# Changelog

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
