<?php namespace ProcessWire;

/**
 * Client credentials, scope authorization, rate limiting, and durable storage.
 */
trait McpClientTrait {

    /**
     * Return the authenticated identity for the currently executing MCP request.
     *
     * Provider modules may use this request-scoped projection to bind domain
     * principals to a gateway client without accepting an impersonable identity
     * in tool arguments. Credential material and token hashes are never exposed.
     * Calls outside an authenticated MCP request return null.
     *
     * @return array{id:string,namespace:string,scopes:array<int,string>}|null
     */
    public function requestClientContext(): ?array {
        if(!is_array($this->activeClient) || empty($this->activeClient['id'])) return null;
        return [
            'id' => (string) $this->activeClient['id'],
            'namespace' => $this->namespacePrefix(),
            'scopes' => $this->normaliseScopes((array) ($this->activeClient['scopes'] ?? [])),
        ];
    }

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

        $this->insertClientRecord($client);

        $public = $this->publicClient($client);
        $public['token'] = $raw;
        return $public;
    }

    public function revokeClient(string $id): bool {
        $statement = $this->wire('database')->prepare(
            'UPDATE mcp_server_clients SET enabled=0, revoked_at=:now WHERE id=:id'
        );
        if(!$statement->execute([':now' => time(), ':id' => $id])) return false;
        if($statement->rowCount() > 0) return true;
        // Preserve idempotent success for an already revoked, existing client.
        $exists = $this->wire('database')->prepare('SELECT id FROM mcp_server_clients WHERE id=:id');
        $exists->execute([':id' => $id]);
        return $exists->fetchColumn() !== false;
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
            // The display prefix selects candidates; only the full credential
            // hash authenticates them. Empty legacy prefixes still fall back to
            // verification, and collisions must not prevent later matches.
            $prefix = (string) ($client['prefix'] ?? '');
            if($prefix !== '' && !str_starts_with($raw, $prefix)) continue;
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

            // Never write a registry snapshot: usage/hash migration must not
            // overwrite a concurrent revocation, scope change or expiry change.
            $expectedHash = (string) $client['token_hash'];
            $newHash = $hashVersion < 2 ? $this->hashCredential($raw) : $expectedHash;
            try {
                $statement = $this->wire('database')->prepare(
                    'UPDATE mcp_server_clients SET last_used_at=:now'
                    . ($hashVersion < 2 ? ', token_hash=:new_hash, token_hash_version=2' : '')
                    . ' WHERE id=:id AND token_hash=:old_hash AND enabled=1'
                    . ' AND (expires_at IS NULL OR expires_at>:expires_now)'
                );
                $params = [':now' => time(), ':expires_now' => time(), ':id' => $id, ':old_hash' => $expectedHash];
                if($hashVersion < 2) $params[':new_hash'] = $newHash;
                if(!$statement->execute($params)) return null;
            } catch(\Throwable) {
                $this->wire('log')->save('mcp-server', 'Credential usage update failed closed.');
                return null;
            }
            $client['token_hash'] = $newHash;
            return $this->refreshClientAuthorization($client);
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

    /** Return current policy only while the verified credential is still valid. */
    private function refreshClientAuthorization(array $verified): ?array {
        $current = $this->clientRecords()[(string) ($verified['id'] ?? '')] ?? null;
        if(!$current || empty($current['enabled']) || !empty($current['revoked_at'])
            || (!empty($current['expires_at']) && (int) $current['expires_at'] <= time())
            || !hash_equals((string) ($verified['token_hash'] ?? ''), (string) ($current['token_hash'] ?? ''))) return null;
        return $current;
    }

    private function consumeRateLimit(string $clientId): bool {
        return $this->consumeLimitBucket('client:' . $clientId, max(1, min(600, (int) $this->rate_limit_per_minute)));
    }

    /** Bound expensive credential checks, including invalid credentials. */
    private function consumeAuthenticationLimit(string $address): bool {
        // REMOTE_ADDR only; arbitrary forwarded headers must not select buckets.
        // The global cap also bounds the number of attacker-created IP buckets.
        return $this->consumeLimitBucket('auth:global', 600)
            && $this->consumeLimitBucket('auth:ip:' . $address, 120);
    }

    private function consumeLimitBucket(string $identity, int $limit): bool {
        try {
            $database = $this->wire('database');
            $now = time();
            $key = hash('sha256', $identity . ':' . intdiv($now, 60));
            $cleanup = $database->prepare('DELETE FROM mcp_server_limits WHERE expires_at<:now LIMIT 1000');
            $cleanup->execute([':now' => $now]);
            $insert = $database->prepare('INSERT IGNORE INTO mcp_server_limits (bucket_key, hits, expires_at) VALUES (:key, 0, :expires)');
            $insert->execute([':key' => $key, ':expires' => (intdiv($now, 60) + 2) * 60]);
            // A single conditional UPDATE serializes admission for this bucket.
            $update = $database->prepare('UPDATE mcp_server_limits SET hits=hits+1 WHERE bucket_key=:key AND hits<:maximum');
            $update->execute([':key' => $key, ':maximum' => $limit]);
            return $update->rowCount() === 1;
        } catch(\Throwable $e) {
            $this->wire('log')->save('mcp-server', 'Request admission storage unavailable.');
            return false;
        }
    }

    private function installLimitTable(): void {
        $this->wire('database')->exec(
            'CREATE TABLE IF NOT EXISTS mcp_server_limits ('
            . 'bucket_key CHAR(64) NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'expires_at INT UNSIGNED NOT NULL, PRIMARY KEY (bucket_key), KEY expires (expires_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=ascii'
        );
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
                foreach($legacy as $record) {
                    if(is_array($record) && !empty($record['id']) && !empty($record['token_hash'])) $this->insertClientRecord($record, true);
                }
                $config = $this->wire('modules')->getConfig($this);
                unset($config['clients']);
                $this->wire('modules')->saveConfig($this, $config);
                $this->clients = [];
                return $this->clientRecords();
            }
            return [];
        } catch(\Throwable $e) {
            $this->wire('log')->save('mcp-server', 'Client registry read failed: ' . $e->getMessage());
            return []; // Registry failures must never authenticate stale config.
        }
    }

    /** Insert only; legacy migration must never overwrite an existing policy. */
    private function insertClientRecord(array $client, bool $migration = false): void {
        $statement = $this->wire('database')->prepare(
            ($migration ? 'INSERT IGNORE' : 'INSERT') . ' INTO mcp_server_clients '
            . '(id, label, prefix, token_hash, token_hash_version, scopes_json, enabled, created_at, expires_at, last_used_at, revoked_at) '
            . 'VALUES (:id, :label, :prefix, :token_hash, :token_hash_version, :scopes_json, :enabled, :created_at, :expires_at, :last_used_at, :revoked_at)'
        );
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
