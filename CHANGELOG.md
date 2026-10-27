# Changelog

## [1.0.5] - 2026-10-27

### Security

- Fix an authenticated scope-check and audit bypass through legacy JSON-RPC
  batches, reported by MrSnoozles. Require authorization for the actual tool
  reference immediately before every provider invocation and audit each
  invocation or dispatch denial independently of the HTTP envelope.
- Reject JSON-RPC batch bodies in every protocol era before SDK dispatch;
  audit up to 100 denied tool attempts without executing any batch element.
- Fail closed on missing identity, unknown tool references and mismatched
  request context. Preserve single-message legacy handshake support.

### Validation

- Add security regressions exercising real bundled SDK legacy batch dispatch,
  scope boundaries, denial/success/error audits, malformed batches and bounded
  audit work. Extend real HTTP tests with legacy handshakes, batch rejection,
  mixed batches and persisted per-call denial evidence.

### Upgrade

- Update the complete module and refresh ProcessWire modules. Existing tokens
  retain their scopes and format; no database migration is required. Clients
  using batches must send separate HTTP requests. Do not restore an older
  runtime with the endpoint enabled; disable remote access before rollback.

## [1.0.4] - 2026-10-27

### Fixed

- Filter bearer credential candidates by their stored prefix before expensive
  password verification. Preserve full hash verification, prefix collisions,
  prefix-less legacy clients, and existing tokens after namespace changes.
- Add the missing Tools admin workspace and navigation while retaining the
  existing tool inventory under Providers.
- Document SDK-injected RequestContext handlers and access to validated tool
  arguments in the API and operator guide.
- Derive the Host allowlist from configuration rather than adding the incoming
  Host header, as verified by the development HTTP security suite.

### Validation

- Add behavioral regression tests for credential selection and legacy hashes,
  SDK context injection and input validation, and Tools rendering.

### Upgrade

- Replace the complete module and refresh ProcessWire modules. No credential,
  configuration, or database migration is required. The previous runtime can
  be restored without changing stored tokens.

## [1.0.3] - 2026-09-22

### Added

- Expose a request-scoped provider context containing only the authenticated
  client ID, installation namespace, and normalized scopes, so domain modules
  can bind remote calls to their own principals without accepting an
  impersonable identity argument or receiving credential material.

## [1.0.2] - 2026-08-30

### Fixed

- Discover explicit MCP provider modules in CLI and authenticated HTTP
  requests even when ProcessWire bootstraps without an admin user. Provider
  tool scopes and module business rules remain authoritative.

## [1.0.1] - 2026-08-27

### Fixed

- Resolve Composer and reference-handler files from the module root when the
  Streamable HTTP endpoint is implemented by the nested transport trait.
- Avoid the PHP 8.5 `curl_close()` deprecation in the development HTTP suite.

## [1.0.0] - 2026-08-25

### Added

- First public release of the authenticated Streamable HTTP MCP gateway for ProcessWire.
- Provider discovery for explicit module-owned tools with closed JSON Schema, bounded scopes, safety annotations, and installation-neutral names.
- Per-client bearer credentials with one-time disclosure, salted and installation-peppered hashes, expiry, revocation, hierarchical scopes, and per-client rate limits.
- Durable client identities and append-only audit evidence without raw tool arguments.
- Host, Origin, body-size, endpoint, environment, namespace, and no-store/noindex boundaries.
- Admin workspaces for readiness, clients, providers, tools, CLI, documentation, and paginated audit history.
- Optional bounded JSON CLI for inspection and explicitly confirmed credential administration.
- Compatibility with the official PHP MCP SDK 0.8, stateless MCP 2026-07-28 requests, and compatible legacy handshake clients.
- Initial provider integrations for Tickets, Ichiban, Liora, Olivia, Relay, Vox, Verk, Folio, and Resend, following the established JobBoard provider contract.
- Domain-oriented runtime and admin traits under `src/`, with compact ProcessWire composition roots and colocated admin styling.
