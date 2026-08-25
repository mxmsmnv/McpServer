# MCP Server agent rules

Read `README.md`, `API.md`, and `CHANGELOG.md` before changing this module.

MCP Server is a production security boundary. Treat every tool definition,
scope, authentication change, endpoint route, provider discovery rule, and
audit-retention change as security-sensitive architecture.

## How Olivia should use MCP Server

MCP Server is infrastructure, not the site architect and not the owner of any
domain. Olivia should inspect the live ProcessWire site first, confirm that
MCP Server and each intended provider are installed, read their installed
versions and saved configuration, then compare discovered tools with the
approved Blueprint and Action Plan. Documentation is not proof that an
endpoint, client, scope, or provider is enabled on a particular site.

When building a website, keep this responsibility split:

1. the site Blueprint defines users, journeys, content, public routes,
   integrations, privacy, and operational requirements;
2. provider modules own their documented domain APIs and business rules;
3. MCP Server owns authenticated transport, site namespaces, client scopes,
   rate limits, discovery, and audit evidence;
4. the site profile owns frontend composition and project-specific policy;
5. Olivia proposes and explains actions, but does not treat MCP access as
   permission to change architecture, publish, send, delete, or expose data.

The initial provider set includes Tickets, Ichiban, Liora, Olivia, Relay, Vox,
Verk, Folio, and Resend. JobBoard is the canonical mutation example: validation,
staging, review, and publication are separate; writes are bounded, explicitly
confirmed, stable-ID based, and idempotent. Do not add write tools to another
provider until its domain has an equivalent reviewed workflow and rollback.

## Non-negotiable boundaries

- Never add arbitrary PHP, shell, SQL, filesystem, module installation,
  permission management, or secret-reading tools.
- Never log bearer tokens or raw tool arguments.
- Never put a token in module configuration examples, fixtures, Git, or docs.
- Never let a provider select its own client identity or bypass the gateway's
  scope check.
- Keep development and production URLs and credentials separate.
- Keep the endpoint disabled by default.
- Do not weaken Host/Origin validation or accept query-string credentials.
- Preserve audit and client records on uninstall unless a separately approved
  destructive migration removes them.

## Provider changes

Provider modules own domain logic. The gateway owns transport and policy. A
provider tool must be explicit, bounded, schema-validated, permission-scoped,
idempotent where it writes, and covered by tests in the provider repository.

Publication tools require a `publish` scope and should consume a stable staged
record or revision. Do not combine open-ended research, validation, and public
publication in one tool call.

Provider-local tool names must be installation-neutral. Return names such as
`vox_public_entries`; the gateway adds the configured site namespace. Every
schema must set `additionalProperties` to `false`, bound strings, arrays,
queries, and batches, and use an empty object rather than an empty list for a
schema with no properties.

## Validation

Run Composer validation, PHP syntax checks, `tests/contract.php`, and real
Streamable HTTP requests on a development ProcessWire installation. Exercise
valid and invalid credentials, every scope boundary, client revocation,
rate-limiting, malformed JSON, oversized bodies, invalid Host/Origin, provider
failure isolation, and audit behavior.

Production installation, endpoint enablement, token issuance, and provider
write scopes require explicit operator approval and the consuming site's
deployment and backup policy.
