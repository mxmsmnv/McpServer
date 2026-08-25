# MCP Server

MCP Server connects Codex and other Model Context Protocol clients to explicit,
module-owned ProcessWire capabilities through one authenticated Streamable HTTP
endpoint.

![MCP Server](assets/McpServer.png)

It is made for sites that need remote agent access without exposing SSH, the
database, arbitrary PHP, a remote shell, or a generic content-editing API.
Installed provider modules opt in and keep ownership of their validation and
business rules; MCP Server owns transport, client identity, scopes, rate limits,
tool discovery, and audit evidence.

**Author:** Maxim Semenov

**Website:** [smnv.org](https://smnv.org)

**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development through
[GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or
[smnv.org/sponsor](https://smnv.org/sponsor/).

## What MCP Server Does

- Exposes the official PHP MCP SDK 0.8 over authenticated Streamable HTTP.
- Supports stateless MCP 2026-07-28 requests and compatible legacy handshakes.
- Discovers only installed modules that explicitly declare `mcpProvider`.
- Validates every tool input against a closed JSON Schema.
- Applies hierarchical `read`, `draft`, `publish`, and `admin` scopes.
- Issues separate revocable bearer credentials for each client runtime.
- Stores only salted, installation-peppered credential hashes; raw tokens are
  shown once and cannot be recovered.
- Rate-limits each client and validates Host, Origin, and request body size.
- Records identity, tool, scope, result, timing, and an argument digest without
  retaining raw tool arguments.
- Includes ProcessWire admin workspaces for readiness, clients, providers,
  tools, CLI guidance, documentation, and paginated audit history.
- Includes an optional bounded JSON CLI for local inspection and explicitly
  confirmed client creation or revocation.

## Provider Integrations

The initial release is compatible with the established JobBoard provider
contract and includes integrations prepared in the owning module repositories:

| Module | MCP surface |
| --- | --- |
| Tickets | Aggregate workflow and delivery readiness; no customer records or messages |
| Ichiban | Bounded SEO preview for a public, viewable page |
| Liora | Assistant readiness and aggregate demand telemetry; no conversations |
| Olivia | Safe build-system readiness; no planning, install, build, or undo mutation |
| Relay | Scheduling capabilities and channel readiness; no editorial job payloads |
| Vox | Bounded published discussions for one public, viewable page |
| Verk | Aggregate task and sprint state; no private operations content |
| Folio | Aggregate organizer counts; no contacts, places, notes, or task content |
| Resend | Local delivery readiness without secrets, recipients, or external requests |

Provider-local names are installation-neutral, such as `vox_public_entries`.
The gateway prepends the configured site namespace when it registers a tool.
JobBoard remains the canonical example for mutation tools: validate first,
stage unpublished records, publish reviewed stable IDs separately, bound every
batch, require explicit confirmation, and persist idempotency results.

## Requirements

- ProcessWire 3.0.200+
- PHP 8.2+
- HTTPS for production endpoints
- A strong, installation-specific `$config->userAuthSalt`

Composer runtime dependencies, including `vendor/`, ship with the release.

## Installation

1. Copy the complete `McpServer` directory into `site/modules/McpServer/`.
2. In ProcessWire Admin, refresh modules and install **MCP Server**.
3. Open **Setup → MCP Server** and review the live readiness state.
4. Configure a unique lowercase installation namespace.
5. Set the explicit environment and canonical public HTTPS URL for managed or
   production installations.
6. Review the endpoint path, allowed hosts, browser origins, and rate limit.
7. Create one least-privilege client identity per computer or agent runtime and
   copy its bearer token immediately.
8. Review every discovered provider and tool before enabling the endpoint.

The default endpoint is `/mcp/` and is disabled by default.

## Client Connection

Choose Streamable HTTP in the MCP client:

```text
Name: Example Production
URL: https://example.com/mcp/
Bearer token env var: EXAMPLE_MCP_BEARER_TOKEN
```

Use a different endpoint credential and environment variable for development.
Never put a production token in Git, ProcessWire content, screenshots, prompts,
or shared shell history.

## Provider Contract

An installed provider opts in through module metadata:

```php
'mcpProvider' => true,
```

It exposes two public methods:

```php
public function mcpProviderInfo(): array;
public function mcpTools(): array;
```

Every tool declares a provider-local name, callable handler, required scope,
safety annotations, and a closed bounded input schema. See [API.md](API.md) for
the complete contract and verified public gateway methods.

## Local CLI

Enable the CLI under **MCP configuration → Local administration**, then run
from the ProcessWire root:

```bash
php site/modules/McpServer/bin/mcp-server status
php site/modules/McpServer/bin/mcp-server clients
php site/modules/McpServer/bin/mcp-server providers
php site/modules/McpServer/bin/mcp-server audit --limit=25
```

Credential mutations require `--execute`:

```bash
php site/modules/McpServer/bin/mcp-server client-create \
  --label="MacBook · Codex" --scopes=read --execute
php site/modules/McpServer/bin/mcp-server client-revoke \
  --id=CLIENT_ID --execute
```

## Security Boundary

- No arbitrary PHP, shell, SQL, filesystem, module-install, permission, or
  secret-reading tools.
- Development and production use separate endpoints and credentials.
- Provider modules own domain validation, stable identifiers, permission
  boundaries, idempotency, and safe mutation semantics.
- The endpoint emits `Cache-Control: no-store` and `X-Robots-Tag: noindex`.
- Uninstall preserves client and audit records; destructive cleanup is a
  separate operator decision.

See [SECURITY.md](SECURITY.md) for private vulnerability reporting and
[SUPPORT.md](SUPPORT.md) for the compatibility and support policy.

## Validation

```bash
composer validate --strict
composer install --no-dev --prefer-dist --optimize-autoloader
composer audit
php -l McpServer.module.php
php -l ProcessMcpServer.module.php
find src -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/contract.php
```

The real HTTP integration suite is development-only and requires an explicit
target and `--execute`; its full contract is documented in [API.md](API.md).

## Source Layout

`McpServer.module.php` and `ProcessMcpServer.module.php` are compact ProcessWire
composition roots. Runtime behavior is grouped into domain traits under `src/`:
transport, client security, providers, audit, environment support, lifecycle,
configuration, and operator workspaces. Admin presentation assets live beside
that UI code in `src/Admin/admin.css`.

## Documentation

- [API.md](API.md) — public methods, provider contract, scopes, schemas, and CLI
- [AGENTS.md](AGENTS.md) — Olivia and coding-agent safety rules
- [CHANGELOG.md](CHANGELOG.md) — release history
- [SECURITY.md](SECURITY.md) — security policy and private reporting
- [SUPPORT.md](SUPPORT.md) — support and compatibility policy

## License

Mozilla Public License 2.0. See [LICENSE](LICENSE).
