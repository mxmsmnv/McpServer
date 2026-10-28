#!/usr/bin/env php
<?php

declare(strict_types=1);

if(PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') exit(1);

$options = getopt('', ['root:', 'url::', 'execute', 'insecure', 'help']);
if(isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tests/http-integration.php --root=/path/to/processwire [--url=https://example.test/mcp/] [--insecure] --execute\n");
    exit(0);
}
if(!isset($options['execute'])) {
    fwrite(STDERR, "Refusing to create test credentials without --execute. This suite is development-only.\n");
    exit(2);
}
if(!function_exists('curl_init')) {
    fwrite(STDERR, "The curl PHP extension is required.\n");
    exit(2);
}

$root = rtrim((string) ($options['root'] ?? ''), DIRECTORY_SEPARATOR);
if($root === '' || !is_file($root . '/wire/core/ProcessWire.php')) {
    fwrite(STDERR, "A valid ProcessWire --root is required.\n");
    exit(2);
}

chdir($root);
require_once $root . '/wire/core/ProcessWire.php';
$config = \ProcessWire\ProcessWire::buildConfig($root);
$wire = new \ProcessWire\ProcessWire($config);
$mcp = $wire->modules->getModule('McpServer', ['noPermissionCheck' => true]);
if(!$mcp instanceof \ProcessWire\McpServer) throw new RuntimeException('MCP Server is not installed.');
if($mcp->environmentName() !== 'development') throw new RuntimeException('HTTP integration tests are restricted to development installations.');
if(!(bool) $mcp->enabled) throw new RuntimeException('Enable the development MCP endpoint before running HTTP integration tests.');

$endpoint = (string) ($options['url'] ?? $mcp->endpointUrl());
$endpointHost = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
$siteHost = strtolower((string) parse_url($mcp->publicSiteUrl(), PHP_URL_HOST));
if($endpointHost === '' || $endpointHost !== $siteHost) throw new RuntimeException('The test URL must use the configured development site host.');

$runId = 'http-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$insecure = isset($options['insecure']);
if($insecure) fwrite(STDOUT, "[WARN] TLS certificate verification is disabled for this development-only run.\n");
$issued = [];
$failures = [];
$passes = 0;
$legacySession = '';
$temporaryLimitKeys = [];

$record = static function(bool $ok, string $label, string $detail = '') use (&$failures, &$passes): void {
    fwrite(STDOUT, ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL);
    if($ok) $passes++; else $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
};

/** @return array{status:int,headers:array<string,string>,body:string,json:mixed,error:string} */
$request = static function(string $url, ?string $token, string $body, array $headers = [], string $method = 'POST') use($insecure): array {
    $responseHeaders = [];
    $curl = curl_init($url);
    $baseHeaders = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    if($token !== null) $baseHeaders[] = 'Authorization: Bearer ' . $token;
    foreach($headers as $name => $value) $baseHeaders[] = $name . ': ' . $value;
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $baseHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_HEADERFUNCTION => static function($curl, string $line) use (&$responseHeaders): int {
            $position = strpos($line, ':');
            if($position !== false) $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
            return strlen($line);
        },
    ]);
    $responseBody = curl_exec($curl);
    $error = $responseBody === false ? curl_error($curl) : '';
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if(PHP_VERSION_ID < 80500) curl_close($curl);
    if($responseBody === false) $responseBody = '';
    $json = json_decode((string) $responseBody, true);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $responseBody, 'json' => $json, 'error' => $error];
};

