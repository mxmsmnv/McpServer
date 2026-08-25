# MCP Server API

The gateway runs on the official PHP MCP SDK 0.8. Its Streamable HTTP endpoint
serves both the stateless MCP 2026-07-28 lifecycle and compatible legacy
handshake clients. Provider modules do not implement transport or protocol
negotiation; they publish only governed domain capabilities.

Release version: `1.0.0` (`100` in ProcessWire module metadata).

## Provider discovery

The gateway reads installed module metadata and loads only modules with
`mcpProvider => true`. The module must implement both methods below. This
duck-typed contract avoids making domain modules depend on the gateway merely
to remain installable.

### `mcpProviderInfo(): array`

Return:

```php
[
    'name' => 'jobboard',
    'title' => 'Job Board',
    'version' => '1.11.0',
]
```

### `mcpTools(): array`

Return a list of tool definitions:

```php
[
    [
        'name' => 'jobs_coverage',
        'title' => 'Jobs category coverage',
        'description' => 'Return bounded active-listing coverage by category.',
        'handler' => [$this, 'mcpJobsCoverage'],
        'scope' => 'read',
        'read_only' => true,
        'destructive' => false,
        'idempotent' => true,
        'open_world' => false,
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'additionalProperties' => false,
        ],
    ],
]
```

Provider-local tool names must match `^[a-z][a-z0-9_]{2,63}$`. The gateway
prepends the installation namespace and checks the resulting names for global
uniqueness. For example, `jobs_coverage` becomes
`example_jobs_coverage` when the configured namespace is `example`. Names
already beginning with the active namespace remain supported for compatibility.
Handlers should use named PHP parameters matching schema property names.

## Installation namespace

`namespace_prefix` is a per-installation value containing 2–24 lowercase
letters, numbers, or underscores and beginning with a letter. It identifies
the MCP server, core provider, exposed tools, and newly issued bearer tokens.
Changing it renames exposed tools and requires clients to refresh discovery;
existing bearer tokens remain valid.

## Installation environment

`environment_mode` accepts `auto`, `development`, `staging`, or `production`.
The resolved value is returned by `environmentName()` and appears in status,
connection guidance, and the suggested bearer-token environment variable.
Auto mode derives the value from the request host; production and managed
environments should set it explicitly.

## Credential storage

Client identities are durable rows in `mcp_server_clients`. A raw bearer token
is returned only by `issueClient()` and is never stored. The table retains a
non-secret display prefix and a salted password hash of an HMAC derived with
ProcessWire's installation-specific `$config->userAuthSalt`. Legacy hashes are
upgraded after their next successful
authentication, when the raw token is temporarily available for verification.

## Scopes

| Scope | Intended operations |
| --- | --- |
| `read` | Status, counts, bounded queries, validation, and previews |
| `draft` | Create or update unpublished staging records |
| `publish` | Publish, expire, or otherwise change public state |
| `admin` | Credential or provider administration; avoid for domain tools |

Scopes are hierarchical. An `admin` client can call all tools; a `draft`
client can call `draft` and `read`; a `read` client cannot mutate state.

## Public module methods

### `issueClient(string $label, array $scopes = ['read'], ?int $expiresAt = null): array`

Creates a client and returns its public record plus `token`. The raw token is
not retrievable later.

### `revokeClient(string $id): bool`

Disables a client immediately while retaining its identity and audit history.

### `clients(): array`

Returns client metadata without token hashes.

### `providers(): array`

Returns the current provider inventory and readiness state.

### `tools(): array`

Returns public tool metadata without PHP callables.

### `recentAudit(int $limit = 50): array`

Returns up to 200 recent audit rows.

### `auditPage(int $page = 1, int $perPage = 25): array`

Returns one bounded audit page plus `page`, `per_page`, `total`, and
`total_pages` metadata. The admin workspace uses 25 rows per page.

### `endpointUrl(): string`

Returns the configured absolute endpoint URL for the current installation.

### `publicSiteUrl(): string`

Returns the canonical public site URL. It prefers the optional
`public_base_url` module setting and otherwise derives the ProcessWire root,
using secure HTTPS output for non-local hosts in headless CLI context.

## Local CLI contract

`bin/mcp-server` is disabled until `enable_cli` is selected in module
configuration. Read commands are `status`, `clients`, `providers`, and bounded
`audit`. Credential mutations are `client-create` and `client-revoke`; both
require `--execute`. Every successful response uses
`{"ok":true,"result":...}` and failures use `{"ok":false,"error":"..."}`.
Client listings omit token hashes, and the raw token appears only in the
one-time `client-create` result.

## Provider requirements

- Bound every selector, batch, result set, and external request.
- Keep domain validation in the owning module.
- Make write operations idempotent and return stable record identifiers.
- Split validation/staging from publication.
- Never return credentials, application attachments, private notes, raw
  personal data, or unpublished content unless a separately reviewed tool has
  a legitimate requirement and explicit scope.
- Never expose generic eval, SQL, shell, filesystem, or unrestricted
  module-management handlers. A module provider may expose narrowly defined,
  permission-checked module administration tools with explicit schemas and an
  appropriate scope.
- Return structured arrays rather than preformatted prose where practical.

## Initial provider inventory

Provider availability is always determined from the installed site. The 1.0.0
release contract has been prepared in the owning repositories as follows:

| Provider | Tool | Scope | Data boundary |
| --- | --- | --- | --- |
| Tickets | `tickets_status` | `read` | Aggregate workflow/readiness only |
| Ichiban | `ichiban_seo_preview` | `read` | One public viewable page |
| Liora | `liora_status` | `read` | Readiness and aggregate telemetry only |
| Olivia | `olivia_status` | `read` | Safe feature/dependency state only |
| Relay | `relay_status` | `read` | Capability/channel state only |
| Vox | `vox_public_entries` | `read` | Bounded published entries on one public page |
| Verk | `verk_status` | `read` | Aggregate private-workspace counts only |
| Folio | `folio_status` | `read` | Aggregate organizer counts only |
| Resend | `resend_status` | `read` | Local readiness; no external request or secret |

These conservative surfaces are intentional. An authenticated MCP client is
not automatically a ProcessWire user, customer, editor, or mail operator.
Private record reads and mutations need a separately reviewed provider design,
stable identifiers, bounded schemas, explicit confirmation, idempotency, and a
domain rollback. JobBoard is the known-good example for that richer pattern.

## Website integration sequence for Olivia

1. Confirm live module installation, installed versions, endpoint state,
   environment, namespace, clients, providers, and tool inventory.
2. Compare the inventory with the approved site Blueprint and current privacy,
   editorial, delivery, and retention policies.
3. Begin with one development-only `read` client and call a harmless status
   tool. Verify the corresponding audit entry.
4. Add `draft` or `publish` only for a provider whose documented workflow
   separates validation, staging, review, and execution.
5. Use separate production credentials and repeat negative scope, Host, Origin,
   malformed-input, rate-limit, provider-failure, and audit checks.
6. Record deviations, remaining risks, credential ownership, revocation steps,
   monitoring, and rollback in the Action Plan result.
