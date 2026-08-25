<?php namespace ProcessWire;

/**
 * CLI and integration documentation workspaces.
 */
trait ProcessMcpDocumentationTrait {

    public function ___executeCli(): string {
        $this->headline($this->_('MCP CLI'));
        $mcp = $this->mcp();
        $enabled = (bool) $mcp->enable_cli;
        $command = 'php site/modules/McpServer/bin/mcp-server';
        $configurationUrl = $this->moduleConfigUrl() . '#wrap_Inputfield_mcp_config_local';

        $out = $this->nav('cli')
            . $this->pageIntro($this->_('Local administration'), $this->_('Inspect and administer this MCP installation from the server command line with bounded JSON responses.'), '<span class="mcp-state mcp-state--' . ($enabled ? 'ready' : 'attention') . '">' . ($enabled ? $this->_('CLI enabled') : $this->_('CLI disabled')) . '</span>')
            . '<section class="mcp-grid mcp-cli-status"><article class="mcp-panel"><header><div><p class="mcp-kicker">' . $this->_('Execution contract') . '</p><h2>' . $this->_('Run from the ProcessWire root') . '</h2></div></header><dl class="mcp-facts"><div><dt>' . $this->_('Executable') . '</dt><dd><code>site/modules/McpServer/bin/mcp-server</code></dd></div><div><dt>' . $this->_('Output') . '</dt><dd>JSON</dd></div><div><dt>' . $this->_('Environment') . '</dt><dd>' . $this->e(ucfirst($mcp->environmentName())) . ' · ' . $this->e($mcp->environmentMode() === 'auto' ? $this->_('auto-detected') : $this->_('explicit')) . '</dd></div></dl><pre><code>' . $this->e($command . ' help') . '</code></pre></article>'
            . '<article class="mcp-panel"><header><div><p class="mcp-kicker">' . $this->_('Safety') . '</p><h2>' . $this->_('Credential mutations are explicit') . '</h2></div></header><ul class="mcp-checks"><li>' . $this->_('The CLI must be enabled in module configuration') . '</li><li>' . $this->_('Client creation and revocation require --execute') . '</li><li>' . $this->_('Stored token hashes are never returned') . '</li><li>' . $this->_('A newly issued raw token is displayed once') . '</li></ul><a class="uk-button uk-button-default mcp-panel-action" href="' . $this->e($configurationUrl) . '">' . $this->_('Open CLI configuration') . '</a></article></section>'
            . '<section class="mcp-section"><header><div><p class="mcp-kicker">' . $this->_('Read commands') . '</p><h2>' . $this->_('Inspect the gateway without changing data') . '</h2></div></header><div class="mcp-command-grid">'
            . $this->commandCard('status', $this->_('Gateway, runtime, endpoint, provider, tool, and active-client status.'), $command . ' status')
            . $this->commandCard('clients', $this->_('Client identities, scopes, state, timestamps, and non-secret token prefixes.'), $command . ' clients')
            . $this->commandCard('providers', $this->_('Installed providers and the complete exposed tool registry.'), $command . ' providers')
            . $this->commandCard('audit', $this->_('Recent audit rows. Limit is bounded from 1 to 200.'), $command . ' audit --limit=25')
            . '</div></section>'
            . '<section class="mcp-section"><header><div><p class="mcp-kicker">' . $this->_('Credential administration') . '</p><h2>' . $this->_('Create or revoke one client identity') . '</h2><p>' . $this->_('Use a separate identity for every computer or agent runtime. Begin with read and add stronger scopes only after review.') . '</p></div></header><div class="mcp-command-grid mcp-command-grid--mutations">'
            . $this->commandCard('client-create', $this->_('Issue a credential. Options: --label, --scopes, optional --expires=YYYY-MM-DD, and required --execute.'), $command . ' client-create --label="MacBook · Codex" --scopes=read --execute')
            . $this->commandCard('client-revoke', $this->_('Disable a client immediately while preserving its identity and audit history.'), $command . ' client-revoke --id=CLIENT_ID --execute')
            . '</div></section>'
            . '<aside class="mcp-advisory"><i class="fa fa-info-circle" aria-hidden="true"></i><div><strong>' . $this->_('ProcessWire root not found?') . '</strong><p>' . $this->_('Run the command from the site root or add --root=/absolute/path/to/processwire. The help command is available even when the CLI is disabled.') . '</p></div></aside>';
        return $this->workspace($out);
    }