$meta = static fn(): array => [
    'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
    'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
    'io.modelcontextprotocol/clientInfo' => ['name' => 'McpServer HTTP integration', 'version' => '1.0.0'],
];
$modern = static fn(string $method, string $id, array $params = []): string => json_encode([
    'jsonrpc' => '2.0',
    'id' => $id,
    'method' => $method,
    'params' => $params + ['_meta' => $meta()],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$modernHeaders = static function(string $method, ?string $name = null, array $extra = []): array {
    $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
    if($name !== null) $headers['Mcp-Name'] = $name;
    return $headers + $extra;
};
$issue = static function(string $scope, ?int $expires = null) use($mcp, $runId, &$issued): array {
    $client = $mcp->issueClient($runId . ' · ' . $scope, [$scope], $expires);
    $issued[] = (string) $client['id'];
    return $client;
};

try {
    $read = $issue('read');
    $draft = $issue('draft');
    $publish = $issue('publish');
    $admin = $issue('admin');

    $unauthenticated = $request($endpoint, null, '{}');
    $record($unauthenticated['status'] === 401, 'missing bearer token is rejected', 'HTTP ' . $unauthenticated['status']);
    $invalid = $request($endpoint, 'invalid_' . str_repeat('x', 40), '{}');
    $record($invalid['status'] === 401, 'invalid bearer token is rejected', 'HTTP ' . $invalid['status']);

    $discoverBody = $modern('server/discover', $runId . '-discover');
    $discover = $request($endpoint, (string) $read['token'], $discoverBody, $modernHeaders('server/discover'));
    $record($discover['status'] === 200 && is_array($discover['json']['result'] ?? null), 'modern 2026-07-28 discovery succeeds', 'HTTP ' . $discover['status']);

    $listBody = $modern('tools/list', $runId . '-list');
    $listed = $request($endpoint, (string) $read['token'], $listBody, $modernHeaders('tools/list'));
    $listedTools = (array) ($listed['json']['result']['tools'] ?? []);
    $record($listed['status'] === 200 && $listedTools !== [], 'modern tools/list succeeds', count($listedTools) . ' tools');

    $tools = $mcp->tools();
    $byScope = [];
    foreach($tools as $tool) $byScope[(string) ($tool['scope'] ?? 'read')] ??= $tool;
    $readTool = $byScope['read'] ?? null;
    if(!$readTool) throw new RuntimeException('No read tool is registered.');
    $readName = (string) $readTool['name'];
    $callBody = $modern('tools/call', $runId . '-read', ['name' => $readName, 'arguments' => new stdClass()]);
    $readCall = $request($endpoint, (string) $read['token'], $callBody, $modernHeaders('tools/call', $readName));
    $record($readCall['status'] === 200 && empty($readCall['json']['error']), 'read-scoped tool call succeeds', $readName);

    $allowedNames = array_column(array_filter($tools, static fn(array $t): bool => $t['scope'] === 'read'), 'name');
    $visibleNames = array_column($listedTools, 'name');
    sort($allowedNames); sort($visibleNames);
    $record($visibleNames === $allowedNames, 'HTTP tools/list contains only read-authorized tools');
    $unicodeId = $runId . str_repeat('é', 96);
    $unicodeBody = $modern('tools/call', $unicodeId, ['name' => $readName, 'arguments' => new stdClass()]);
    $unicodeResponse = $request($endpoint, (string) $read['token'], $unicodeBody, $modernHeaders('tools/call', $readName));
    $unicodeAudit = array_filter($mcp->recentAudit(200), static fn(array $r): bool => $r['request_id'] === 'sha256:' . hash('sha256', $unicodeId) && $r['status'] === 'ok');
    $record($unicodeResponse['status'] === 200 && count($unicodeAudit) === 1, 'HTTP multibyte request ID has exactly one completed audit entry');

    $restrictedTool = $byScope['draft'] ?? $byScope['publish'] ?? $byScope['admin'] ?? null;
    if($restrictedTool) {
        $restrictedName = (string) $restrictedTool['name'];
        $restrictedBody = $modern('tools/call', $runId . '-scope', ['name' => $restrictedName, 'arguments' => new stdClass()]);
        $denied = $request($endpoint, (string) $read['token'], $restrictedBody, $modernHeaders('tools/call', $restrictedName));
        $record($denied['status'] === 403, 'insufficient scope is rejected before provider dispatch', $restrictedName);
    } else {
        $record(false, 'insufficient scope is rejected before provider dispatch', 'no non-read provider tool is installed');
    }

    $unknownName = $mcp->namespacePrefix() . '_missing_' . bin2hex(random_bytes(2));
    $unknownBody = $modern('tools/call', $runId . '-unknown', ['name' => $unknownName, 'arguments' => new stdClass()]);
    $unknown = $request($endpoint, (string) $admin['token'], $unknownBody, $modernHeaders('tools/call', $unknownName));
    $record($unknown['status'] === 404, 'unknown tool is isolated', 'HTTP ' . $unknown['status']);

    $malformedBody = json_encode(['jsonrpc' => '2.0', 'id' => $runId . '-malformed', 'params' => ['_meta' => $meta()]], JSON_THROW_ON_ERROR);
    $malformed = $request($endpoint, (string) $read['token'], $malformedBody, $modernHeaders('tools/list'));
    $record($malformed['status'] === 400, 'malformed JSON-RPC is rejected', 'HTTP ' . $malformed['status'] . ($malformed['error'] !== '' ? ' · ' . $malformed['error'] : ''));
    $oversized = $request($endpoint, (string) $read['token'], json_encode(['payload' => str_repeat('x', \ProcessWire\McpServer::MAX_BODY_BYTES + 64)], JSON_THROW_ON_ERROR));
    $record($oversized['status'] === 413, 'oversized body is rejected before parsing', 'HTTP ' . $oversized['status']);

    $originRejected = $request($endpoint, (string) $read['token'], $listBody, $modernHeaders('tools/list', null, ['Origin' => 'https://attacker.invalid']));
    $record(in_array($originRejected['status'], [400, 403], true), 'untrusted Origin is rejected', 'HTTP ' . $originRejected['status']);
    $hostRejected = $request($endpoint, (string) $read['token'], $listBody, $modernHeaders('tools/list', null, ['Host' => 'attacker.invalid']));
    $record($hostRejected['status'] >= 400, 'untrusted Host is rejected', 'HTTP ' . $hostRejected['status']);

    $revoked = $issue('read');
    $mcp->revokeClient((string) $revoked['id']);
    $revokedResponse = $request($endpoint, (string) $revoked['token'], $listBody, $modernHeaders('tools/list'));
    $record($revokedResponse['status'] === 401, 'revoked credential is rejected', 'HTTP ' . $revokedResponse['status']);

    $expired = $issue('read', time() + 1);
    while(time() <= (int) $expired['expires_at']) usleep(100000);
    $expiredResponse = $request($endpoint, (string) $expired['token'], $listBody, $modernHeaders('tools/list'));
    $record($expiredResponse['status'] === 401, 'expired credential is rejected', 'HTTP ' . $expiredResponse['status']);

    $rate = $issue('read');
    $rateKey = hash('sha256', 'client:' . $rate['id'] . ':' . intdiv(time(), 60));
    $seed = $wire->database->prepare('INSERT INTO mcp_server_limits (bucket_key,hits,expires_at) VALUES (?,?,?)');
    $temporaryLimitKeys[] = $rateKey;
    $seed->execute([$rateKey, max(1, min(600, (int) $mcp->rate_limit_per_minute)), time() + 120]);
    $rateResponse = $request($endpoint, (string) $rate['token'], $listBody, $modernHeaders('tools/list'));
    $record($rateResponse['status'] === 429 && isset($rateResponse['headers']['retry-after']), 'per-client rate limit returns Retry-After', 'HTTP ' . $rateResponse['status']);
    $cleanup = $wire->database->prepare('DELETE FROM mcp_server_limits WHERE bucket_key=?');
    $cleanup->execute([$rateKey]);

    if($restrictedTool) {
        $failureName = (string) $restrictedTool['name'];
        $failureBody = $modern('tools/call', $runId . '-provider-failure', ['name' => $failureName, 'arguments' => new stdClass()]);
        $failureResponse = $request($endpoint, (string) $publish['token'], $failureBody, $modernHeaders('tools/call', $failureName));
        $afterFailureBody = $modern('tools/call', $runId . '-after-failure', ['name' => $readName, 'arguments' => new stdClass()]);
        $afterFailure = $request($endpoint, (string) $read['token'], $afterFailureBody, $modernHeaders('tools/call', $readName));
        $record($failureResponse['status'] !== 500 && $afterFailure['status'] === 200, 'provider or schema failure remains isolated', 'failure HTTP ' . $failureResponse['status']);
    }

    // Regression for the reported scope bypass: an old handshake must never
    // enable unaudited, unauthorized calls through an array envelope.
    $legacyInit = $request($endpoint, (string) $read['token'], json_encode([
        'jsonrpc' => '2.0', 'id' => $runId . '-legacy-init', 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'Scope regression', 'version' => '1']],
    ], JSON_THROW_ON_ERROR));
    $legacySession = $legacyInit['headers']['mcp-session-id'] ?? '';
    $record($legacyInit['status'] === 200 && $legacySession !== '', 'legacy 2025-03-26 handshake succeeds');
    if($legacySession !== '') {
        $legacyHeaders = ['Mcp-Session-Id' => $legacySession, 'MCP-Protocol-Version' => '2025-03-26'];
        $initialized = $request($endpoint, (string) $read['token'], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR), $legacyHeaders);
        $record(in_array($initialized['status'], [200, 202], true), 'legacy initialized notification succeeds');
        $legacyRead = ['jsonrpc' => '2.0', 'id' => $runId . '-legacy-read', 'method' => 'tools/call', 'params' => ['name' => $readName, 'arguments' => new stdClass()]];
        $readLegacy = $request($endpoint, (string) $read['token'], json_encode($legacyRead, JSON_THROW_ON_ERROR), $legacyHeaders);
        $record($readLegacy['status'] === 200, 'legacy single read call remains supported');
        if($restrictedTool) {
            $restrictedLegacy = ['jsonrpc' => '2.0', 'id' => $runId . '-legacy-denied', 'method' => 'tools/call', 'params' => ['name' => $restrictedName, 'arguments' => new stdClass()]];
            $deniedLegacy = $request($endpoint, (string) $read['token'], json_encode($restrictedLegacy, JSON_THROW_ON_ERROR), $legacyHeaders);
            $record($deniedLegacy['status'] === 403, 'legacy single restricted call is denied');
            $restrictedLegacy['id'] = $runId . '-batch-denied';
            $batchDenied = $request($endpoint, (string) $read['token'], json_encode([$restrictedLegacy], JSON_THROW_ON_ERROR), $legacyHeaders);
            $record($batchDenied['status'] === 400 && ($batchDenied['json']['error'] ?? '') === 'batch_not_supported', 'legacy batch scope bypass is rejected before SDK dispatch');
            $mixedRead = $legacyRead;
            $mixedRead['id'] = $runId . '-batch-read-denied';
            $restrictedLegacy['id'] = $runId . '-batch-mixed-denied';
            $mixed = $request($endpoint, (string) $read['token'], json_encode([$mixedRead, $restrictedLegacy], JSON_THROW_ON_ERROR), $legacyHeaders);
            $record($mixed['status'] === 400, 'mixed batches are rejected atomically');
            $adminBatch = $request($endpoint, (string) $admin['token'], json_encode([$restrictedLegacy], JSON_THROW_ON_ERROR), $legacyHeaders);
            $record($adminBatch['status'] === 400, 'batch rejection also applies to admin clients');
        }
        $emptyBatch = $request($endpoint, (string) $read['token'], '[]', $legacyHeaders);
        $record($emptyBatch['status'] === 400, 'empty legacy batch is rejected');
        $modernBatchBody = $modern('tools/call', $runId . '-modern-batch', ['name' => $readName, 'arguments' => new stdClass()]);
        $modernBatch = $request($endpoint, (string) $read['token'], '[' . $modernBatchBody . ']', $modernHeaders('tools/call', $readName));
        $record($modernBatch['status'] === 400 && ($modernBatch['json']['error'] ?? '') === 'batch_not_supported', 'modern batches share the same gateway rejection');
        $readLegacy = $request($endpoint, (string) $read['token'], json_encode($legacyRead, JSON_THROW_ON_ERROR), $legacyHeaders);
        $record($readLegacy['status'] === 200, 'legacy session remains usable after rejected batches');
    }

    $auditRows = $mcp->recentAudit(200);
    $auditMatch = array_filter($auditRows, static fn(array $row): bool => (string) ($row['request_id'] ?? '') === $runId . '-read');
    $record($auditMatch !== [], 'successful tool call creates bounded audit evidence');
    $record(count($auditMatch) === 1, 'single tool execution is audited exactly once');
    if($restrictedTool && $legacySession !== '') {
        $deniedAudit = array_values(array_filter($auditRows, static fn(array $row): bool => (string) ($row['request_id'] ?? '') === $runId . '-batch-denied'));
        $record(count($deniedAudit) === 1 && $deniedAudit[0]['status'] === 'denied' && $deniedAudit[0]['client_id'] === $read['id'] && $deniedAudit[0]['scope'] === $restrictedTool['scope'], 'rejected legacy batch is audited with client, scope and individual request ID');
        $mixedAudit = array_values(array_filter($auditRows, static fn(array $row): bool => (string) ($row['request_id'] ?? '') === $runId . '-batch-read-denied'));
        $record(count($mixedAudit) === 1 && $mixedAudit[0]['status'] === 'denied', 'read element of a rejected mixed batch never executes');
    }
    if(in_array($endpointHost, ['127.0.0.1', '::1'], true)) {
        $authKey = hash('sha256', 'auth:ip:' . $endpointHost . ':' . intdiv(time(), 60));
        $temporaryLimitKeys[] = $authKey;
        $seed = $wire->database->prepare('INSERT INTO mcp_server_limits (bucket_key,hits,expires_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE hits=VALUES(hits)');
        $seed->execute([$authKey, 120, time()+120]);
        $blocked = $request($endpoint, $read['prefix'] . str_repeat('x',43), '{}');
        $record($blocked['status'] === 429 && isset($blocked['headers']['retry-after']), 'HTTP authentication admission limit precedes invalid token verification');
    } else {
        fwrite(STDOUT, "[SKIP] pre-auth peer seed requires a direct loopback endpoint.\n");
    }
} finally {
    $cleanup = $wire->database->prepare('DELETE FROM mcp_server_limits WHERE bucket_key=?');
    foreach($temporaryLimitKeys as $key) $cleanup->execute([$key]);
    if($legacySession !== '' && isset($read['token'])) {
        $request($endpoint, (string) $read['token'], '', ['Mcp-Session-Id' => $legacySession, 'MCP-Protocol-Version' => '2025-03-26'], 'DELETE');
    }
    foreach(array_unique($issued) as $id) $mcp->revokeClient($id);
}

if($failures) {
    fwrite(STDERR, count($failures) . " HTTP integration checks failed:\n- " . implode("\n- ", $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, $passes . " HTTP integration checks passed. Test clients were revoked.\n");
