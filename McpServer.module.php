<?php namespace ProcessWire;

require_once __DIR__ . '/src/Core/McpLifecycleTrait.php';
require_once __DIR__ . '/src/Admin/McpConfigTrait.php';
require_once __DIR__ . '/src/Transport/McpEndpointTrait.php';
require_once __DIR__ . '/src/Support/McpEnvironmentTrait.php';
require_once __DIR__ . '/src/Security/McpClientTrait.php';
require_once __DIR__ . '/src/Provider/McpProviderRegistryTrait.php';
require_once __DIR__ . '/src/Audit/McpAuditTrait.php';

/**
 * Authenticated, provider-driven MCP gateway for ProcessWire.
 */
class McpServer extends WireData implements Module, ConfigurableModule {

    use McpLifecycleTrait;
    use McpConfigTrait;
    use McpEndpointTrait;
    use McpEnvironmentTrait;
    use McpClientTrait;
    use McpProviderRegistryTrait;
    use McpAuditTrait;

    public const VERSION = 101;
    public const VERSION_STRING = '1.0.1';
    public const DEFAULT_ENDPOINT = '/mcp/';
    public const DEFAULT_RATE_LIMIT = 60;
    public const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /** @var array<string,array<string,mixed>>|null */
    private ?array $toolRegistry = null;

    /** @var array<string,mixed>|null */
    private ?array $activeClient = null;

    public static function getModuleInfo(): array {
        return [
            'title' => 'MCP Server',
            'version' => self::VERSION,
            'summary' => 'Connect AI clients to safe, module-owned ProcessWire operations.',
            'author' => 'Maxim Semenov',
            'href' => 'https://github.com/mxmsmnv/McpServer',
            'license' => 'MPL-2.0',
            'hreflicense' => 'LICENSE',
            'autoload' => true,
            'singular' => true,
            'requires' => ['PHP>=8.2.0', 'ProcessWire>=3.0.200'],
            'installs' => ['ProcessMcpServer'],
        ];
    }
}