    public function ___executeDocumentation(): string {
        $this->headline($this->_('MCP Documentation'));
        $mcp = $this->mcp();
        $status = $mcp->toolSystemStatus();
        $clients = $mcp->clients();
        $providers = $mcp->providers();
        $tools = $mcp->tools();
        $activeClients = count(array_filter($clients, fn(array $client): bool => !empty($client['enabled'])));
        $endpointReady = !empty($status['enabled']);
        $base = $this->wire('config')->urls->admin . 'setup/mcp-server/';
        $clientUrl = $base . 'clients/';
        $providerUrl = $base . 'providers/';
        $auditUrl = $base . 'audit/';
        $settingsUrl = $this->moduleConfigUrl();
        $example = <<<'PHP'
public static function getModuleInfo(): array {
    return [
        'title' => 'My Module',
        'version' => '1.0.0',
        'mcpProvider' => true,
    ];
}

public function mcpProviderInfo(): array {
    return [
        'name' => 'my-module',
        'title' => 'My Module',
        'version' => '1.0.0',
    ];
}

public function mcpTools(): array {
    return [[
        'name' => 'my_module_list_items',
        'title' => 'List items',
        'description' => 'Return a bounded list of items.',
        'handler' => [$this, 'mcpListItems'],
        'scope' => 'read',
        'read_only' => true,
        'destructive' => false,
        'idempotent' => true,
        'open_world' => false,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
            'additionalProperties' => false,
        ],
    ]];
}
PHP;

