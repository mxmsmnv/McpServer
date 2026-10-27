<?php

declare(strict_types=1);

namespace ProcessWire;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Transport/McpEndpointTrait.php';
require dirname(__DIR__) . '/src/Security/McpClientTrait.php';
require dirname(__DIR__) . '/src/Audit/McpAuditTrait.php';
require dirname(__DIR__) . '/McpServerReferenceHandler.php';

final class DispatchHarness {
    use McpEndpointTrait;
    use McpClientTrait;
    public ?array $activeClient = ['id' => 'read-fixture', 'scopes' => ['read']];
    public array $registry = [];
    public array $audits = [];
    private function toolRegistry(): array { return $this->registry; }
    private function audit(string $tool, string $scope, string $status, int $duration, array $request): void {
        $this->audits[] = compact('tool', 'scope', 'status', 'request');
    }
    public function handler(): McpServerReferenceHandler {
        return new McpServerReferenceHandler(fn($reference, $arguments, $handler) => $this->dispatchMcpTool($reference, $arguments, $handler));
    }
    public function rejectBatch(string $raw, array $data): bool { return $this->rejectBatchRequest($raw, $data); }
}

$passed = 0;
$check = static function(bool $ok, string $label) use (&$passed): void {
    if(!$ok) throw new \RuntimeException('[FAIL] ' . $label);
    echo '[PASS] ', $label, PHP_EOL;
    $passed++;
};
$h = new DispatchHarness();
$session = new \Mcp\Server\Session\Session(new \Mcp\Server\Session\InMemorySessionStore());
$schema = ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
$executed = 0;
$callback = static function() use (&$executed): array { $executed++; return ['executed' => true]; };
$ref = new \Mcp\Capability\Registry\ToolReference(new \Mcp\Schema\Tool('probe_publish', null, $schema, null, null), $callback);
$h->registry = ['probe_publish' => ['scope' => 'publish']];
$request = (new \Mcp\Schema\Request\CallToolRequest('probe_publish', []))->withId('denied-fixture');
$args = ['_session' => $session, '_request' => $request];
$denied = false;
try { $h->handler()->handle($ref, $args); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
$check($denied && $executed === 0, 'read client cannot dispatch a publish tool even without envelope preflight');
$check(count($h->audits) === 1 && $h->audits[0]['status'] === 'denied' && $h->audits[0]['request']['id'] === 'denied-fixture', 'dispatch denial is audited with its individual request ID');

foreach(['draft', 'publish', 'admin'] as $scope) {
    $h->registry['probe_publish']['scope'] = $scope;
    $denied = false;
    try { $h->handler()->handle($ref, $args); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
    $check($denied && $executed === 0, 'read client cannot dispatch ' . $scope . ' tools');
}
$h->activeClient = null;
$h->registry['probe_publish']['scope'] = 'read';
$denied = false;
try { $h->handler()->handle($ref, $args); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
$check($denied && $executed === 0, 'dispatch without an authenticated client fails closed');
$h->activeClient = ['scopes' => ['admin']];
$denied = false;
try { $h->handler()->handle($ref, $args); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
$check($denied && $executed === 0, 'a scope list without an authenticated client ID fails closed');
$h->activeClient = ['id' => 'admin-fixture', 'scopes' => ['admin']];
$h->registry = [];
$denied = false;
try { $h->handler()->handle($ref, $args); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
$check($denied && $executed === 0, 'unknown tool references fail closed even for admin');
$h->registry = ['probe_publish' => ['scope' => 'publish']];
$result = $h->handler()->handle($ref, $args);
$check($result === ['executed' => true] && $executed === 1 && end($h->audits)['status'] === 'ok', 'authorized dispatch executes once and audits success');
$mismatched = ['_session' => $session, '_request' => (new \Mcp\Schema\Request\CallToolRequest('different_tool', []))->withId('wrong-context')];
$denied = false;
try { $h->handler()->handle($ref, $mismatched); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
$check($denied && $executed === 1, 'a mismatched request context cannot dispatch the referenced tool');

$errorRef = new \Mcp\Capability\Registry\ToolReference($ref->tool, static fn() => new \Mcp\Schema\Result\CallToolResult([], isError: true));
$h->handler()->handle($errorRef, $args);
$check(end($h->audits)['status'] === 'error', 'provider error results are audited as errors');
$errorRef = new \Mcp\Capability\Registry\ToolReference($ref->tool, static function() { throw new \RuntimeException('fixture failure'); });
try { $h->handler()->handle($errorRef, $args); } catch(\RuntimeException) {}
$check(end($h->audits)['status'] === 'error', 'provider exceptions are audited as errors');

$h->audits = [];
$batch = [['jsonrpc' => '2.0', 'id' => 'batch-publish', 'method' => 'tools/call', 'params' => ['name' => 'probe_publish', 'arguments' => []]]];
$check($h->rejectBatch(" \n" . json_encode($batch), $batch), 'legacy-shaped JSON arrays are rejected before SDK dispatch');
$check(count($h->audits) === 1 && $h->audits[0]['status'] === 'denied' && $h->audits[0]['request']['id'] === 'batch-publish', 'rejected batch calls are audited individually');
$check($h->rejectBatch('[]', []), 'empty JSON arrays are rejected');
$check(!$h->rejectBatch('{}', []), 'empty JSON objects are not mistaken for arrays');
$check(!$h->rejectBatch(json_encode($batch[0]), $batch[0]), 'single messages retain compatibility');
$h->audits = [];
$h->rejectBatch(json_encode(array_fill(0, 101, $batch[0])), array_fill(0, 101, $batch[0]));
$check(count($h->audits) === 100, 'batch audit work is bounded to one hundred entries');
$h->audits = [];
$malformed = [['method' => 'tools/call', 'params' => ['name' => []]], ['method' => 'tools/call', 'params' => 'invalid'], ['method' => 'tools/list'], null, []];
$check($h->rejectBatch(json_encode($malformed), $malformed) && $h->audits === [], 'malformed and non-tool batch elements are rejected without unsafe coercion');

// Exercise the reported old-protocol SDK path without the HTTP envelope gate.
// Even if batching reaches the SDK, the final dispatch boundary must hold.
$h->activeClient = ['id' => 'legacy-read-fixture', 'scopes' => ['read']];
$h->registry = ['probe_publish' => ['scope' => 'publish'], 'probe_read' => ['scope' => 'read']];
$server = \Mcp\Server::builder()->setServerInfo('scope-regression', '1')
    ->setSession(new \Mcp\Server\Session\InMemorySessionStore())
    ->setReferenceHandler($h->handler())
    ->addTool($callback, 'probe_publish', inputSchema: $schema)
    ->addTool($callback, 'probe_read', inputSchema: $schema)
    ->build();
$factory = new \Nyholm\Psr7\Factory\Psr17Factory();
$sid = '';
$send = static function(array $body) use ($server, $factory, &$sid): object {
    $request = $factory->createServerRequest('POST', 'http://127.0.0.1/mcp/')
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Accept', 'application/json, text/event-stream')
        ->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    if($sid !== '') $request = $request->withHeader('Mcp-Session-Id', $sid)->withHeader('MCP-Protocol-Version', '2025-03-26');
    return $server->run(new \Mcp\Server\Transport\StreamableHttpTransport($request, $factory, $factory));
};
$response = $send(['jsonrpc' => '2.0', 'id' => 'initialize', 'method' => 'initialize', 'params' => [
    'protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'scope-regression', 'version' => '1'],
]]);
$sid = $response->getHeaderLine('Mcp-Session-Id');
$check($response->getStatusCode() === 200 && $sid !== '', 'bundled SDK negotiates a legacy session for the regression');
$send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$before = $executed;
$h->audits = [];
$response = $send([
    ['jsonrpc' => '2.0', 'id' => 'legacy-denied', 'method' => 'tools/call', 'params' => ['name' => 'probe_publish', 'arguments' => new \stdClass()]],
    ['jsonrpc' => '2.0', 'id' => 'legacy-read', 'method' => 'tools/call', 'params' => ['name' => 'probe_read', 'arguments' => new \stdClass()]],
]);
$check($response->getStatusCode() === 200 && $executed === $before + 1, 'SDK legacy batch cannot execute publish for a read client; permitted read still works');
$check(count($h->audits) === 2 && $h->audits[0]['status'] === 'denied' && $h->audits[1]['status'] === 'ok'
    && $h->audits[0]['request']['id'] === 'legacy-denied' && $h->audits[1]['request']['id'] === 'legacy-read',
    'SDK batch dispatch audits each permitted or denied call exactly once');
$ranks = ['read' => 1, 'draft' => 2, 'publish' => 3, 'admin' => 4];
foreach($ranks as $clientScope => $clientRank) {
    foreach($ranks as $toolScope => $toolRank) {
        $h->activeClient = ['id' => 'matrix-fixture', 'scopes' => [$clientScope]];
        $h->registry['probe_publish']['scope'] = $toolScope;
        $h->audits = [];
        $before = $executed;
        $denied = false;
        try { $h->handler()->handle($ref, $args); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
        $allowed = $clientRank >= $toolRank;
        $check($denied !== $allowed && $executed === $before + (int) $allowed && count($h->audits) === 1
            && $h->audits[0]['status'] === ($allowed ? 'ok' : 'denied'), $clientScope . ' client / ' . $toolScope . ' tool enforces scope and audit');
    }
}

final class AuditHarness {
    use McpAuditTrait;
    private ?array $activeClient = ['id' => 'audit-fixture'];
    public array $rows = [];
    public array $errors = [];
    public function wire(string $name): object {
        return new class($this) {
            public function __construct(private AuditHarness $owner) {}
            public function prepare(string $sql): object { return $this; }
            public function execute(array $params): void { $this->owner->rows[] = $params; }
            public function save(string $channel, string $message): void { $this->owner->errors[] = $message; }
        };
    }
    public function record(array $request): void { $this->audit('probe_publish', 'publish', 'denied', 0, $request); }
}
$audit = new AuditHarness();
set_error_handler(static function(int $severity, string $message): never { throw new \RuntimeException($message); });
try {
    $audit->record(['id' => [], 'params' => ['arguments' => ['fixture' => 'private-fixture-content']]]);
} finally {
    restore_error_handler();
}
$check(count($audit->rows) === 1 && $audit->errors === [] && $audit->rows[0][':request_id'] !== '', 'malformed batch request IDs are safely audited without coercion warnings');
$check(!str_contains(json_encode($audit->rows), 'private-fixture-content')
    && $audit->rows[0][':arguments_digest'] === hash('sha256', '{"fixture":"private-fixture-content"}'), 'persisted denial evidence contains an argument digest, never raw input');
$audit->record(['id' => 0]);
$check($audit->rows[1][':request_id'] === '0', 'numeric JSON-RPC request IDs are retained in audit evidence');
echo $passed, " security regression checks passed.\n";
