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

$record = static function(bool $ok, string $label, string $detail = '') use (&$failures, &$passes): void {
    fwrite(STDOUT, ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL);
    if($ok) $passes++; else $failures[] = $label . ($detail !== '' ? ': ' . $detail : '');
};

/** @return array{status:int,headers:array<string,string>,body:string,json:mixed,error:string} */
$request = static function(string $url, ?string $token, string $body, array $headers = []) use($insecure): array {
    $responseHeaders = [];
    $curl = curl_init($url);
    $baseHeaders = ['Content-Type: application/json', 'Accept: application/json, text/event-stream'];
    if($token !== null) $baseHeaders[] = 'Authorization: Bearer ' . $token;
    foreach($headers as $name => $value) $baseHeaders[] = $name . ': ' . $value;
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
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
    curl_close($curl);
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
    $bucket = gmdate('YmdHi');
    $rateKey = 'mcp-rate-' . hash('sha256', (string) $rate['id'] . ':' . $bucket);
    $wire->cache->save($rateKey, max(1, min(600, (int) $mcp->rate_limit_per_minute)), 90);
    $rateResponse = $request($endpoint, (string) $rate['token'], $listBody, $modernHeaders('tools/list'));
    $record($rateResponse['status'] === 429 && isset($rateResponse['headers']['retry-after']), 'per-client rate limit returns Retry-After', 'HTTP ' . $rateResponse['status']);
    $wire->cache->delete($rateKey);

    if($restrictedTool) {
        $failureName = (string) $restrictedTool['name'];
        $failureBody = $modern('tools/call', $runId . '-provider-failure', ['name' => $failureName, 'arguments' => new stdClass()]);
        $failureResponse = $request($endpoint, (string) $publish['token'], $failureBody, $modernHeaders('tools/call', $failureName));
        $afterFailure = $request($endpoint, (string) $read['token'], $callBody, $modernHeaders('tools/call', $readName));
        $record($failureResponse['status'] !== 500 && $afterFailure['status'] === 200, 'provider or schema failure remains isolated', 'failure HTTP ' . $failureResponse['status']);
    }

    $auditRows = $mcp->recentAudit(200);
    $auditMatch = array_filter($auditRows, static fn(array $row): bool => (string) ($row['request_id'] ?? '') === $runId . '-read');
    $record($auditMatch !== [], 'successful tool call creates bounded audit evidence');
} finally {
    foreach(array_unique($issued) as $id) $mcp->revokeClient($id);
}

if($failures) {
    fwrite(STDERR, count($failures) . " HTTP integration checks failed:\n- " . implode("\n- ", $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, $passes . " HTTP integration checks passed. Test clients were revoked.\n");
