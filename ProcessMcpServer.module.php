<?php namespace ProcessWire;

require_once __DIR__ . '/src/Admin/ProcessMcpLifecycleTrait.php';
require_once __DIR__ . '/src/Admin/ProcessMcpOverviewTrait.php';
require_once __DIR__ . '/src/Admin/ProcessMcpClientsTrait.php';
require_once __DIR__ . '/src/Admin/ProcessMcpProvidersTrait.php';
require_once __DIR__ . '/src/Admin/ProcessMcpDocumentationTrait.php';
require_once __DIR__ . '/src/Admin/ProcessMcpAuditTrait.php';
require_once __DIR__ . '/src/Admin/ProcessMcpPresentationTrait.php';

/**
 * Operator workspace for MCP clients, providers, tools, and audit history.
 */
class ProcessMcpServer extends Process {

    use ProcessMcpLifecycleTrait;
    use ProcessMcpOverviewTrait;
    use ProcessMcpClientsTrait;
    use ProcessMcpProvidersTrait;
    use ProcessMcpDocumentationTrait;
    use ProcessMcpAuditTrait;
    use ProcessMcpPresentationTrait;

    public static function getModuleInfo(): array {
        return [
            'title' => 'MCP Server',
            'version' => McpServer::VERSION,
            'summary' => 'Connect AI clients to safe, module-owned ProcessWire operations.',
            'author' => 'Maxim Semenov',
            'href' => 'https://github.com/mxmsmnv/McpServer',
            'license' => 'MPL-2.0',
            'requires' => ['McpServer'],
            'permission' => 'mcp-server-admin',
            'page' => [
                'name' => 'mcp-server',
                'parent' => 'setup',
                'title' => 'MCP Server',
            ],
        ];
    }
}
