<?php

declare(strict_types=1);

// Read-only checks of the real ProcessWire dispatcher and module permissions.
$options = getopt('', ['root:']);
$root = rtrim((string) ($options['root'] ?? ''), '/');
if(!is_file($root . '/wire/core/ProcessWire.php')) {
    fwrite(STDERR, "Usage: php tests/processwire-integration.php --root=/path/to/development-site\n");
    exit(2);
}
require $root . '/wire/core/ProcessWire.php';
$wire = new ProcessWire\ProcessWire(ProcessWire\ProcessWire::buildConfig($root));
$mcp = $wire->modules->getModule('McpServer', ['noPermissionCheck' => true]);
if(!$mcp || $mcp->environmentName() !== 'development') throw new RuntimeException('A development MCP Server installation is required.');
$passed = 0;
$check = static function(bool $ok, string $label) use (&$passed): void {
    if(!$ok) throw new RuntimeException('[FAIL] ' . $label);
    echo '[PASS] ', $label, PHP_EOL;
    $passed++;
};
$originalUser = $wire->user;
$wire->wire('breadcrumbs', new ProcessWire\Breadcrumbs());
$page = $wire->pages->get('template=admin, name=mcp-server');
$check((bool) $page->id, 'MCP admin page exists');
$wire->wire('page', $page);
try {
    $wire->users->setCurrentUser($wire->users->get($wire->config->superUserPageID));
    $process = $wire->modules->get('ProcessMcpServer');
    $dispatch = static function(string $method) use ($wire, $process): string {
        $controller = new ProcessWire\ProcessController();
        $wire->wire($controller);
        $controller->setProcess($process);
        $controller->setProcessMethodName($method);
        return $controller->execute();
    };
    $tools = $dispatch('executeTools');
    $check(str_contains($tools, 'Capabilities visible to agents') && str_contains($tools, '/setup/mcp-server/tools/'), 'ProcessWire dispatches executeTools to the new workspace');
    foreach($mcp->tools() as $tool) {
        $check(str_contains($tools, htmlspecialchars($tool['name'], ENT_QUOTES, 'UTF-8')), 'Tools lists registered tool ' . $tool['name']);
    }
    $providers = $dispatch('executeProviders');
    $check(str_contains($providers, 'Installed module integrations') && str_contains($providers, 'Capabilities visible to agents'), 'Providers retains its combined inventory');
    $docs = $dispatch('executeDocumentation');
    $check(str_contains($docs, 'Mcp\\Server\\RequestContext') && str_contains($docs, 'getRequest()-&gt;arguments'), 'operator guide renders the context-handler example');
    $wire->users->setCurrentUser($wire->users->getGuestUser());
    try {
        $dispatch('executeTools');
        $check(false, 'guest must not access Tools');
    } catch(ProcessWire\ProcessControllerPermissionException $e) {
        $check(true, 'ProcessWire denies guest access to Tools');
    }
    $member = null;
    foreach($wire->users->find('include=all, roles!=superuser, limit=50') as $candidate) {
        if(!$candidate->isGuest() && !$candidate->hasPermission('mcp-server-admin')) { $member = $candidate; break; }
    }
    if($member && $member->id) {
        $wire->users->setCurrentUser($member);
        try {
            $dispatch('executeTools');
            $check(false, 'member without permission must not access Tools');
        } catch(ProcessWire\ProcessControllerPermissionException $e) {
            $check(true, 'ProcessWire denies a member without mcp-server-admin access to Tools');
        }
    }
} finally {
    $wire->users->setCurrentUser($originalUser);
}
echo $passed, " ProcessWire integration checks passed. No users or content were changed.\n";
