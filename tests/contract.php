<?php

$root = dirname(__DIR__);
$readSources = static function(array $files): string {
    $source = '';
    foreach($files as $file) {
        $source .= "\n" . (string) file_get_contents($file);
    }
    return $source;
};

$moduleRoot = (string) file_get_contents($root . '/McpServer.module.php');
$module = $moduleRoot . $readSources([
    $root . '/src/Core/McpLifecycleTrait.php',
    $root . '/src/Admin/McpConfigTrait.php',
    $root . '/src/Transport/McpEndpointTrait.php',
    $root . '/src/Support/McpEnvironmentTrait.php',
    $root . '/src/Security/McpClientTrait.php',
    $root . '/src/Provider/McpProviderRegistryTrait.php',
    $root . '/src/Audit/McpAuditTrait.php',
]);
$referenceHandler = (string) file_get_contents($root . '/McpServerReferenceHandler.php');
$processRoot = (string) file_get_contents($root . '/ProcessMcpServer.module.php');
$process = $processRoot . $readSources(glob($root . '/src/Admin/ProcessMcp*Trait.php') ?: []);
$css = (string) file_get_contents($root . '/src/Admin/admin.css');
$cli = (string) file_get_contents($root . '/bin/mcp-server');
$readme = (string) file_get_contents($root . '/README.md');
$security = (string) file_get_contents($root . '/SECURITY.md');
$support = (string) file_get_contents($root . '/SUPPORT.md');
$httpIntegration = (string) file_get_contents($root . '/tests/http-integration.php');
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);

