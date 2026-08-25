<?php namespace ProcessWire;

/**
 * Module defaults, installation, upgrade, and removal lifecycle.
 */
trait McpLifecycleTrait {

    public function __construct() {
        parent::__construct();
        $this->setArray([
            'enabled' => 0,
            'namespace_prefix' => '',
            'environment_mode' => 'auto',
            'public_base_url' => '',
            'endpoint_path' => self::DEFAULT_ENDPOINT,
            'allowed_origins' => '',
            'allowed_hosts' => '',
            'rate_limit_per_minute' => self::DEFAULT_RATE_LIMIT,
            'enable_cli' => 0,
            'clients' => [],
        ]);
    }

    public function init(): void {
        $this->addHookBefore('ProcessPageView::pageNotFound', $this, 'handleMcpEndpoint');
    }

    public function ___install(): void {
        $this->ensureStorageSchema();
    }

    public function ___upgrade($fromVersion, $toVersion): void {
        $this->ensureStorageSchema();
    }

    public function ensureStorageSchema(): void {
        $this->installClientTable();
        $this->installAuditTable();
        $sessionPath = $this->sessionPath();
        if(!is_dir($sessionPath)) $this->wire('files')->mkdir($sessionPath, true);
        $deny = $sessionPath . '/.htaccess';
        if(!is_file($deny)) file_put_contents($deny, "Require all denied\nDeny from all\n");
    }

    public function ___uninstall(): void {
        // Audit data and client identities are deliberately preserved. Removing
        // them requires an explicit operator decision outside module uninstall.
    }

}
