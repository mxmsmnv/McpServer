<?php namespace ProcessWire;

/**
 * Provider discovery, tool registration, and capability reporting.
 */
trait McpProviderRegistryTrait {

    public function providers(): array {
        $providers = [[
            'module' => self::class,
            'name' => $this->namespacePrefix() . '-core',
            'title' => $this->serverName() . ' Core',
            'version' => self::VERSION_STRING,
            'tool_count' => 2,
            'status' => 'ready',
        ]];

        foreach($this->providerModules() as $moduleName => $module) {
            try {
                $info = $module->mcpProviderInfo();
                $tools = $module->mcpTools();
                $providers[] = [
                    'module' => $moduleName,
                    'name' => (string) ($info['name'] ?? strtolower($moduleName)),
                    'title' => (string) ($info['title'] ?? $moduleName),
                    'version' => (string) ($info['version'] ?? ''),
                    'tool_count' => count($tools),
                    'status' => 'ready',
                ];
            } catch(\Throwable $e) {
                $providers[] = [
                    'module' => $moduleName,
                    'name' => strtolower($moduleName),
                    'title' => $moduleName,
                    'version' => '',
                    'tool_count' => 0,
                    'status' => 'error',
                ];
                $this->wire('log')->save('mcp-server', "Provider {$moduleName} failed discovery: {$e->getMessage()}");
            }
        }

        return $providers;
    }

    /** @return array<int,array<string,mixed>> */
    public function tools(): array {
        return array_values(array_map(function(array $tool): array {
            unset($tool['handler']);
            return $tool;
        }, $this->toolRegistry()));
    }

    /** @return array<string,mixed> */
    public function toolSystemStatus(): array {
        return [
            'service' => $this->namespacePrefix() . '-mcp',
            'version' => self::VERSION_STRING,
            'environment' => $this->environmentName(),
            'environment_mode' => $this->environmentMode(),
            'environment_source' => $this->environmentMode() === 'auto' ? 'hostname' : 'configuration',
            'site_url' => $this->publicSiteUrl(),
            'processwire' => (string) $this->wire('config')->version,
            'php' => PHP_VERSION,
            'enabled' => (bool) $this->enabled,
            'providers' => count($this->providers()),
            'tools' => count($this->toolRegistry()),
            'time' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function toolCapabilities(): array {
        return [
            'environment' => $this->environmentName(),
            'providers' => $this->providers(),
            'tools' => $this->tools(),
            'policy' => [
                'authentication' => 'bearer',
                'default_scope' => 'read',
                'arbitrary_php' => false,
                'arbitrary_sql' => false,
                'production_writes_require_scoped_client' => true,
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */

    private function toolRegistry(): array {
        if($this->toolRegistry !== null) return $this->toolRegistry;

        $registry = [];
        $core = [
            [
                'name' => 'system_status',
                'title' => $this->serverName() . ' system status',
                'description' => 'Read the current ProcessWire environment, runtime, provider, and tool status.',
                'handler' => [$this, 'toolSystemStatus'],
                'scope' => 'read',
            ],
            [
                'name' => 'mcp_capabilities',
                'title' => $this->serverName() . ' capabilities',
                'description' => 'List registered providers, tools, scopes, and server policy.',
                'handler' => [$this, 'toolCapabilities'],
                'scope' => 'read',
            ],
        ];

        foreach($core as $tool) $this->registerTool($registry, $tool, self::class);
        foreach($this->providerModules() as $moduleName => $module) {
            try {
                foreach($module->mcpTools() as $tool) $this->registerTool($registry, $tool, $moduleName);
            } catch(\Throwable $e) {
                $this->wire('log')->save('mcp-server', "Provider {$moduleName} tools failed: {$e->getMessage()}");
            }
        }

        return $this->toolRegistry = $registry;
    }

    /** @param array<string,array<string,mixed>> $registry @param array<string,mixed> $tool */
    private function registerTool(array &$registry, array $tool, string $provider): void {
        $localName = strtolower(trim((string) ($tool['name'] ?? '')));
        if(!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $localName)) throw new WireException("Invalid MCP tool name from {$provider}.");
        $namespace = $this->namespacePrefix() . '_';
        $name = str_starts_with($localName, $namespace) ? $localName : $namespace . $localName;
        if(strlen($name) > 64) throw new WireException("Namespaced MCP tool name is too long: {$name}.");
        if(isset($registry[$name])) throw new WireException("Duplicate MCP tool name: {$name}.");
        if(!isset($tool['handler']) || !is_callable($tool['handler'])) throw new WireException("MCP tool {$name} has no callable handler.");

        $scope = strtolower((string) ($tool['scope'] ?? 'read'));
        if(!in_array($scope, ['read', 'draft', 'publish', 'admin'], true)) throw new WireException("MCP tool {$name} has an invalid scope.");

        $registry[$name] = [
            'name' => $name,
            'title' => trim((string) ($tool['title'] ?? $name)),
            'description' => trim((string) ($tool['description'] ?? '')),
            'provider' => $provider,
            'handler' => $tool['handler'],
            'scope' => $scope,
            'read_only' => (bool) ($tool['read_only'] ?? $scope === 'read'),
            'destructive' => (bool) ($tool['destructive'] ?? false),
            'idempotent' => (bool) ($tool['idempotent'] ?? true),
            'open_world' => (bool) ($tool['open_world'] ?? false),
            'input_schema' => is_array($tool['input_schema'] ?? null) ? $tool['input_schema'] : ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
        ];
    }

    /** @return array<string,object> */
    private function providerModules(): array {
        $providers = [];
        foreach($this->wire('modules')->getModuleInfo('*') as $id => $info) {
            if(empty($info['mcpProvider'])) continue;
            $name = (string) ($info['name'] ?? $this->wire('modules')->getModuleClass($id));
            if($name === '' || $name === self::class) continue;
            try {
                $module = $this->wire('modules')->getModule($name, ['noInstall' => true, 'noThrow' => true]);
                if($module && method_exists($module, 'mcpProviderInfo') && method_exists($module, 'mcpTools')) $providers[$name] = $module;
            } catch(\Throwable) {
                continue;
            }
        }
        return $providers;
    }

    /** @return array<string,mixed>|null */
}
