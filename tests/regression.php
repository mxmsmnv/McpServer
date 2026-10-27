<?php

declare(strict_types=1);

namespace ProcessWire;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Security/McpClientTrait.php';
require dirname(__DIR__) . '/src/Admin/ProcessMcpProvidersTrait.php';
require dirname(__DIR__) . '/src/Admin/ProcessMcpPresentationTrait.php';
require dirname(__DIR__) . '/McpServerReferenceHandler.php';

// Count actual expensive verifications, rather than asserting wall-clock times.
function password_verify(string $password, string $hash): bool {
    $GLOBALS['credential_verifications']++;
    return \password_verify($password, $hash);
}

final class CredentialHarness {
    use McpClientTrait;
    public array $records = [];
    public int $saves = 0;
    public string $namespace = 'changed_namespace';
    public string $pepper = 'regression-only-installation-pepper';
    private ?array $activeClient = null;
    public function namespacePrefix(): string { return $this->namespace; }
    private function clientRecords(): array { return $this->records; }
    private function saveClientRecords(array $clients): void { $this->records = $clients; $this->saves++; }
    public function wire(string $name): object {
        if($name === 'config') return new class($this->pepper) {
            public function __construct(private string $pepper) {}
            public function get(string $name): string { return $this->pepper; }
        };
        throw new \RuntimeException('Unexpected service: ' . $name);
    }
    public function login(string $token): ?array {
        $GLOBALS['credential_verifications'] = 0;
        return $this->authenticate('Bearer ' . $token);
    }
    public function scope(array $client, string $scope): bool { return $this->clientHasScope($client, $scope); }
}

$passed = 0;
$check = static function(bool $ok, string $label) use (&$passed): void {
    if(!$ok) throw new \RuntimeException('[FAIL] ' . $label);
    echo '[PASS] ', $label, PHP_EOL;
    $passed++;
};
$raw = static fn(string $prefix): string => $prefix . bin2hex(random_bytes(32));
$client = static function(string $id, string $token, string $prefix, int $version = 2): array {
    $material = $version === 1 ? $token : hash_hmac('sha256', $token, 'regression-only-installation-pepper');
    return ['id' => $id, 'enabled' => true, 'expires_at' => null, 'prefix' => $prefix,
        'token_hash' => \password_hash($material, PASSWORD_BCRYPT, ['cost' => 4]),
        'token_hash_version' => $version, 'scopes' => ['read']];
};
$h = new CredentialHarness();
for($i = 0; $i < 20; $i++) {
    $prefix = 'old_mcp_' . sprintf('%08d', $i);
    $token = $raw($prefix);
    $h->records[(string) $i] = $client((string) $i, $token, $prefix);
}
$check($h->login($token)['id'] === '19' && $GLOBALS['credential_verifications'] === 1,
    'twenty clients require one hash check for the matching stored prefix');
$check($h->records['19']['last_used_at'] > 0 && $h->saves === 1, 'successful authentication records use');
$check($h->login($raw('unknown_mcp_')) === null && $GLOBALS['credential_verifications'] === 0,
    'unknown prefixes require no password verification');
$check($h->login($raw($prefix)) === null && $GLOBALS['credential_verifications'] === 1,
    'a matching prefix alone never authenticates');
$check($h->login('short') === null && $GLOBALS['credential_verifications'] === 0, 'malformed tokens fail before hashing');
$check($h->login(str_repeat('x', 257)) === null && $GLOBALS['credential_verifications'] === 0, 'oversized tokens fail before hashing');
$check($h->login($token)['id'] === '19', 'namespace changes preserve previously issued credentials');
$h->records['19']['enabled'] = false;
$check($h->login($token) === null && $GLOBALS['credential_verifications'] === 0, 'revoked tokens stay rejected');
$h->records['19']['enabled'] = true;
$h->records['19']['expires_at'] = time() - 1;
$check($h->login($token) === null && $GLOBALS['credential_verifications'] === 0, 'expired tokens stay rejected');
$collision = 'collision_mcp_';
$good = $raw($collision);
$h->records = ['wrong' => $client('wrong', $raw($collision), $collision), 'right' => $client('right', $good, $collision)];
$check($h->login($good)['id'] === 'right' && $GLOBALS['credential_verifications'] === 2,
    'prefix collisions continue to full verification of later candidates');
