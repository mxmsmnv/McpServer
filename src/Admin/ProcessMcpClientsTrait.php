<?php namespace ProcessWire;

/**
 * Client credential administration workspace.
 */
trait ProcessMcpClientsTrait {

    public function ___executeClients(): string {
        $this->headline($this->_('MCP Clients'));
        $mcp = $this->mcp();
        $notice = '';

        if($this->wire('input')->requestMethod('POST')) {
            $this->wire('session')->CSRF->validate();
            $action = (string) $this->wire('input')->post->text('mcp_action');
            if($action === 'create') {
                $label = (string) $this->wire('input')->post->text('client_label');
                $scopes = (array) $this->wire('input')->post->scope;
                try {
                    $client = $mcp->issueClient($label, $scopes);
                    $notice = '<div class="uk-alert-success" uk-alert><p><strong>' . $this->_('Client created. Copy this token now; it will not be shown again.') . '</strong></p><div class="mcp-token"><code>' . $this->e((string) $client['token']) . '</code></div></div>';
                } catch(\Throwable $e) {
                    $notice = '<div class="uk-alert-danger" uk-alert><p>' . $this->e($e->getMessage()) . '</p></div>';
                }
            } elseif($action === 'revoke') {
                $id = (string) $this->wire('input')->post->text('client_id');
                $notice = $mcp->revokeClient($id)
                    ? '<div class="uk-alert-success" uk-alert><p>' . $this->_('Client revoked.') . '</p></div>'
                    : '<div class="uk-alert-warning" uk-alert><p>' . $this->_('Client was not found.') . '</p></div>';
            }
        }

        $tokenName = $this->wire('session')->CSRF->getTokenName();
        $tokenValue = $this->wire('session')->CSRF->getTokenValue();
        $rows = '';
        foreach($mcp->clients() as $client) {
            $enabled = !empty($client['enabled']);
            $rows .= '<tr><td><strong>' . $this->e((string) $client['label']) . '</strong><small>' . $this->e((string) $client['prefix']) . '…</small></td><td>' . $this->e(implode(', ', (array) ($client['scopes'] ?? []))) . '</td><td>' . $this->e(date('Y-m-d', (int) $client['created_at'])) . '</td><td>' . $this->e($client['last_used_at'] ? date('Y-m-d H:i', (int) $client['last_used_at']) : $this->_('Never')) . '</td><td><span class="mcp-state mcp-state--' . ($enabled ? 'ready' : 'off') . '">' . ($enabled ? $this->_('Active') : $this->_('Revoked')) . '</span></td><td>';
            if($enabled) $rows .= '<form method="post"><input type="hidden" name="' . $tokenName . '" value="' . $tokenValue . '"><input type="hidden" name="mcp_action" value="revoke"><input type="hidden" name="client_id" value="' . $this->e((string) $client['id']) . '"><button class="uk-button uk-button-default uk-button-small" type="submit">' . $this->_('Revoke') . '</button></form>';
            $rows .= '</td></tr>';
        }
        if($rows === '') $rows = '<tr><td colspan="6"><div class="mcp-empty"><strong>' . $this->_('No clients yet') . '</strong><span>' . $this->_('Create a separate credential for each computer or agent runtime.') . '</span></div></td></tr>';

        $scopeInputs = '';
        foreach(['read' => 'Read', 'draft' => 'Draft', 'publish' => 'Publish', 'admin' => 'Admin'] as $value => $label) {
            $scopeInputs .= '<label><input class="uk-checkbox" type="checkbox" name="scope[]" value="' . $value . '"' . ($value === 'read' ? ' checked' : '') . '> ' . $this->e($label) . '</label>';
        }

        $out = $this->nav('clients')
            . $this->pageIntro($this->_('Client identities'), $this->_('Issue one revocable credential per computer or agent runtime, with only the scopes it requires.'), '<span class="mcp-state mcp-state--ready">' . sprintf($this->_('%d active'), count(array_filter($mcp->clients(), fn(array $client): bool => !empty($client['enabled'])))) . '</span>')
            . $notice
            . '<aside class="mcp-advisory"><i class="fa fa-shield" aria-hidden="true"></i><div><strong>' . $this->_('Raw bearer tokens are never recoverable') . '</strong><p>' . $this->_('The token is shown once. Only a salted one-way hash protected by config.php is retained; revoke and replace a lost token.') . '</p></div></aside>'
            . '<section class="mcp-panel"><header><div><p class="mcp-kicker">' . $this->_('Client identities') . '</p><h2>' . $this->_('Issue one credential per computer') . '</h2><p>' . $this->_('Independent credentials make access revocable and audit history attributable without sharing one master secret.') . '</p></div></header><form method="post" class="mcp-client-form"><input type="hidden" name="' . $tokenName . '" value="' . $tokenValue . '"><input type="hidden" name="mcp_action" value="create"><label><span>' . $this->_('Client label') . '</span><input class="uk-input" type="text" name="client_label" required maxlength="120" placeholder="MacBook · Codex"></label><fieldset><legend>' . $this->_('Maximum scope') . '</legend><div class="mcp-scope-list">' . $scopeInputs . '</div></fieldset><button class="uk-button uk-button-primary" type="submit">' . $this->_('Create client') . '</button></form></section>'
            . '<section class="mcp-panel mcp-table-panel"><header><div><p class="mcp-kicker">' . $this->_('Access registry') . '</p><h2>' . $this->_('Known clients') . '</h2></div></header><div class="mcp-table-wrap"><table class="uk-table uk-table-divider"><thead><tr><th>' . $this->_('Client') . '</th><th>' . $this->_('Scopes') . '</th><th>' . $this->_('Created') . '</th><th>' . $this->_('Last used') . '</th><th>' . $this->_('State') . '</th><th><span class="uk-hidden">' . $this->_('Actions') . '</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        return $this->workspace($out);
    }

}
