<?php namespace ProcessWire;

/**
 * Audit history workspace and pagination.
 */
trait ProcessMcpAuditTrait {

    public function ___executeAudit(): string {
        $this->headline($this->_('MCP Audit'));
        $requestedPage = max(1, (int) $this->wire('input')->get->int('audit_page'));
        $audit = $this->mcp()->auditPage($requestedPage, 25);
        $entries = $audit['entries'];
        $currentPage = (int) $audit['page'];
        $totalPages = (int) $audit['total_pages'];
        $totalEntries = (int) $audit['total'];
        $perPage = (int) $audit['per_page'];
        $rangeStart = $totalEntries > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
        $rangeEnd = min($totalEntries, $currentPage * $perPage);
        $clients = [];
        foreach($this->mcp()->clients() as $client) $clients[(string) $client['id']] = (string) $client['label'];
        $successful = 0;
        $duration = 0;
        $rows = '';
        foreach($entries as $entry) {
            $ok = (string) $entry['status'] === 'ok';
            if($ok) $successful++;
            $duration += (int) $entry['duration_ms'];
            $clientId = (string) $entry['client_id'];
            $clientLabel = $clients[$clientId] ?? $this->_('Unknown client');
            $rows .= '<tr><td><code>' . $this->e((string) $entry['request_id']) . '</code></td><td><strong>' . $this->e($clientLabel) . '</strong><small><code>' . $this->e($clientId) . '</code></small></td><td><strong>' . $this->e((string) $entry['tool_name']) . '</strong><small>' . $this->e((string) $entry['scope']) . '</small></td><td><span class="mcp-state mcp-state--' . ($ok ? 'ready' : 'attention') . '">' . $this->e((string) $entry['status']) . '</span></td><td>' . (int) $entry['duration_ms'] . ' ms</td><td>' . $this->e((string) $entry['created_at']) . '</td></tr>';
        }
        if($rows === '') $rows = '<tr><td colspan="6"><div class="mcp-empty"><strong>' . $this->_('No tool calls recorded') . '</strong><span>' . $this->_('Authenticated tool calls will appear here without raw argument values.') . '</span></div></td></tr>';

        $out = $this->nav('audit')
            . $this->pageIntro($this->_('Operational evidence'), $this->_('Review identity, tool, scope, result, and timing without retaining raw argument values.'), '<span class="mcp-state">' . sprintf($this->_('Page %d of %d'), $currentPage, $totalPages) . '</span>')
            . '<section class="mcp-metrics mcp-metrics--audit">'
            . $this->metric($this->_('Calls on page'), (string) count($entries), 'ready')
            . $this->metric($this->_('Successful'), (string) $successful, 'ready')
            . $this->metric($this->_('Errors'), (string) (count($entries) - $successful), count($entries) === $successful ? 'ready' : 'attention')
            . $this->metric($this->_('Average time'), count($entries) > 0 ? (string) round($duration / count($entries)) . ' ms' : '—', 'ready')
            . '</section>'
            . '<section class="mcp-panel mcp-table-panel"><header><div><p class="mcp-kicker">' . $this->_('Operational evidence') . '</p><h2>' . $this->_('Recent tool calls') . '</h2><p>' . $this->_('The log records identity, tool, scope, result, and timing. Arguments are stored only as a SHA-256 digest.') . '</p></div></header><div class="mcp-table-wrap"><table class="uk-table uk-table-divider"><thead><tr><th>' . $this->_('Request') . '</th><th>' . $this->_('Client') . '</th><th>' . $this->_('Tool') . '</th><th>' . $this->_('Result') . '</th><th>' . $this->_('Duration') . '</th><th>' . $this->_('Time') . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div><footer class="mcp-table-footer"><span>' . sprintf($this->_('Showing %1$d–%2$d of %3$d calls'), $rangeStart, $rangeEnd, $totalEntries) . '</span>' . $this->pagination($currentPage, $totalPages) . '</footer></section>';
        return $this->workspace($out);
    }


    private function metric(string $label, string $value, string $state): string {
        return '<article><span>' . $this->e($label) . '</span><strong>' . $this->e($value) . '</strong><i class="mcp-dot mcp-dot--' . $this->e($state) . '" aria-hidden="true"></i></article>';
    }

    private function pagination(int $currentPage, int $totalPages): string {
        if($totalPages <= 1) return '';

        $base = $this->wire('config')->urls->admin . 'setup/mcp-server/audit/?audit_page=';
        $pages = [1, $totalPages];
        for($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++) $pages[] = $page;
        $pages = array_values(array_unique($pages));
        sort($pages);

        $html = '<nav aria-label="' . $this->_('Audit pages') . '"><ul class="uk-pagination mcp-pagination">';
        if($currentPage > 1) {
            $html .= '<li><a href="' . $this->e($base . ($currentPage - 1)) . '" aria-label="' . $this->_('Previous page') . '"><span uk-pagination-previous></span></a></li>';
        }

        $previous = 0;
        foreach($pages as $page) {
            if($previous > 0 && $page > $previous + 1) $html .= '<li class="uk-disabled" aria-hidden="true"><span>…</span></li>';
            if($page === $currentPage) {
                $html .= '<li class="uk-active"><span aria-current="page">' . $page . '</span></li>';
            } else {
                $html .= '<li><a href="' . $this->e($base . $page) . '" aria-label="' . sprintf($this->_('Page %d'), $page) . '">' . $page . '</a></li>';
            }
            $previous = $page;
        }

        if($currentPage < $totalPages) {
            $html .= '<li><a href="' . $this->e($base . ($currentPage + 1)) . '" aria-label="' . $this->_('Next page') . '"><span uk-pagination-next></span></a></li>';
        }
        return $html . '</ul></nav>';
    }

}
