<?php namespace ProcessWire;

/**
 * Provider and tool inventory workspace.
 */
trait ProcessMcpProvidersTrait {

    public function ___executeProviders(): string {
        $this->headline($this->_('MCP Providers'));
        $mcp = $this->mcp();
        $providers = $mcp->providers();
        $tools = $mcp->tools();
        $scopeCounts = array_fill_keys(['read', 'draft', 'publish', 'admin'], 0);
        foreach($tools as $tool) {
            $scope = (string) ($tool['scope'] ?? 'read');
            if(isset($scopeCounts[$scope])) $scopeCounts[$scope]++;
        }
        $docsUrl = $this->wire('config')->urls->admin . 'setup/mcp-server/documentation/#module-integration';
        $out = $this->nav('providers')
            . $this->pageIntro($this->_('Module contract'), $this->_('Only installed modules with explicit MCP metadata and documented tool definitions are discoverable.'), '<div class="mcp-intro-actions"><div class="mcp-inline-stats"><span><strong>' . count($providers) . '</strong> ' . $this->_('providers') . '</span><span><strong>' . count($tools) . '</strong> ' . $this->_('tools') . '</span></div><a class="uk-button uk-button-default" href="' . $this->e($docsUrl) . '">' . $this->_('Integration guide') . '</a></div>')
            . '<section class="mcp-scope-summary mcp-scope-summary--standalone" aria-label="' . $this->_('Tools by required scope') . '">'
            . $this->scopeMetric('read', $scopeCounts['read'])
            . $this->scopeMetric('draft', $scopeCounts['draft'])
            . $this->scopeMetric('publish', $scopeCounts['publish'])
            . $this->scopeMetric('admin', $scopeCounts['admin'])
            . '</section>'
            . $this->providerTable($providers)
            . $this->toolTable($tools);
        return $this->workspace($out);
    }


    private function providerTable(array $providers): string {
        $cards = '';
        foreach($providers as $provider) {
            $status = (string) ($provider['status'] ?? 'error');
            $cards .= '<article class="mcp-provider"><div><span class="mcp-provider-icon"><i class="fa fa-plug" aria-hidden="true"></i></span><div><h3>' . $this->e((string) $provider['title']) . '</h3><p><code>' . $this->e((string) $provider['module']) . '</code>' . (!empty($provider['version']) ? ' · v' . $this->e((string) $provider['version']) : '') . '</p></div></div><footer><span>' . sprintf($this->_('%d tools'), (int) $provider['tool_count']) . '</span><span class="mcp-state mcp-state--' . ($status === 'ready' ? 'ready' : 'attention') . '">' . $this->e($status) . '</span></footer></article>';
        }
        return '<section class="mcp-section"><header><div><p class="mcp-kicker">' . $this->_('Providers') . '</p><h2>' . $this->_('Installed module integrations') . '</h2></div></header><div class="mcp-provider-grid">' . $cards . '</div></section>';
    }

    /** @param array<int,array<string,mixed>> $tools */
    private function toolTable(array $tools): string {
        $rows = '';
        foreach($tools as $tool) $rows .= '<tr><td><strong>' . $this->e((string) $tool['title']) . '</strong><small><code>' . $this->e((string) $tool['name']) . '</code></small></td><td>' . $this->e((string) $tool['provider']) . '</td><td><span class="mcp-scope mcp-scope--' . $this->e((string) $tool['scope']) . '">' . $this->e((string) $tool['scope']) . '</span></td><td>' . $this->e((string) $tool['description']) . '</td></tr>';
        return '<section class="mcp-panel mcp-table-panel"><header><div><p class="mcp-kicker">' . $this->_('Tool registry') . '</p><h2>' . $this->_('Capabilities visible to agents') . '</h2></div></header><div class="mcp-table-wrap"><table class="uk-table uk-table-divider"><thead><tr><th>' . $this->_('Tool') . '</th><th>' . $this->_('Provider') . '</th><th>' . $this->_('Scope') . '</th><th>' . $this->_('Purpose') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    }

}
