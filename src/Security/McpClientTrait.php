<?php namespace ProcessWire;

/**
 * Client credentials, scope authorization, rate limiting, and durable storage.
 */
trait McpClientTrait {

    public function issueClient(string $label, array $scopes = ['read'], ?int $expiresAt = null): array {
        $label = trim($this->wire('sanitizer')->text($label));
        if($label === '') throw new WireException('Client label is required.');

        $scopes = $this->normaliseScopes($scopes);
        $raw = $this->namespacePrefix() . '_mcp_' . $this->base64Url(random_bytes(32));
        $id = bin2hex(random_bytes(8));
        $now = time();
        $client = [
            'id' => $id,
            'label' => $label,
            'prefix' => substr($raw, 0, min(48, strlen($this->namespacePrefix()) + 13)),
            'token_hash' => $this->hashCredential($raw),
            'token_hash_version' => 2,
            'scopes' => $scopes,
            'enabled' => true,
            'created_at' => $now,
            'expires_at' => $expiresAt && $expiresAt > $now ? $expiresAt : null,
            'last_used_at' => null,
        ];

        $clients = $this->clientRecords();
        $clients[$id] = $client;
        $this->saveClientRecords($clients);

        $public = $this->publicClient($client);
        $public['token'] = $raw;
        return $public;
    }