$checks = [
    'runtime composition roots stay compact' => substr_count($moduleRoot, "\n") < 100
        && substr_count($processRoot, "\n") < 100
        && str_contains($moduleRoot, 'use McpEndpointTrait;')
        && str_contains($processRoot, 'use ProcessMcpDocumentationTrait;'),
    'admin stylesheet lives with the admin source' => is_file($root . '/src/Admin/admin.css')
        && !is_file($root . '/admin.css')
        && str_contains($module, 'McpServer/src/Admin/admin.css?v='),
    'official MCP SDK is pinned' => ($composer['require']['mcp/sdk'] ?? '') === '^0.8.0',
    'streamable HTTP transport is used' => str_contains($module, 'StreamableHttpTransport'),
    'endpoint is disabled by default' => str_contains($module, "'enabled' => 0"),
    'bearer tokens are hashed' => str_contains($module, 'password_hash(') && str_contains($module, 'password_verify('),
    'credential hashes use the config.php site secret as a pepper' => str_contains($module, "get('userAuthSalt')")
        && str_contains($module, "hash_hmac('sha256'")
        && str_contains($module, "'token_hash_version' => 2"),
    'legacy credential hashes upgrade after successful authentication' => str_contains($module, '$hashVersion < 2')
        && str_contains($module, '$this->hashCredential($raw)'),
    'client identities use durable storage' => str_contains($module, 'mcp_server_clients') && str_contains($module, 'ON DUPLICATE KEY UPDATE'),
    'tokens are not exposed by clients API' => str_contains($module, "unset(\$client['token_hash'], \$client['token_hash_version'])"),
    'scope boundary exists' => str_contains($module, 'clientHasScope(') && str_contains($module, "['read', 'draft', 'publish', 'admin']"),
    'expected domain failures become safe MCP tool errors' => str_contains($module, "require_once __DIR__ . '/McpServerReferenceHandler.php'")
        && str_contains($referenceHandler, 'catch(WireException $e)')
        && str_contains($referenceHandler, 'new ToolCallException($e->getMessage()'),
    'audit recognises protocol and tool-result errors' => str_contains($module, 'responseRepresentsError($response)')
        && str_contains($module, 'isset($message[\'error\'])')
        && str_contains($module, '!empty($message[\'result\'][\'isError\'])'),
    'body size is bounded before JSON parsing and by transport' => str_contains($module, 'boundedRequestBody(')
        && str_contains($module, 'MAX_BODY_BYTES')
        && str_contains($module, 'maxBodyBytes: self::MAX_BODY_BYTES'),
    'SDK 0.8 owns protocol negotiation at the transport boundary' => !str_contains($module, 'ProtocolVersionMiddleware'),
    'FastCGI duplicate Host values are canonicalised' => str_contains($module, "explode(',', \$request->getHeaderLine('Host'), 2)"),
    'providers require explicit metadata' => str_contains($module, "empty(\$info['mcpProvider'])"),
    'installation namespace is configurable and applied to tools and tokens' => str_contains($module, "'namespace_prefix' => ''")
        && str_contains($module, 'public function namespacePrefix()')
        && str_contains($module, "\$this->namespacePrefix() . '_mcp_'")
        && str_contains($module, '$namespace . $localName'),
    'gateway runtime has no LQRS identity hardcode' => !preg_match('/\\bLQRS\\b|lqrs_mcp_|lqrs_system_status|lqrs_mcp_capabilities/', $module . "\n" . $process),
    'arbitrary operations are denied by policy' => str_contains($module, "'arbitrary_php' => false") && str_contains($module, "'arbitrary_sql' => false"),
    'raw arguments are represented by digest' => str_contains($module, "hash('sha256'") && str_contains($module, 'arguments_digest'),
    'client workspace supports create and revoke' => str_contains($process, "\$action === 'create'") && str_contains($process, "\$action === 'revoke'"),
    'Process install delegates admin page creation' => str_contains($process, 'parent::___install()'),
    'provider and audit pages exist while legacy settings redirect' => str_contains($process, '___executeProviders()')
        && str_contains($process, '___executeAudit()')
        && str_contains($process, '___executeSettings()')
        && str_contains($process, "redirect(\$this->moduleConfigUrl())"),
    'audit workspace uses bounded server-side pagination' => str_contains($module, 'public function auditPage(')
        && str_contains($module, 'LIMIT :limit OFFSET :offset')
        && str_contains($process, "get->int('audit_page')")
        && str_contains($process, 'private function pagination(')
        && str_contains($process, "\$this->mcp()->auditPage(\$requestedPage, 25)"),
    'collapsed admin masthead cannot widen tablet pages' => str_contains($css, '@media (max-width: 1199px)')
        && str_contains($css, 'body.ProcessMcpServer #pw-masthead.pw-masthead-hidden'),
    'module configuration exposes operational context' => str_contains($module, 'renderConfigOverview()')
        && str_contains($module, "addBodyClass('ProcessMcpServerConfig')")
        && str_contains($module, 'McpConfigStatusGrid')
        && str_contains($module, "'setup/mcp-server/'"),
    'module configuration groups identity, transport, and network settings' => str_contains($module, "'mcp_config_identity'")
        && str_contains($module, "'mcp_config_endpoint'")
        && str_contains($module, "'mcp_config_network'")
        && str_contains($module, 'McpConfigSection McpConfigSection--expanded'),
    'operator workspace follows the shared admin hierarchy' => str_contains($process, 'uk-subnav uk-subnav-pill mcp-tabs')
        && str_contains($process, 'mcp-page-intro')
        && str_contains($process, 'mcp-module-settings')
        && str_contains($process, 'private function workspace(')
        && str_contains($css, '.ProcessMcpServer .mcp-admin')
        && str_contains($css, 'var(--pw-blocks-background)'),
    'operator documentation explains setup and provider integration' => str_contains($process, '___executeDocumentation()')
        && str_contains($process, "'documentation' => ['documentation/'")
        && str_contains($process, "id=\"module-integration\"")
        && str_contains($process, "'mcpProvider' => true")
        && str_contains($process, 'mcpProviderInfo()')
        && str_contains($process, 'mcpTools()'),
    'operator documentation covers client compatibility and live readiness' => str_contains($process, "'Claude Code'")
        && str_contains($process, "'Claude Desktop'")
        && str_contains($process, "'VS Code'")
        && str_contains($process, 'mcp-readiness-grid')
        && str_contains($process, 'private function readinessCard('),
    'operator UI explains non-recoverable credential storage' => str_contains($process, "'Raw bearer tokens are never recoverable'")
        && str_contains($process, 'salted one-way hash protected by config.php'),
    'bounded CLI is configurable and protects credential mutations' => str_contains($module, "'enable_cli' => 0")
        && str_contains($module, "name = 'enable_cli'")
        && str_contains($cli, "case 'client-create':")
        && str_contains($cli, "case 'client-revoke':")
        && str_contains($cli, "requires the explicit --execute flag")
        && !str_contains($cli, 'token_hash'),
    'workspace has one canonical configuration surface' => !str_contains($process, "'settings' => ['settings/'")
        && str_contains($process, "private function moduleConfigUrl()"),
    'dedicated CLI workspace documents every command' => str_contains($process, '___executeCli()')
        && str_contains($process, "'cli' => ['cli/'")
        && str_contains($process, "'client-create'")
        && str_contains($process, "'client-revoke'")
        && str_contains($process, 'private function commandCard(')
        && !str_contains($process, 'href="#cli"')
        && !str_contains($process, 'id="cli"'),
    'installation environment can be explicit or auto-detected' => str_contains($module, "'environment_mode' => 'auto'")
        && str_contains($module, "name = 'environment_mode'")
        && str_contains($module, 'public function environmentMode()')
        && str_contains($module, "['auto', 'development', 'staging', 'production']")
        && str_contains($module, "environment_source"),
    'public URL is configurable and secure in headless CLI context' => str_contains($module, "'public_base_url' => ''")
        && str_contains($module, 'public function publicSiteUrl()')
        && str_contains($module, "preg_replace('#^http://#i', 'https://'")
        && str_contains($module, "name = 'public_base_url'"),
    'module configuration wraps long command values on mobile' => str_contains($css, '#ModuleEditForm .McpConfigSection code')
        && str_contains($css, 'overflow-wrap: anywhere'),
    'real development HTTP integration suite covers the security boundary' => str_contains($httpIntegration, "environmentName() !== 'development'")
        && str_contains($httpIntegration, 'missing bearer token is rejected')
        && str_contains($httpIntegration, 'insufficient scope is rejected')
        && str_contains($httpIntegration, 'oversized body is rejected')
        && str_contains($httpIntegration, 'untrusted Origin is rejected')
        && str_contains($httpIntegration, 'per-client rate limit')
        && str_contains($httpIntegration, 'audit evidence')
        && str_contains($httpIntegration, 'finally'),
    'release documentation omits volatile version labels and keeps private security reporting' => !str_contains($readme, 'Current version:')
        && str_contains($security, 'Report a vulnerability')
        && str_contains($support, 'best-effort'),
];

$failed = [];
foreach($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if(!$ok) $failed[] = $label;
}

if($failed) {
    fwrite(STDERR, count($failed) . " contract checks failed.\n");
    exit(1);
}

echo count($checks) . " contract checks passed.\n";
