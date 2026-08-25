<?php namespace ProcessWire;

/**
 * Installation identity, URLs, environment detection, and small format helpers.
 */
trait McpEnvironmentTrait {

    public function endpointUrl(): string {
        return rtrim($this->publicSiteUrl(), '/') . $this->normaliseEndpointPath((string) $this->endpoint_path);
    }

    public function publicSiteUrl(): string {
        $configured = rtrim(trim((string) $this->public_base_url), '/');
        if($configured !== '') {
            $parts = parse_url($configured);
            if(is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && !empty($parts['host'])) {
                return $configured . '/';
            }
        }

        $root = (string) $this->wire('config')->urls->httpRoot;
        $parts = parse_url($root);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $isCli = in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
        if($isCli && $scheme === 'http' && !$this->isLocalHostname($host)) {
            $root = preg_replace('#^http://#i', 'https://', $root) ?: $root;
        }
        return rtrim($root, '/') . '/';
    }

    public function namespacePrefix(): string {
        $configured = strtolower(trim((string) $this->namespace_prefix));
        if(preg_match('/^[a-z][a-z0-9_]{1,23}$/', $configured)) return $configured;

        $host = strtolower((string) parse_url($this->publicSiteUrl(), PHP_URL_HOST));
        $label = preg_replace('/[^a-z0-9]+/', '_', explode('.', $host ?: 'processwire', 2)[0]) ?: '';
        $label = trim($label, '_');
        if(!preg_match('/^[a-z]/', $label)) $label = 'site_' . $label;
        return substr($label !== '' ? $label : 'processwire', 0, 24);
    }

    public function serverName(): string {
        return strtoupper($this->namespacePrefix()) . ' MCP';
    }

    public function tokenEnvironmentVariable(): string {
        $environment = match($this->environmentName()) {
            'development' => '_DEV',
            'staging' => '_STAGING',
            default => '',
        };
        return strtoupper($this->namespacePrefix()) . $environment . '_MCP_BEARER_TOKEN';
    }

    /**
     * Issue a client credential. The raw bearer token is returned exactly once.
     *
     * @param string[] $scopes
     * @return array<string,mixed>
     */

    private function lineList(string $value): array {
        $items = preg_split('/[\r\n,]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }

    private function normaliseEndpointPath(string $path): string {
        $path = '/' . trim($path, '/') . '/';
        return $path === '//' ? self::DEFAULT_ENDPOINT : $path;
    }

    private function sessionPath(): string {
        return rtrim((string) $this->wire('config')->paths->assets, '/') . '/cache/mcp-server-sessions';
    }

    public function environmentMode(): string {
        $mode = strtolower(trim((string) $this->environment_mode));
        return in_array($mode, ['auto', 'development', 'staging', 'production'], true) ? $mode : 'auto';
    }

    public function environmentName(): string {
        $mode = $this->environmentMode();
        if($mode !== 'auto') return $mode;

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? parse_url($this->publicSiteUrl(), PHP_URL_HOST)));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        if($host === 'localhost' || str_ends_with($host, '.localhost') || preg_match('/\.(?:dev|test|local)$/', $host)) return 'development';
        if(preg_match('/(?:^|[.-])(?:staging|stage|preview|sandbox)(?:[.-]|$)/', $host)) return 'staging';
        return 'production';
    }

    private function isLocalHostname(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1'
            || str_ends_with($host, '.localhost') || preg_match('/\.(?:test|local)$/', $host) === 1;
    }

    private function serverInstructions(): string {
        return $this->serverName() . ' ' . $this->environmentName() . '. Use read tools freely within bounded limits. '
            . 'Treat draft and publish as separate operations. Never claim a mutation succeeded unless the tool result confirms it. '
            . 'Do not request arbitrary SQL, PHP, shell, credentials, unpublished private content, or personal data. '
            . 'Production writes require a client credential with the matching scope and must preserve auditability and idempotency.';
    }

    private function base64Url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param array<string,mixed> $payload */
    private function emitJson(int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