    public function revokeClient(string $id): bool {
        $clients = $this->clientRecords();
        if(!isset($clients[$id])) return false;
        $clients[$id]['enabled'] = false;
        $clients[$id]['revoked_at'] = time();
        $this->saveClientRecords($clients);
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function clients(): array {
        return array_values(array_map(fn(array $client): array => $this->publicClient($client), $this->clientRecords()));
    }

    /** @return array<int,array<string,mixed>> */

    private function authenticate(string $authorization): ?array {
        if(!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) return null;
        $raw = trim($matches[1]);
        if(strlen($raw) < 32 || strlen($raw) > 256) return null;

        $clients = $this->clientRecords();
        foreach($clients as $id => $client) {
            if(empty($client['enabled']) || empty($client['token_hash'])) continue;
            if(!empty($client['expires_at']) && (int) $client['expires_at'] <= time()) continue;
            $hashVersion = (int) ($client['token_hash_version'] ?? 1);
            try {
                $valid = $hashVersion >= 2
                    ? password_verify($this->credentialMaterial($raw), (string) $client['token_hash'])
                    : password_verify($raw, (string) $client['token_hash']);
            } catch(\Throwable $e) {
                $this->wire('log')->save('mcp-server', 'Credential verification failed closed: ' . $e->getMessage());
                return null;
            }
            if(!$valid) continue;

            // A legacy password hash cannot be migrated until its client next
            // presents the raw token. Upgrade it opportunistically at login.
            if($hashVersion < 2) {
                $clients[$id]['token_hash'] = $this->hashCredential($raw);
                $clients[$id]['token_hash_version'] = 2;
            }
            $clients[$id]['last_used_at'] = time();
            $this->saveClientRecords($clients);
            return $clients[$id];
        }
        return null;
    }

    /** @param array<string,mixed> $client */
    private function clientHasScope(array $client, string $required): bool {
        $rank = ['read' => 10, 'draft' => 20, 'publish' => 30, 'admin' => 40];
        $max = 0;
        foreach((array) ($client['scopes'] ?? []) as $scope) $max = max($max, $rank[(string) $scope] ?? 0);
        return $max >= ($rank[$required] ?? 999);
    }

    private function consumeRateLimit(string $clientId): bool {
        $limit = max(1, min(600, (int) $this->rate_limit_per_minute));
        $bucket = gmdate('YmdHi');
        $key = 'mcp-rate-' . hash('sha256', $clientId . ':' . $bucket);
        $count = (int) $this->wire('cache')->get($key);
        if($count >= $limit) return false;
        $this->wire('cache')->save($key, $count + 1, 90);
        return true;
    }

    /** @return array{0:object,1:string,2:bool} */

    private function installClientTable(): void {
        $database = $this->wire('database');
        $database->exec(
            'CREATE TABLE IF NOT EXISTS mcp_server_clients ('
            . 'id VARCHAR(32) NOT NULL,'
            . 'label VARCHAR(191) NOT NULL,'
            . 'prefix VARCHAR(64) NOT NULL,'
            . 'token_hash VARCHAR(255) NOT NULL,'
            . 'token_hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'scopes_json VARCHAR(255) NOT NULL,'
            . 'enabled TINYINT(1) NOT NULL DEFAULT 1,'
            . 'created_at INT UNSIGNED NOT NULL,'
            . 'expires_at INT UNSIGNED NULL,'
            . 'last_used_at INT UNSIGNED NULL,'
            . 'revoked_at INT UNSIGNED NULL,'
            . 'PRIMARY KEY (id),'
            . 'KEY enabled_expires (enabled, expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $columns = $database->query('SHOW COLUMNS FROM mcp_server_clients')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if(!in_array('token_hash_version', $columns, true)) {
            $database->exec('ALTER TABLE mcp_server_clients ADD token_hash_version TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER token_hash');
        }
        $database->exec('ALTER TABLE mcp_server_clients MODIFY prefix VARCHAR(64) NOT NULL');
    }

    /** @return array<string,array<string,mixed>> */
    private function clientRecords(): array {
        try {
            $statement = $this->wire('database')->query(
                'SELECT id, label, prefix, token_hash, token_hash_version, scopes_json, enabled, created_at, expires_at, last_used_at, revoked_at '
                . 'FROM mcp_server_clients ORDER BY created_at, id'
            );
            $normalised = [];
            foreach($statement->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $record) {
                $record['scopes'] = json_decode((string) $record['scopes_json'], true) ?: ['read'];
                unset($record['scopes_json']);
                $record['enabled'] = (bool) $record['enabled'];
                $record['token_hash_version'] = (int) ($record['token_hash_version'] ?? 1);
                foreach(['created_at', 'expires_at', 'last_used_at', 'revoked_at'] as $field) {
                    $record[$field] = $record[$field] === null ? null : (int) $record[$field];
                }
                $normalised[(string) $record['id']] = $record;
            }
            if($normalised !== []) return $normalised;

            // One-time migration from the pre-table development build.
            $legacy = is_array($this->clients) ? $this->clients : [];
            if($legacy !== []) {
                $this->saveClientRecords($legacy);
                $config = $this->wire('modules')->getConfig($this);
                unset($config['clients']);
                $this->wire('modules')->saveConfig($this, $config);
                $this->clients = [];
                return $legacy;
            }
            return [];
        } catch(\Throwable $e) {
            $this->wire('log')->save('mcp-server', 'Client registry read failed: ' . $e->getMessage());
            $records = is_array($this->clients) ? $this->clients : [];
            $normalised = [];
            foreach($records as $record) {
                if(!is_array($record) || empty($record['id'])) continue;
                $normalised[(string) $record['id']] = $record;
            }
            return $normalised;
        }
    }

    /** @param array<string,array<string,mixed>> $clients */
    private function saveClientRecords(array $clients): void {
        $statement = $this->wire('database')->prepare(
            'INSERT INTO mcp_server_clients '
            . '(id, label, prefix, token_hash, token_hash_version, scopes_json, enabled, created_at, expires_at, last_used_at, revoked_at) '
            . 'VALUES (:id, :label, :prefix, :token_hash, :token_hash_version, :scopes_json, :enabled, :created_at, :expires_at, :last_used_at, :revoked_at) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label), prefix=VALUES(prefix), token_hash=VALUES(token_hash), token_hash_version=VALUES(token_hash_version), '
            . 'scopes_json=VALUES(scopes_json), enabled=VALUES(enabled), expires_at=VALUES(expires_at), '
            . 'last_used_at=VALUES(last_used_at), revoked_at=VALUES(revoked_at)'
        );
        foreach($clients as $client) {
            if(!is_array($client) || empty($client['id']) || empty($client['token_hash'])) continue;
            $statement->execute([
                ':id' => substr((string) $client['id'], 0, 32),
                ':label' => substr((string) ($client['label'] ?? ''), 0, 191),
                ':prefix' => substr((string) ($client['prefix'] ?? ''), 0, 64),
                ':token_hash' => substr((string) $client['token_hash'], 0, 255),
                ':token_hash_version' => (int) ($client['token_hash_version'] ?? 1),
                ':scopes_json' => json_encode($this->normaliseScopes((array) ($client['scopes'] ?? [])), JSON_THROW_ON_ERROR),
                ':enabled' => !empty($client['enabled']) ? 1 : 0,
                ':created_at' => (int) ($client['created_at'] ?? time()),
                ':expires_at' => !empty($client['expires_at']) ? (int) $client['expires_at'] : null,
                ':last_used_at' => !empty($client['last_used_at']) ? (int) $client['last_used_at'] : null,
                ':revoked_at' => !empty($client['revoked_at']) ? (int) $client['revoked_at'] : null,
            ]);
        }
        $this->clients = $clients;
    }

    /** @param array<string,mixed> $client @return array<string,mixed> */
    private function publicClient(array $client): array {
        unset($client['token_hash'], $client['token_hash_version']);
        return $client;
    }

    private function hashCredential(string $raw): string {
        $hash = password_hash($this->credentialMaterial($raw), PASSWORD_DEFAULT);
        if(!is_string($hash) || $hash === '') throw new WireException('Bearer credential hashing failed.');
        return $hash;
    }

    private function credentialMaterial(string $raw): string {
        $config = $this->wire('config');
        $pepper = trim((string) $config->get('userAuthSalt'));
        if(strlen($pepper) < 16) {
            throw new WireException('Set a strong $config->userAuthSalt in config.php before issuing credentials.');
        }
        return hash_hmac('sha256', $raw, $pepper);
    }

    /** @param string[] $scopes @return string[] */
    private function normaliseScopes(array $scopes): array {
        $allowed = ['read', 'draft', 'publish', 'admin'];
        $scopes = array_values(array_unique(array_intersect($allowed, array_map('strtolower', array_map('strval', $scopes)))));
        return $scopes ?: ['read'];
    }

    /** @return string[] */
}
