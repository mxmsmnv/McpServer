<?php namespace ProcessWire;

/**
 * Bounded audit queries, protocol error classification, and persistence.
 */
trait McpAuditTrait {

    public function recentAudit(int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        return $this->auditEntries($limit, 0);
    }

    /** @return array{entries:array<int,array<string,mixed>>,page:int,per_page:int,total:int,total_pages:int} */
    public function auditPage(int $page = 1, int $perPage = 25): array {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $total = 0;

        try {
            $total = (int) $this->wire('database')->query('SELECT COUNT(*) FROM mcp_server_audit')->fetchColumn();
        } catch(\Throwable) {
            $total = 0;
        }

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'entries' => $this->auditEntries($perPage, $offset),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function auditEntries(int $limit, int $offset): array {
        try {
            $statement = $this->wire('database')->prepare(
                'SELECT request_id, client_id, tool_name, scope, status, duration_ms, created_at '
                . 'FROM mcp_server_audit ORDER BY id DESC LIMIT :limit OFFSET :offset'
            );
            $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch(\Throwable) {
            return [];
        }
    }


    private function responseRepresentsError(object $response): bool {
        if($response->getStatusCode() >= 400) return true;
        try {
            $decoded = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch(\Throwable) {
            return false;
        }
        $messages = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        foreach($messages as $message) {
            if(!is_array($message)) continue;
            if(isset($message['error']) || !empty($message['result']['isError'])) return true;
        }
        return false;
    }

    /** @return array<string,array<string,mixed>> */

    private function audit(string $tool, string $scope, string $status, int $durationMs, array $request): void {
        try {
            $requestId = (string) ($request['id'] ?? bin2hex(random_bytes(8)));
            $arguments = (array) ($request['params']['arguments'] ?? []);
            $digest = hash('sha256', json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            $statement = $this->wire('database')->prepare(
                'INSERT INTO mcp_server_audit '
                . '(request_id, client_id, tool_name, scope, arguments_digest, status, duration_ms, created_at) '
                . 'VALUES (:request_id, :client_id, :tool_name, :scope, :arguments_digest, :status, :duration_ms, :created_at)'
            );
            $statement->execute([
                ':request_id' => substr($requestId, 0, 191),
                ':client_id' => substr((string) ($this->activeClient['id'] ?? ''), 0, 64),
                ':tool_name' => substr($tool, 0, 128),
                ':scope' => substr($scope, 0, 32),
                ':arguments_digest' => $digest,
                ':status' => substr($status, 0, 32),
                ':duration_ms' => max(0, $durationMs),
                ':created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch(\Throwable $e) {
            $this->wire('log')->save('mcp-server', 'Audit write failed: ' . $e->getMessage());
        }
    }

    private function installAuditTable(): void {
        $this->wire('database')->exec(
            'CREATE TABLE IF NOT EXISTS mcp_server_audit ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'request_id VARCHAR(191) NOT NULL,'
            . 'client_id VARCHAR(64) NOT NULL DEFAULT \'\','
            . 'tool_name VARCHAR(128) NOT NULL,'
            . 'scope VARCHAR(32) NOT NULL,'
            . 'arguments_digest CHAR(64) NOT NULL,'
            . 'status VARCHAR(32) NOT NULL,'
            . 'duration_ms INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at DATETIME NOT NULL,'
            . 'PRIMARY KEY (id),'
            . 'KEY client_created (client_id, created_at),'
            . 'KEY tool_created (tool_name, created_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

}
