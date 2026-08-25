<?php namespace ProcessWire;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

/**
 * Authenticated Streamable HTTP endpoint and request parsing.
 */
trait McpEndpointTrait {

    public function handleMcpEndpoint(HookEvent $event): void {
        if(!$this->isEndpointRequest()) return;

        $event->replace = true;
        $event->return = true;
        $this->emitEndpointResponse();
        exit;
    }

    public function isEndpointRequest(): bool {
        $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return rtrim($requestPath, '/') . '/' === $this->normaliseEndpointPath((string) $this->endpoint_path);
    }


    private function emitEndpointResponse(): void {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow', true);

        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/McpServerReferenceHandler.php';

        if(!(bool) $this->enabled) {
            $this->emitJson(503, ['error' => 'mcp_disabled', 'message' => 'The MCP endpoint is not enabled.']);
            return;
        }

        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $request = $creator->fromGlobals();
        // Some FastCGI profiles expose the same Host value through two server
        // variables. PSR-7 then combines them into a comma-separated header,
        // which the SDK's DNS-rebinding middleware correctly rejects. A Host
        // header is singular by definition, so retain its first canonical value.
        $requestHost = trim(explode(',', $request->getHeaderLine('Host'), 2)[0]);
        if($requestHost !== '') $request = $request->withHeader('Host', $requestHost);

        [$request, $rawBody, $bodyTooLarge] = $this->boundedRequestBody($request, $factory);
        if($bodyTooLarge) {
            $this->emitJson(413, ['error' => 'request_too_large', 'message' => 'The MCP request body exceeds the 2 MiB limit.']);
            return;
        }

        if(strtoupper($request->getMethod()) !== 'OPTIONS') {
            $client = $this->authenticate($request->getHeaderLine('Authorization'));
            if(!$client) {
                header('WWW-Authenticate: Bearer realm="' . $this->serverName() . '"');
                $this->emitJson(401, ['error' => 'invalid_token', 'message' => 'A valid MCP bearer token is required.']);
                return;
            }
            $this->activeClient = $client;

            if(!$this->consumeRateLimit((string) $client['id'])) {
                header('Retry-After: 60');
                $this->emitJson(429, ['error' => 'rate_limited', 'message' => 'Client request limit exceeded.']);
                return;
            }
        }

        $requestData = $this->readRequestData($rawBody);
        $toolName = $this->requestToolName($requestData);
        $tool = $toolName !== '' ? ($this->toolRegistry()[$toolName] ?? null) : null;

        if($toolName !== '' && !$tool) {
            $this->audit($toolName, 'unknown', 'denied', 0, $requestData);
            $this->emitJson(404, ['error' => 'unknown_tool', 'message' => 'The requested tool is not registered.']);
            return;
        }

        if($tool && !$this->clientHasScope($this->activeClient ?? [], (string) $tool['scope'])) {
            $this->audit($toolName, (string) $tool['scope'], 'denied', 0, $requestData);
            $this->emitJson(403, ['error' => 'insufficient_scope', 'message' => 'This client is not allowed to call the requested tool.']);
            return;
        }

        $builder = Server::builder()
            ->setServerInfo($this->serverName(), self::VERSION_STRING, 'Governed ProcessWire operations exposed by installed module providers.')
            ->setInstructions($this->serverInstructions())
            ->setSession(new FileSessionStore($this->sessionPath(), 3600))
            ->setReferenceHandler(new McpServerReferenceHandler());

        foreach($this->toolRegistry() as $definition) {
            $annotations = new ToolAnnotations(
                title: (string) $definition['title'],
                readOnlyHint: (bool) $definition['read_only'],
                destructiveHint: (bool) $definition['destructive'],
                idempotentHint: (bool) $definition['idempotent'],
                openWorldHint: (bool) $definition['open_world'],
            );
            $builder->addTool(
                $definition['handler'],
                (string) $definition['name'],
                (string) $definition['title'],
                (string) $definition['description'],
                $annotations,
                $definition['input_schema'],
            );
        }

        $origins = $this->lineList((string) $this->allowed_origins);
        $originHosts = array_values(array_filter(array_map(
            static fn(string $origin): string => (string) parse_url($origin, PHP_URL_HOST),
            $origins
        )));
        $hosts = array_values(array_unique(array_filter(array_merge(
            $this->lineList((string) $this->allowed_hosts),
            $originHosts,
            [(string) ($_SERVER['HTTP_HOST'] ?? ''), parse_url($this->publicSiteUrl(), PHP_URL_HOST)]
        ))));
        $hosts = array_map(fn(string $host): string => strtolower(explode(':', $host, 2)[0]), $hosts);
        $transport = new StreamableHttpTransport(
            $request,
            $factory,
            $factory,
            middleware: [
                new CorsMiddleware(allowedOrigins: $origins, allowCredentials: false),
                new DnsRebindingProtectionMiddleware($hosts, $factory, $factory),
            ],
            maxBodyBytes: self::MAX_BODY_BYTES,
        );

        $started = microtime(true);
        try {
            $response = $builder->build()->run($transport);
            $duration = (int) round((microtime(true) - $started) * 1000);
            if($tool) $this->audit($toolName, (string) $tool['scope'], $this->responseRepresentsError($response) ? 'error' : 'ok', $duration, $requestData);
            (new SapiEmitter())->emit($response);
        } catch(\Throwable $e) {
            $duration = (int) round((microtime(true) - $started) * 1000);
            if($tool) $this->audit($toolName, (string) $tool['scope'], 'error', $duration, $requestData);
            $this->wire('log')->save('mcp-server', 'Endpoint failure: ' . $e->getMessage());
            $this->emitJson(500, ['error' => 'mcp_failure', 'message' => 'The MCP request could not be completed.']);
        }
    }


    private function boundedRequestBody(object $request, Psr17Factory $factory): array {
        $contentLength = trim($request->getHeaderLine('Content-Length'));
        if($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BODY_BYTES) {
            return [$request, '', true];
        }

        $body = $request->getBody();
        if($body->isSeekable()) $body->rewind();
        $raw = '';
        while(!$body->eof() && strlen($raw) <= self::MAX_BODY_BYTES) {
            $raw .= $body->read(min(8192, self::MAX_BODY_BYTES + 1 - strlen($raw)));
        }
        if(strlen($raw) > self::MAX_BODY_BYTES || !$body->eof()) return [$request, '', true];

        return [$request->withBody($factory->createStream($raw)), $raw, false];
    }

    /** @return array<string,mixed> */
    private function readRequestData(string $raw): array {
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch(\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $request */
    private function requestToolName(array $request): string {
        if(($request['method'] ?? '') !== 'tools/call') return '';
        return strtolower(trim((string) ($request['params']['name'] ?? '')));
    }

    /** @param array<string,mixed> $request */
}
