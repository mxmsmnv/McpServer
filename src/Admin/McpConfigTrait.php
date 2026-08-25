<?php namespace ProcessWire;

/**
 * ProcessWire module configuration fields and operational overview.
 */
trait McpConfigTrait {

    public function getModuleConfigInputfields(InputfieldWrapper $inputfields): InputfieldWrapper {
        $modules = $this->wire('modules');
        $config = $this->wire('config');
        $adminTheme = $this->wire('adminTheme');
        if($adminTheme) $adminTheme->addBodyClass('ProcessMcpServerConfig');
        $config->styles->add($config->urls->siteModules . 'McpServer/src/Admin/admin.css?v=' . self::VERSION);

        /** @var InputfieldMarkup $overview */
        $overview = $modules->get('InputfieldMarkup');
        $overview->name = 'mcp_config_overview';
        $overview->label = $this->_('MCP configuration');
        $overview->icon = 'plug';
        $overview->addClass('McpConfigOverview', 'wrapClass');
        $overview->value = $this->renderConfigOverview();
        $inputfields->add($overview);

        /** @var InputfieldFieldset $identity */
        $identity = $modules->get('InputfieldFieldset');
        $identity->name = 'mcp_config_identity';
        $identity->label = $this->_('Installation identity');
        $identity->description = $this->_('Give this ProcessWire installation a stable namespace so clients can distinguish its tools and credentials.');
        $identity->icon = 'tag';
        $identity->collapsed = Inputfield::collapsedNo;
        $identity->addClass('McpConfigSection McpConfigSection--expanded', 'wrapClass');

        /** @var InputfieldText $namespace */
        $namespace = $modules->get('InputfieldText');
        $namespace->name = 'namespace_prefix';
        $namespace->label = $this->_('Namespace prefix');
        $namespace->value = $this->namespacePrefix();
        $namespace->required = true;
        $namespace->pattern = '[a-z][a-z0-9_]{1,23}';
        $namespace->maxlength = 24;
        $namespace->description = $this->_('Prefixes every exposed tool and every newly issued bearer token.');
        $namespace->notes = $this->_('Use 2–24 lowercase letters, numbers, and underscores. Changing it renames tools, but existing client tokens remain valid.');
        $namespace->icon = 'code';
        $namespace->columnWidth = 50;
        $identity->add($namespace);

        /** @var InputfieldSelect $environment */
        $environment = $modules->get('InputfieldSelect');
        $environment->name = 'environment_mode';
        $environment->label = $this->_('Installation environment');
        $environment->addOptions([
            'auto' => $this->_('Auto-detect from hostname'),
            'development' => $this->_('Development'),
            'staging' => $this->_('Staging'),
            'production' => $this->_('Production'),
        ]);
        $environment->value = $this->environmentMode();
        $environment->description = $this->_('Controls environment labels, connection guidance, and the suggested bearer-token variable name.');
        $environment->notes = $this->_('Choose an explicit value for production and managed environments. Auto recognises local, test, development, and staging hostnames; everything else is treated as production.');
        $environment->icon = 'map-marker';
        $environment->columnWidth = 50;
        $identity->add($environment);
        $inputfields->add($identity);

        /** @var InputfieldFieldset $endpoint */
        $endpoint = $modules->get('InputfieldFieldset');
        $endpoint->name = 'mcp_config_endpoint';
        $endpoint->label = $this->_('Endpoint policy');
        $endpoint->description = $this->_('Control whether the remote gateway is available and bound the traffic accepted from each client.');
        $endpoint->icon = 'exchange';
        $endpoint->collapsed = Inputfield::collapsedNo;
        $endpoint->addClass('McpConfigSection McpConfigSection--expanded', 'wrapClass');

        /** @var InputfieldCheckbox $enabled */
        $enabled = $modules->get('InputfieldCheckbox');
        $enabled->name = 'enabled';
        $enabled->label = $this->_('Enable remote MCP endpoint');
        $enabled->description = $this->_('Accept authenticated Streamable HTTP requests at the configured path.');
        $enabled->notes = $this->_('Keep disabled until allowed hosts, client scopes, providers, and environment-specific routing have been reviewed.');
        $enabled->icon = 'power-off';
        $enabled->value = 1;
        $enabled->checked = (bool) $this->enabled;
        $endpoint->add($enabled);

        /** @var InputfieldURL $baseUrl */
        $baseUrl = $modules->get('InputfieldURL');
        $baseUrl->name = 'public_base_url';
        $baseUrl->label = $this->_('Public site URL');
        $baseUrl->value = trim((string) $this->public_base_url);
        $baseUrl->description = $this->_('Optional canonical HTTPS origin used by CLI output and connection guidance behind proxies.');
        $baseUrl->notes = $this->_('Leave blank to derive it from ProcessWire. Remote non-local hosts are reported as HTTPS when no web request is available.');
        $baseUrl->icon = 'globe';
        $baseUrl->placeholder = 'https://example.com';
        $baseUrl->columnWidth = 50;
        $endpoint->add($baseUrl);

        /** @var InputfieldText $path */
        $path = $modules->get('InputfieldText');
        $path->name = 'endpoint_path';
        $path->label = $this->_('Endpoint path');
        $path->value = $this->normaliseEndpointPath((string) $this->endpoint_path);
        $path->required = true;
        $path->description = $this->_('A dedicated path such as /mcp/. Do not reuse a public content route.');
        $path->notes = $this->_('Begin and end the path with a slash.');
        $path->icon = 'link';
        $path->columnWidth = 50;
        $endpoint->add($path);

        /** @var InputfieldInteger $rate */
        $rate = $modules->get('InputfieldInteger');
        $rate->name = 'rate_limit_per_minute';
        $rate->label = $this->_('Requests per client per minute');
        $rate->description = $this->_('Bound traffic independently for every bearer credential.');
        $rate->notes = $this->_('Enter a value from 1 to 600.');
        $rate->icon = 'tachometer';
        $rate->value = max(1, min(600, (int) $this->rate_limit_per_minute));
        $rate->min = 1;
        $rate->max = 600;
        $rate->columnWidth = 50;
        $endpoint->add($rate);
        $inputfields->add($endpoint);

        /** @var InputfieldFieldset $network */
        $network = $modules->get('InputfieldFieldset');
        $network->name = 'mcp_config_network';
        $network->label = $this->_('Network boundary');
        $network->description = $this->_('Restrict accepted Host and browser Origin headers without affecting native MCP clients.');
        $network->icon = 'shield';
        $network->collapsed = Inputfield::collapsedNo;
        $network->addClass('McpConfigSection McpConfigSection--expanded', 'wrapClass');

        /** @var InputfieldTextarea $hosts */
        $hosts = $modules->get('InputfieldTextarea');
        $hosts->name = 'allowed_hosts';
        $hosts->label = $this->_('Allowed hosts');
        $hosts->value = (string) $this->allowed_hosts;
        $hosts->rows = 4;
        $hosts->description = $this->_('One hostname per line. The current request host is always included.');
        $hosts->notes = $this->_('Use hostnames only, optionally with a port. Do not include a scheme or path.');
        $hosts->icon = 'server';
        $hosts->columnWidth = 50;
        $network->add($hosts);

        /** @var InputfieldTextarea $origins */
        $origins = $modules->get('InputfieldTextarea');
        $origins->name = 'allowed_origins';
        $origins->label = $this->_('Allowed browser origins');
        $origins->value = (string) $this->allowed_origins;
        $origins->rows = 4;
        $origins->description = $this->_('Optional, one complete HTTPS origin per line. Native MCP clients normally send no Origin header.');
        $origins->notes = $this->_('Leave blank unless a browser-based MCP client requires cross-origin access.');
        $origins->icon = 'globe';
        $origins->columnWidth = 50;
        $network->add($origins);
        $inputfields->add($network);

        /** @var InputfieldFieldset $local */
        $local = $modules->get('InputfieldFieldset');
        $local->name = 'mcp_config_local';
        $local->label = $this->_('Local administration');
        $local->description = $this->_('Enable the bounded JSON command-line interface for operators and local automation.');
        $local->icon = 'terminal';
        $local->collapsed = Inputfield::collapsedNo;
        $local->addClass('McpConfigSection McpConfigSection--expanded', 'wrapClass');

        /** @var InputfieldCheckbox $cli */
        $cli = $modules->get('InputfieldCheckbox');
        $cli->name = 'enable_cli';
        $cli->label = $this->_('Enable local MCP CLI');
        $cli->description = $this->_('Allow status inspection and client credential administration from the server command line.');
        $cli->notes = $this->_('The CLI never exposes stored hashes. Creating or revoking a client requires the explicit --execute flag.');
        $cli->icon = 'terminal';
        $cli->value = 1;
        $cli->checked = (bool) $this->enable_cli;
        $local->add($cli);

        /** @var InputfieldMarkup $cliCommand */
        $cliCommand = $modules->get('InputfieldMarkup');
        $cliCommand->name = 'mcp_cli_command';
        $cliCommand->label = $this->_('Command');
        $cliCommand->value = '<code>php site/modules/McpServer/bin/mcp-server status</code>';
        $cliCommand->notes = $this->_('Run from the ProcessWire root. Use the help command to see every safe operation.');
        $local->add($cliCommand);
        $inputfields->add($local);

        return $inputfields;
    }