        $out = $this->nav('documentation')
            . $this->pageIntro($this->_('MCP in plain language'), $this->_('Use a compatible AI client on your computer as a control panel for ProcessWire. MCP Server checks who is connecting, which actions are allowed, and which installed module must perform the work.'), '<span class="mcp-state mcp-state--' . ($endpointReady ? 'ready' : 'off') . '">' . ($endpointReady ? $this->_('Gateway ready') : $this->_('Gateway disabled')) . '</span>')
            . '<nav class="mcp-doc-nav" aria-label="' . $this->_('Documentation sections') . '"><a href="#readiness">' . $this->_('Readiness') . '</a><a href="#connect">' . $this->_('Connect') . '</a><a href="#scopes">' . $this->_('Scopes') . '</a><a href="#module-integration">' . $this->_('Module integration') . '</a></nav>'
            . '<section class="mcp-doc-flow" aria-label="' . $this->_('How MCP Server works') . '">'
            . $this->docStep('1', $this->_('Your AI client'), $this->_('Codex, ChatGPT, Claude Code, or another MCP client sends a structured request from your computer.'))
            . $this->docStep('2', $this->_('MCP Server'), $this->_('The gateway verifies the client token, scope, rate limit, requested tool, and input.'))
            . $this->docStep('3', $this->_('ProcessWire module'), $this->_('The owning module runs its own validated business logic and returns a structured result.'))
            . '</section>'
            . '<section class="mcp-section mcp-readiness" id="readiness"><header><div><p class="mcp-kicker">' . $this->_('This installation') . '</p><h2>' . $this->_('What is ready and what still needs configuration') . '</h2><p>' . $this->_('The server can accept useful work only when transport, identity, and at least one domain provider are ready together.') . '</p></div></header><div class="mcp-readiness-grid">'
            . $this->readinessCard($this->_('Remote endpoint'), $endpointReady ? $this->_('Ready') : $this->_('Disabled'), $endpointReady ? $this->_('This installation accepts authenticated MCP requests.') : $this->_('Review the network boundary and enable the endpoint when it is safe.'), $endpointReady ? 'ready' : 'attention', $settingsUrl)
            . $this->readinessCard($this->_('Client identities'), (string) $activeClients, $activeClients > 0 ? $this->_('Active credentials can connect from approved computers or agent runtimes.') : $this->_('Create a separate credential for each computer or agent runtime.'), $activeClients > 0 ? 'ready' : 'attention', $clientUrl)
            . $this->readinessCard($this->_('Module tools'), (string) count($tools), sprintf($this->_('%d providers currently contribute bounded operations.'), count($providers)), count($tools) > 2 ? 'ready' : 'attention', $providerUrl)
            . '</div><div class="mcp-readiness-next"><strong>' . $this->_('Still required on each computer') . '</strong><p>' . $this->_('Add the MCP URL and that computer’s bearer token to the chosen client, start with read access, make a test call, and confirm the result in Audit. Production must use its own URL and credential.') . '</p></div></section>'
            . '<section class="mcp-section mcp-doc-section"><header><div><p class="mcp-kicker">' . $this->_('Compatible clients') . '</p><h2>' . $this->_('Use any client that supports remote Streamable HTTP MCP') . '</h2><p>' . $this->_('MCP is an open protocol. This gateway does not depend on one AI vendor or one desktop application.') . '</p></div></header><div class="mcp-client-grid">'
            . $this->clientCard('Codex', $this->_('Connect the custom MCP server from Codex or the ChatGPT plugin settings.'))
            . $this->clientCard('ChatGPT', $this->_('Use a custom MCP connection when it is available for the account and workspace.'))
            . $this->clientCard('Claude Code', $this->_('Add this installation as a remote HTTP MCP server with an Authorization bearer header.'))
            . $this->clientCard('Claude Desktop', $this->_('Use the MCP connection controls provided by the Anthropic desktop client.'))
            . $this->clientCard('VS Code', $this->_('Add the remote HTTP server to the user or workspace MCP configuration.'))
            . $this->clientCard($this->_('Other MCP clients'), $this->_('Any client is suitable when it supports Streamable HTTP and can send a bearer token securely.'))
            . '</div></section>'
            . '<div class="mcp-doc-grid"><section class="mcp-panel mcp-doc-panel"><header><div><p class="mcp-kicker">' . $this->_('What this gives you') . '</p><h2>' . $this->_('One controlled entry point to your site') . '</h2></div></header><ul class="mcp-checks"><li>' . $this->_('Manage approved site data without giving an agent SSH, database, or unrestricted PHP access') . '</li><li>' . $this->_('Use the same client workflow with development and production while keeping their URLs and credentials separate') . '</li><li>' . $this->_('Let each ProcessWire module expose only the operations it owns and validates') . '</li><li>' . $this->_('Review which client called which tool, when it ran, and whether it succeeded') . '</li></ul></section>'
            . '<section class="mcp-panel mcp-doc-panel"><header><div><p class="mcp-kicker">' . $this->_('Important boundary') . '</p><h2>' . $this->_('MCP Server is a gateway, not a remote shell') . '</h2></div></header><p>' . $this->_('The gateway does not invent ways to edit ProcessWire. A change is possible only when an installed provider module publishes a specific tool for it. That module remains responsible for validation, permissions, identifiers, and business rules.') . '</p><div class="mcp-doc-note"><strong>' . $this->_('Example') . '</strong><span>' . $this->_('Job Board can publish a bounded job-import tool. MCP Server authenticates the request; Job Board validates and stores the vacancy.') . '</span></div></section></div>'
            . '<section class="mcp-panel mcp-doc-panel" id="connect"><header><div><p class="mcp-kicker">' . $this->_('First connection') . '</p><h2>' . $this->_('Set up a computer or agent in five steps') . '</h2></div></header><ol class="mcp-doc-checklist">'
            . '<li><span>1</span><div><strong>' . $this->_('Review the endpoint') . '</strong><p>' . $this->_('Confirm the path, allowed host, browser origins, and request limit for this installation.') . '</p><a href="' . $this->e($settingsUrl) . '">' . $this->_('Open endpoint settings') . '</a></div></li>'
            . '<li><span>2</span><div><strong>' . $this->_('Create a client') . '</strong><p>' . $this->_('Create a separate identity for every computer or agent runtime. Copy the token when it appears; it cannot be shown again.') . '</p><a href="' . $this->e($clientUrl) . '">' . $this->_('Manage clients') . '</a></div></li>'
            . '<li><span>3</span><div><strong>' . $this->_('Choose the smallest scope') . '</strong><p>' . $this->_('Begin with read. Add draft or publish only when the client has a reviewed reason to change content.') . '</p></div></li>'
            . '<li><span>4</span><div><strong>' . $this->_('Connect with Streamable HTTP') . '</strong><p>' . $this->_('Enter this installation’s MCP URL in the client and supply the token through a protected environment variable or secret store.') . '</p></div></li>'
            . '<li><span>5</span><div><strong>' . $this->_('Verify tools and audit evidence') . '</strong><p>' . $this->_('Confirm that only expected providers and tools are visible, make a read-only call, then inspect its audit record.') . '</p><a href="' . $this->e($providerUrl) . '">' . $this->_('Review providers') . '</a> <a href="' . $this->e($auditUrl) . '">' . $this->_('Review audit') . '</a></div></li>'
            . '</ol></section>'
            . '<section class="mcp-section mcp-doc-section" id="scopes"><header><div><p class="mcp-kicker">' . $this->_('Access levels') . '</p><h2>' . $this->_('Give every client only the authority it needs') . '</h2></div></header><div class="mcp-scope-grid">'
            . $this->scopeCard('read', $this->_('Read'), $this->_('Inspect status, counts, validation results, previews, and bounded records without changing data.'))
            . $this->scopeCard('draft', $this->_('Draft'), $this->_('Create or update staging records that are not yet public. Includes read tools.'))
            . $this->scopeCard('publish', $this->_('Publish'), $this->_('Change public state, such as publishing or expiring a record. Includes draft and read tools.'))
            . $this->scopeCard('admin', $this->_('Admin'), $this->_('Highest authority for reviewed administration. Avoid using it for ordinary content operations.'))
            . '</div></section>'
            . '<section class="mcp-panel mcp-doc-panel" id="module-integration"><header><div><p class="mcp-kicker">' . $this->_('Module integration') . '</p><h2>' . $this->_('Let another ProcessWire module contribute tools') . '</h2><p>' . $this->_('The provider module opts in explicitly, describes itself, and returns bounded tool definitions. MCP Server discovers it automatically after the ProcessWire module cache is refreshed.') . '</p></div></header><div class="mcp-doc-code-grid"><div><ol class="mcp-doc-checklist mcp-doc-checklist--compact"><li><span>1</span><div><strong>' . $this->_('Opt in') . '</strong><p>' . $this->_('Add mcpProvider to the module metadata. Nothing is exposed implicitly.') . '</p></div></li><li><span>2</span><div><strong>' . $this->_('Describe the provider') . '</strong><p>' . $this->_('Return a stable name, human title, and module version from mcpProviderInfo().') . '</p></div></li><li><span>3</span><div><strong>' . $this->_('Declare bounded tools') . '</strong><p>' . $this->_('Each tool needs a unique name, callable handler, scope, safety annotations, and a closed JSON Schema.') . '</p></div></li><li><span>4</span><div><strong>' . $this->_('Keep business rules in the module') . '</strong><p>' . $this->_('Bound queries and batches, use stable identifiers, make writes idempotent, and separate staging from publication.') . '</p></div></li></ol></div><pre class="mcp-code-sample"><code>' . $this->e($example) . '</code></pre></div></section>'
            . '<section class="mcp-doc-footer"><div><p class="mcp-kicker">' . $this->_('Safe operating rule') . '</p><h2>' . $this->_('Test on development, then create a separate production client') . '</h2><p>' . $this->_('Never reuse a development token in production. Do not put bearer tokens in Git, ProcessWire content, screenshots, prompts, or shared command history.') . '</p></div><a class="uk-button uk-button-primary" href="' . $this->e($clientUrl) . '">' . $this->_('Manage clients') . '</a></section>';
        return $this->workspace($out);
    }


    private function docStep(string $number, string $title, string $description): string {
        return '<article><span>' . $this->e($number) . '</span><div><strong>' . $this->e($title) . '</strong><p>' . $this->e($description) . '</p></div></article>';
    }

    private function scopeCard(string $scope, string $title, string $description): string {
        return '<article><span class="mcp-scope mcp-scope--' . $this->e($scope) . '">' . $this->e($scope) . '</span><h3>' . $this->e($title) . '</h3><p>' . $this->e($description) . '</p></article>';
    }

    private function readinessCard(string $label, string $value, string $description, string $state, string $url): string {
        return '<article data-state="' . $this->e($state) . '"><div><i aria-hidden="true"></i><span>' . $this->e($label) . '</span></div><strong>' . $this->e($value) . '</strong><p>' . $this->e($description) . '</p><a href="' . $this->e($url) . '">' . $this->_('Review') . '</a></article>';
    }

    private function clientCard(string $title, string $description): string {
        return '<article><span class="mcp-client-mark" aria-hidden="true"><i class="fa fa-plug"></i></span><div><h3>' . $this->e($title) . '</h3><p>' . $this->e($description) . '</p></div></article>';
    }

    private function actionCard(string $icon, string $title, string $description, string $url): string {
        return '<a class="mcp-action-card" href="' . $this->e($url) . '"><span class="mcp-provider-icon"><i class="fa fa-' . $this->e($icon) . '" aria-hidden="true"></i></span><span><strong>' . $this->e($title) . '</strong><small>' . $this->e($description) . '</small></span><i class="fa fa-arrow-right" aria-hidden="true"></i></a>';
    }

    private function commandCard(string $command, string $description, string $example): string {
        return '<article class="mcp-command-card"><header><code>' . $this->e($command) . '</code></header><p>' . $this->e($description) . '</p><pre><code>' . $this->e($example) . '</code></pre></article>';
    }

    private function scopeMetric(string $scope, int $count): string {
        return '<article><span class="mcp-scope mcp-scope--' . $this->e($scope) . '">' . $this->e($scope) . '</span><strong>' . $count . '</strong><small>' . ($count === 1 ? $this->_('tool') : $this->_('tools')) . '</small></article>';
    }

}
