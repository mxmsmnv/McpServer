<?php namespace ProcessWire;

/**
 * Operator dashboard overview.
 */
trait ProcessMcpOverviewTrait {

    public function ___execute(): string {
        $this->headline($this->_('MCP Server'));
        $mcp = $this->mcp();
        $status = $mcp->toolSystemStatus();
        $clients = $mcp->clients();
        $providers = $mcp->providers();
        $tools = $mcp->tools();

        $readyClients = count(array_filter($clients, fn(array $client): bool => !empty($client['enabled'])));
        $state = !empty($status['enabled']) ? 'ready' : 'off';
        $base = $this->wire('config')->urls->admin . 'setup/mcp-server/';
        $scopeCounts = array_fill_keys(['read', 'draft', 'publish', 'admin'], 0);
        foreach($tools as $tool) {
            $scope = (string) ($tool['scope'] ?? 'read');
            if(isset($scopeCounts[$scope])) $scopeCounts[$scope]++;
        }

        $metrics = $this->metric($this->_('Endpoint'), !empty($status['enabled']) ? $this->_('Enabled') : $this->_('Disabled'), $state)
            . $this->metric($this->_('Clients'), (string) $readyClients, $readyClients > 0 ? 'ready' : 'attention')
            . $this->metric($this->_('Providers'), (string) count($providers), 'ready')
            . $this->metric($this->_('Tools'), (string) count($tools), 'ready');

        $endpoint = $this->e($mcp->endpointUrl());
        $connection = "Name: {$mcp->serverName()} {$status['environment']}\nType: Streamable HTTP\nURL: {$mcp->endpointUrl()}\nBearer token env var: {$mcp->tokenEnvironmentVariable()}";

        $summary = '<div class="mcp-endpoint-summary" data-state="' . $state . '"><i aria-hidden="true"></i><div><strong>' . (!empty($status['enabled']) ? $this->_('Endpoint ready') : $this->_('Endpoint off')) . '</strong><small>' . $this->e(ucfirst((string) $status['environment'])) . ' · Streamable HTTP</small></div></div>';

        $out = $this->nav('overview')
            . $this->pageIntro($this->_('Central agent gateway'), $this->_('Connect approved AI clients to explicit ProcessWire tools without exposing generic server access.'), $summary)
            . '<section class="mcp-metrics">' . $metrics . '</section>'
            . '<div class="mcp-grid"><section class="mcp-panel"><header><div><p class="mcp-kicker">' . $this->_('Connection') . '</p><h3>' . $this->_('Streamable HTTP endpoint') . '</h3></div></header><dl class="mcp-facts"><div><dt>' . $this->_('Environment') . '</dt><dd>' . $this->e(ucfirst((string) $status['environment'])) . '</dd></div><div><dt>' . $this->_('URL') . '</dt><dd><code>' . $endpoint . '</code></dd></div><div><dt>' . $this->_('Authentication') . '</dt><dd>' . $this->_('Bearer token') . '</dd></div></dl><pre><code>' . $this->e($connection) . '</code></pre></section>'
            . '<section class="mcp-panel"><header><div><p class="mcp-kicker">' . $this->_('Safety model') . '</p><h3>' . $this->_('No generic server access') . '</h3></div></header><ul class="mcp-checks"><li>' . $this->_('No arbitrary shell, PHP, or SQL tools') . '</li><li>' . $this->_('Separate read, draft, publish, and admin scopes') . '</li><li>' . $this->_('Arguments are represented in audit logs only by a digest') . '</li><li>' . $this->_('Development and production use distinct URLs and credentials') . '</li></ul></section></div>'
            . '<section class="mcp-section"><header><div><p class="mcp-kicker">' . $this->_('Operating surface') . '</p><h2>' . $this->_('Choose the task, then review the evidence') . '</h2><p>' . $this->_('The overview stays compact; detailed provider, tool, credential, and audit records live in their dedicated workspaces.') . '</p></div></header><div class="mcp-action-grid">'
            . $this->actionCard('key', $this->_('Clients'), sprintf($this->_('%d active credentials'), $readyClients), $base . 'clients/')
            . $this->actionCard('plug', $this->_('Providers'), sprintf($this->_('%d integrations · %d tools'), count($providers), count($tools)), $base . 'providers/')
            . $this->actionCard('terminal', 'CLI', $this->_('Commands and local automation'), $base . 'cli/')
            . $this->actionCard('history', $this->_('Audit'), $this->_('Recent calls and outcomes'), $base . 'audit/')
            . $this->actionCard('book', $this->_('Documentation'), $this->_('Connection, CLI, and module guide'), $base . 'documentation/')
            . '</div></section>'
            . '<section class="mcp-panel"><header><div><p class="mcp-kicker">' . $this->_('Authority map') . '</p><h2>' . $this->_('Tools by required scope') . '</h2><p>' . $this->_('Higher scopes include lower ones, but every tool remains bound to its own declared minimum.') . '</p></div><a class="uk-button uk-button-default" href="' . $this->e($base . 'providers/') . '">' . $this->_('View tool registry') . '</a></header><div class="mcp-scope-summary">'
            . $this->scopeMetric('read', $scopeCounts['read'])
            . $this->scopeMetric('draft', $scopeCounts['draft'])
            . $this->scopeMetric('publish', $scopeCounts['publish'])
            . $this->scopeMetric('admin', $scopeCounts['admin'])
            . '</div></section>';
        return $this->workspace($out);
    }

}