    private function renderConfigOverview(): string {
        $e = fn(string $value): string => $this->wire('sanitizer')->entities($value);
        $admin = rtrim((string) $this->wire('config')->urls->admin, '/') . '/';
        $workspace = $admin . 'setup/mcp-server/';
        $clients = $this->clients();
        $providers = $this->providers();
        $tools = $this->tools();
        $activeClients = count(array_filter($clients, fn(array $client): bool => !empty($client['enabled'])));
        $readyProviders = count(array_filter($providers, fn(array $provider): bool => ($provider['status'] ?? '') === 'ready'));
        $environment = ucfirst($this->environmentName());
        $environmentSource = $this->environmentMode() === 'auto' ? $this->_('auto-detected') : $this->_('explicit');
        $endpointState = (bool) $this->enabled ? 'active' : 'off';

        $status = static function(string $label, string $value, string $state) use ($e): string {
            return '<div class="McpConfigStatus" data-state="' . $e($state) . '"><span class="McpConfigStatus__dot" aria-hidden="true"></span><div><small>'
                . $e($label) . '</small><strong>' . $e($value) . '</strong></div></div>';
        };

        return '<div class="McpConfigIntro"><p>'
            . $this->_('Configure the transport boundary here. Client credentials, provider readiness, tool visibility, and audit evidence remain in the dedicated MCP workspace.')
            . '</p><div class="McpConfigMeta"><span><i class="fa fa-tag" aria-hidden="true"></i> <code>' . $e($this->namespacePrefix()) . '</code></span><span><i class="fa fa-map-marker" aria-hidden="true"></i> ' . $e($environment) . ' · ' . $e($environmentSource) . '</span><span><i class="fa fa-link" aria-hidden="true"></i> <code>' . $e($this->endpointUrl()) . '</code></span></div>'
            . '<div class="McpConfigStatusGrid" aria-label="' . $e($this->_('Current MCP status')) . '">'
            . $status($this->_('Endpoint'), (bool) $this->enabled ? $this->_('Enabled') : $this->_('Disabled'), $endpointState)
            . $status($this->_('Clients'), (string) $activeClients . ' ' . $this->_('active'), $activeClients > 0 ? 'active' : 'attention')
            . $status($this->_('Providers'), (string) $readyProviders . ' / ' . (string) count($providers) . ' ' . $this->_('ready'), $readyProviders === count($providers) ? 'active' : 'attention')
            . $status($this->_('Tools'), (string) count($tools) . ' ' . $this->_('available'), count($tools) > 0 ? 'active' : 'attention')
            . $status($this->_('Local CLI'), (bool) $this->enable_cli ? $this->_('Enabled') : $this->_('Disabled'), (bool) $this->enable_cli ? 'active' : 'off')
            . '</div><nav class="McpConfigNav" aria-label="' . $e($this->_('MCP configuration shortcuts')) . '">'
            . '<a href="#wrap_Inputfield_mcp_config_identity"><i class="fa fa-tag" aria-hidden="true"></i> ' . $this->_('Installation identity') . '</a>'
            . '<a href="#wrap_Inputfield_mcp_config_endpoint"><i class="fa fa-exchange" aria-hidden="true"></i> ' . $this->_('Endpoint policy') . '</a>'
            . '<a href="#wrap_Inputfield_mcp_config_network"><i class="fa fa-shield" aria-hidden="true"></i> ' . $this->_('Network boundary') . '</a>'
            . '<a href="#wrap_Inputfield_mcp_config_local"><i class="fa fa-terminal" aria-hidden="true"></i> ' . $this->_('Local administration') . '</a>'
            . '<a href="' . $e($workspace . 'cli/') . '"><i class="fa fa-book" aria-hidden="true"></i> ' . $this->_('CLI command guide') . '</a>'
            . '<a href="' . $e($workspace) . '"><i class="fa fa-columns" aria-hidden="true"></i> ' . $this->_('Open workspace') . '</a>'
            . '<a href="' . $e($workspace . 'clients/') . '"><i class="fa fa-key" aria-hidden="true"></i> ' . $this->_('Manage clients') . '</a>'
            . '<a href="' . $e($workspace . 'audit/') . '"><i class="fa fa-history" aria-hidden="true"></i> ' . $this->_('Review audit') . '</a>'
            . '</nav></div>';
    }

}