foreach(['', null] as $legacyPrefix) {
    $h->records = ['legacy' => $client('legacy', $good, '')];
    if($legacyPrefix === null) unset($h->records['legacy']['prefix']);
    $check($h->login($good)['id'] === 'legacy' && $GLOBALS['credential_verifications'] === 1,
        'legacy ' . ($legacyPrefix === null ? 'missing' : 'empty') . ' prefixes fall back to full verification');
}
$h->records = ['legacy' => $client('legacy', $good, $collision, 1)];
$check($h->login($good)['token_hash_version'] === 2, 'legacy raw-token hashes migrate on successful authentication');
$check($h->login($good)['id'] === 'legacy', 'migrated hashes remain usable');
$h->pepper = 'different-regression-installation-pepper';
$check($h->login($good) === null, 'credentials remain bound to the installation pepper');
$check(!$h->scope(['scopes' => ['read']], 'draft') && $h->scope(['scopes' => ['publish']], 'read'), 'scope hierarchy remains enforced');
$check($h->requestClientContext() === null, 'no client identity is exposed outside an active request');

// Exercise the real SDK dispatch and closed schema through the gateway wrapper.
$registry = new \Mcp\Capability\Registry();
$calls = 0;
$seen = null;
$schema = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]], 'additionalProperties' => false];
$registry->registerTool(new \Mcp\Schema\Tool(name: 'context_probe', title: null, inputSchema: $schema, description: null, annotations: null),
    static function(\Mcp\Server\RequestContext $context) use (&$calls, &$seen): array {
        $calls++;
        $seen = $context->getRequest()->arguments;
        return ['limit' => $seen['limit'] ?? 5];
    });
$handler = new \Mcp\Server\Handler\Request\CallToolHandler($registry, new McpServerReferenceHandler());
$session = new \Mcp\Server\Session\Session(new \Mcp\Server\Session\InMemorySessionStore());
$dispatch = static function(array $args) use ($handler, $session): object {
    $request = new \Mcp\Schema\Request\CallToolRequest('context_probe', $args);
    $request = $request->withId('regression');
    return $handler->handle($request, $session);
};
$result = $dispatch(['limit' => 7]);
$check($result instanceof \Mcp\Schema\JsonRpc\Response && $seen === ['limit' => 7] && $calls === 1,
    'RequestContext receives the original tool argument array through SDK dispatch');
$dispatch([]);
$check($seen === [] && $calls === 2, 'context handlers support omitted optional arguments');
foreach([['limit' => 11], ['limit' => '7'], ['unknown' => true]] as $invalid) {
    $result = $dispatch($invalid);
    $check($result instanceof \Mcp\Schema\JsonRpc\Error && $calls === 2, 'invalid context arguments never reach the handler: ' . json_encode($invalid));
}
$registry->registerTool(new \Mcp\Schema\Tool(name: 'named_probe', title: null, inputSchema: $schema, description: null, annotations: null), static fn(int $limit = 5): array => ['limit' => $limit]);
$request = new \Mcp\Schema\Request\CallToolRequest('named_probe', ['limit' => 4]);
$request = $request->withId('named');
$check($handler->handle($request, $session) instanceof \Mcp\Schema\JsonRpc\Response, 'named-parameter handlers remain supported');

// Render the actual Tools handler and shared navigation with an isolated inventory.
final class McpServer {
    public array $inventory = [];
    public function tools(): array { return $this->inventory; }
}
final class ToolsHarness {
    use ProcessMcpProvidersTrait;
    use ProcessMcpPresentationTrait;
    public string $title = '';
    public function __construct(public McpServer $gateway) {}
    public function headline(string $title): void { $this->title = $title; }
    public function _(string $text): string { return $text; }
    public function wire(string $name): object {
        return match($name) {
            'config' => (object) ['urls' => (object) ['admin' => '/processwire/']],
            'modules' => new class($this->gateway) {
                public function __construct(private McpServer $gateway) {}
                public function get(string $name): McpServer { return $this->gateway; }
            },
            'sanitizer' => new class {
                public function entities(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
            },
            default => throw new \RuntimeException('Unexpected service: ' . $name),
        };
    }
}
$gateway = new McpServer();
$admin = new ToolsHarness($gateway);
$html = $admin->___executeTools();
$check($admin->title === 'MCP Tools' && str_contains($html, '0 tools'), 'Tools route renders an empty registry');
$check(str_contains($html, 'class="uk-active"><a href="/processwire/setup/mcp-server/tools/"'), 'Tools tab links to its canonical route and is selected');
$gateway->inventory = [['name' => 'probe_status', 'title' => '<script>probe</script>', 'provider' => 'Probe', 'scope' => 'read', 'description' => '<b>description</b>']];
$html = $admin->___executeTools();
$check(str_contains($html, 'probe_status') && str_contains($html, 'Probe') && str_contains($html, 'mcp-scope--read'), 'Tools renders names, providers and scopes');
$check(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>') && str_contains($html, '&lt;b&gt;'), 'Tools escapes provider-supplied content');
echo $passed, " behavioral regression checks passed.\n";
