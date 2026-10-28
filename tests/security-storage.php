<?php

declare(strict_types=1);
namespace ProcessWire;

// Real MySQL/MariaDB regressions. Only connection-local temporary tables are
// changed. Supply MCP_TEST_DSN/USER/PASSWORD, or an explicitly selected local PW.
$options = getopt('', ['root:']);
require dirname(__DIR__) . '/vendor/autoload.php';
foreach(['Security/McpClientTrait', 'Audit/McpAuditTrait', 'Transport/McpEndpointTrait', 'Provider/McpProviderRegistryTrait'] as $trait) {
    require_once dirname(__DIR__) . '/src/' . $trait . '.php';
}
if(isset($options['root'])) {
    $root = rtrim((string) $options['root'], '/');
    require $root . '/wire/core/ProcessWire.php';
    $wire = new ProcessWire(ProcessWire::buildConfig($root));
    $pdo = $wire->database->pdo();
} else {
    if(!getenv('MCP_TEST_DSN')) { fwrite(STDERR, "Set MCP_TEST_DSN or pass --root=/path/to/disposable-site\n"); exit(2); }
    $pdo = new \PDO(getenv('MCP_TEST_DSN'), getenv('MCP_TEST_USER') ?: '', getenv('MCP_TEST_PASSWORD') ?: '');
}
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$verifyHook = null;
$verifications = 0;
function password_verify(string $password, string $hash): bool {
    global $verifyHook, $verifications;
    $verifications++;
    if($verifyHook) { $hook = $verifyHook; $verifyHook = null; $hook(); }
    return \password_verify($password, $hash);
}
final class StorageDatabase {
    public ?\Closure $beforePrepare = null;
    public bool $failReads = false;
    public bool $failAuditInsert = false;
    public bool $failAuditFinish = false;
    public bool $failLimits = false;
    public function __construct(public \PDO $pdo) {}
    public function query(string $sql): \PDOStatement {
        if($this->failReads) throw new \RuntimeException('fixture read failure');
        return $this->pdo->query($sql);
    }
    public function exec(string $sql): int|false {
        return $this->pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql));
    }
    public function prepare(string $sql): \PDOStatement {
        if($this->beforePrepare) { $hook = $this->beforePrepare; $this->beforePrepare = null; $hook($sql); }
        if(($this->failAuditInsert && str_starts_with($sql, 'INSERT INTO mcp_server_audit'))
            || ($this->failAuditFinish && str_starts_with($sql, 'UPDATE mcp_server_audit'))
            || ($this->failLimits && str_contains($sql, 'mcp_server_limits'))) throw new \RuntimeException('fixture storage failure');
        return $this->pdo->prepare($sql);
    }
    public function lastInsertId(): string|false { return $this->pdo->lastInsertId(); }
}
final class StorageHarness {
    use McpClientTrait;
    use McpAuditTrait;
    use McpEndpointTrait;
    use McpProviderRegistryTrait;
    public const VERSION_STRING = 'test';
    public array $clients = [];
    public ?array $activeClient = null;
    public array $errors = [];
    public int $rate_limit_per_minute = 1;
    public bool $enabled = true;
    public array $registry = [];
    public function __construct(public StorageDatabase $database) {}
    public function namespacePrefix(): string { return 'fixture'; }
    public function serverName(): string { return 'fixture'; }
    public function environmentName(): string { return 'development'; }
    public function environmentMode(): string { return 'development'; }
    public function publicSiteUrl(): string { return 'http://127.0.0.1/'; }
    private function providerModules(): array { return []; }
    private function toolRegistry(): array { return $this->registry; }
    private function base64Url(string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }
    public function wire(string $name): object {
        return match($name) {
            'database' => $this->database,
            'log' => new class($this) {
                public function __construct(private StorageHarness $h) {}
                public function save(string $c, string $m): void { $this->h->errors[] = $m; }
            },
            'sanitizer' => new class { public function text(string $v): string { return strip_tags($v); } },
            'config' => new class {
                public string $version = 'fixture-version';
                public function get(string $k): string { return 'test-only-installation-pepper-value'; }
            },
            default => throw new \RuntimeException('Unexpected fixture service ' . $name),
        };
    }
    public function schema(): void { $this->installClientTable(); $this->installAuditTable(); $this->installLimitTable(); }
    public function login(string $t): ?array { return $this->authenticate('Bearer ' . $t); }
    public function admittedLogin(string $t, string $ip): ?array { return $this->consumeAuthenticationLimit($ip) ? $this->login($t) : null; }
    public function limit(string $id): bool { return $this->consumeRateLimit($id); }
    public function authLimit(string $ip): bool { return $this->consumeAuthenticationLimit($ip); }
    public function dispatch($ref, array $args): mixed { return $this->dispatchMcpTool($ref, $args, new \Mcp\Capability\Registry\ReferenceHandler()); }
}
$passed = 0;
$check = static function(bool $ok, string $label) use (&$passed): void {
    if(!$ok) throw new \RuntimeException('[FAIL] ' . $label);
    echo '[PASS] ', $label, PHP_EOL; $passed++;
};
$db = new StorageDatabase($pdo);
$h = new StorageHarness($db);
$operator = new StorageHarness($db);
$originalMode = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
$tables = ['mcp_server_clients', 'mcp_server_audit', 'mcp_server_limits'];
try {
    $h->schema(); $h->schema();
    $check(true, 'fresh schema and repeated upgrade succeed on temporary tables');
    $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($originalMode . ',STRICT_TRANS_TABLES'));
    $a = $operator->issueClient('test A', ['read']);
    $b = $operator->issueClient('test B', ['admin']);
    $c = $operator->issueClient('test C', ['read']);
    $row = static function(string $id) use ($pdo): array {
        $s = $pdo->prepare('SELECT * FROM mcp_server_clients WHERE id=?'); $s->execute([$id]); return $s->fetch(\PDO::FETCH_ASSOC);
    };
    $verifyHook = static function() use ($operator, $b): void { $operator->revokeClient($b['id']); };
    $h->login($a['token']);
    $check(!(bool) $row($b['id'])['enabled'] && $row($b['id'])['revoked_at'] !== null, 'login cannot undo another client revocation');
    $verifyHook = static function() use ($operator, $a): void { $operator->revokeClient($a['id']); };
    $check($h->login($a['token']) === null && !(bool) $row($a['id'])['enabled'], 'revocation during bcrypt rejects current login and remains durable');
    $check($operator->revokeClient($a['id']) && !$operator->revokeClient('unknown-fixture'), 'revocation is idempotent for existing clients and rejects unknown IDs');
    $a = $operator->issueClient('test A2', ['admin']);
    $verifyHook = static function() use ($pdo, $a): void {
        $s = $pdo->prepare('UPDATE mcp_server_clients SET scopes_json=? WHERE id=?'); $s->execute(['["read"]', $a['id']]);
    };
    $check($h->login($a['token'])['scopes'] === ['read'], 'login returns concurrently reduced scopes');
    $verifyHook = static function() use ($pdo, $a): void {
        $s = $pdo->prepare('UPDATE mcp_server_clients SET expires_at=? WHERE id=?'); $s->execute([time()-1, $a['id']]);
    };
    $check($h->login($a['token']) === null && (int) $row($a['id'])['expires_at'] < time(), 'concurrently expired credential is rejected without restoring expiry');
    $db->beforePrepare = static function() use ($operator, $c): void { $operator->revokeClient($c['id']); };
    $operator->revokeClient($a['id']);
    $check(!(bool) $row($c['id'])['enabled'], 'revoking a client cannot undo another revocation');
    $d = $operator->issueClient('test D', ['read']);
    $db->beforePrepare = static function() use ($operator, $d): void { $operator->revokeClient($d['id']); };
    $operator->issueClient('test E', ['read']);
    $check(!(bool) $row($d['id'])['enabled'], 'issuing a client cannot undo a concurrent revocation');
    $h->clients = [$d['id'] => $d + ['enabled' => true, 'token_hash' => 'stale-config']];
    $db->failReads = true;
    $verifications = 0;
    $check($h->login($d['token']) === null && $verifications === 0, 'registry failure never falls back to stale config credentials');
    $db->failReads = false; $h->clients = [];

    $legacy = $operator->issueClient('legacy fixture', ['read']);
    $legacyHash = \password_hash($legacy['token'], PASSWORD_BCRYPT, ['cost'=>4]);
    $q = $pdo->prepare('UPDATE mcp_server_clients SET token_hash=?,token_hash_version=1 WHERE id=?');
    $q->execute([$legacyHash, $legacy['id']]);
    $migrated = $h->login($legacy['token']);
    $check($migrated['token_hash_version'] === 2 && $h->login($legacy['token']) !== null, 'real SQL migrates legacy hash without invalidating token');
    $q->execute([$legacyHash, $legacy['id']]);
    $verifyHook = static function() use ($operator, $legacy): void { $operator->revokeClient($legacy['id']); };
    $check($h->login($legacy['token']) === null && $row($legacy['id'])['token_hash'] === $legacyHash, 'legacy migration never overwrites concurrent revocation');
    $rotated = $operator->issueClient('rotated fixture', ['read']);
    $verifyHook = static function() use ($pdo, $rotated): void {
        $s = $pdo->prepare('UPDATE mcp_server_clients SET token_hash=? WHERE id=?'); $s->execute(['changed-hash', $rotated['id']]);
    };
    $check($h->login($rotated['token']) === null, 'changed credential hash rejects a stale verification result');

    $nested = false;
    $db->beforePrepare = function(string $sql) use ($db, $operator, &$nested): void {
        // Interleave after the outer INSERT, immediately before its UPDATE.
        $db->beforePrepare = function(string $sql) use ($db, $operator, &$nested): void {
            $db->beforePrepare = static function(string $sql) use ($operator, &$nested): void { $nested = $operator->limit('parallel'); };
        };
    };
    $outer = $h->limit('parallel');
    $check($nested && !$outer && !$h->limit('parallel'), 'conditional SQL admits exactly one interleaved request for limit one');
    $check($h->limit('independent'), 'client rate buckets are isolated');
    $db->failLimits = true;
    $check(!$h->limit('storage-failure'), 'limiter storage failure denies admission');
    $db->failLimits = false;
    $valid = $operator->issueClient('auth fixture', ['admin']);
    $wrong = $valid['prefix'] . str_repeat('x', 43);
    $verifications = 0;
    $h->admittedLogin($wrong, '192.0.2.1');
    $check($verifications === 1, 'admitted invalid credential performs full verification');
    $ipKey = hash('sha256', 'auth:ip:192.0.2.1:' . intdiv(time(), 60));
    $seed = $pdo->prepare('INSERT INTO mcp_server_limits (bucket_key,hits,expires_at) VALUES (?,120,?) ON DUPLICATE KEY UPDATE hits=120');
    $seed->execute([$ipKey, time()+120]);
    $h->admittedLogin($wrong, '192.0.2.1');
    $check($verifications === 1, 'exhausted IP budget rejects invalid credentials before bcrypt');
    $check($h->authLimit('192.0.2.2'), 'another direct peer has an independent IP budget');
    $key = hash('sha256', 'auth:global:' . intdiv(time(), 60));
    $q = $pdo->prepare('UPDATE mcp_server_limits SET hits=600 WHERE bucket_key=?'); $q->execute([$key]);
    $check(!$h->authLimit('192.0.2.3'), 'global pre-auth budget bounds distributed attempts and IP bucket creation');

    $h->activeClient = $h->login($valid['token']);
    $executions = 0;
    $schema = ['type'=>'object','properties'=>new \stdClass(),'additionalProperties'=>false];
    $ref = new \Mcp\Capability\Registry\ToolReference(new \Mcp\Schema\Tool('fixture_write', null, $schema, null, null), static function() use (&$executions): array { $executions++; return ['executed'=>true]; });
    $h->registry = ['fixture_write'=>['name'=>'fixture_write','scope'=>'publish','provider'=>StorageHarness::class,'handler'=>static fn()=>null]];
    $session = new \Mcp\Server\Session\Session(new \Mcp\Server\Session\InMemorySessionStore());
    $invoke = static function(string $id) use ($h, $ref, $session): mixed {
        return $h->dispatch($ref, ['_session'=>$session,'_request'=>(new \Mcp\Schema\Request\CallToolRequest('fixture_write', []))->withId($id)]);
    };
    $invoke('ascii'); $invoke(str_repeat('é',96));
    $audit = $pdo->query('SELECT * FROM mcp_server_audit ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    $check($executions === 2 && count($audit) === 2 && $audit[1]['status'] === 'ok'
        && $audit[1]['request_id'] === 'sha256:' . hash('sha256', str_repeat('é',96)), 'multibyte ID produces durable completed audit in strict SQL mode');
    $db->failAuditInsert = true; $denied = false;
    try { $invoke('unavailable'); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
    $check($denied && $executions === 2, 'audit INSERT failure prevents provider execution');
    $db->failAuditInsert = false; $db->failAuditFinish = true;
    $invoke('completion-failure');
    $check($executions === 3 && $pdo->query("SELECT status FROM mcp_server_audit WHERE request_id='completion-failure'")->fetchColumn() === 'started', 'completion failure retains a durable started row');
    $db->failAuditFinish = false;
    $q = $pdo->prepare('UPDATE mcp_server_clients SET scopes_json=? WHERE id=?'); $q->execute(['["read"]', $valid['id']]);
    $denied = false;
    try { $invoke('downgraded'); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
    $check($denied && $executions === 3 && $h->activeClient['scopes'] === ['read'], 'final dispatch rechecks current stored scope and updates provider context');
    $check($h->tools() === [] && $h->toolCapabilities()['tools'] === [], 'tools and capabilities hide tools above current client scope');
    $check(!isset($h->toolSystemStatus()['php']) && !isset($h->toolSystemStatus()['processwire']) && $h->providers() === [], 'non-admin status and provider discovery do not disclose runtime versions or hidden providers');
    foreach(['read'=>false, 'draft'=>false, 'publish'=>true, 'admin'=>true] as $scope=>$visible) {
        $h->activeClient = ['id'=>'discovery', 'scopes'=>[$scope]];
        $check((count($h->tools()) === 1) === $visible && (count($h->toolCapabilities()['tools']) === 1) === $visible,
            $scope . ' discovery honors hierarchical tool scopes');
    }
    $check(isset($h->toolSystemStatus()['php']) && isset($h->providers()[0]['version']), 'admin discovery retains detailed versions');
    $h->activeClient = null;
    $check(count($h->tools()) === 1 && isset($h->toolSystemStatus()['php']), 'local operator APIs retain complete inventory and runtime status');
    $h->activeClient = $h->login($valid['token']);
    $operator->revokeClient($valid['id']); $denied = false;
    try { $invoke('revoked-before-dispatch'); } catch(\Mcp\Exception\ToolCallException) { $denied = true; }
    $check($denied && $executions === 3, 'revocation after login prevents final dispatch');
    echo $passed, " storage security checks passed.\n";
} finally {
    $pdo->exec('SET SESSION sql_mode=' . $pdo->quote($originalMode));
    foreach($tables as $table) $pdo->exec('DROP TEMPORARY TABLE IF EXISTS ' . $table);
    echo "Temporary fixtures removed; SQL mode restored.\n";
}
