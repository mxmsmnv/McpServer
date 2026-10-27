# Security policy

## Supported versions

MCP Server 1.x is the supported release line. Operators should update the complete module,
including its Composer `vendor/` directory, rather than copying individual
files between releases.

| Version | Security support |
| --- | --- |
| 1.x | Supported |
| 0.x and earlier | Unsupported |

## Authenticated scope bypass fixed in 1.0.5

Upgrade installations running 1.0.4 or earlier before relying on client scope
isolation. A legacy JSON-RPC batch could bypass envelope-level scope checks
and audit recording, allowing a valid low-scope credential to reach registered
higher-scope provider tools. Authentication was still required, and the impact
depends on the tools provided by installed modules.

Version 1.0.5 rejects batches for all protocol revisions and enforces scope
again at each actual provider dispatch. Single-message clients and existing
tokens remain compatible. If an immediate update is unavailable, keep the
remote endpoint disabled. Review provider/application logs and state when
assessing prior use: missing gateway audit entries do not establish that no
unauthorized tool call occurred. This fix does not itself rotate credentials.

## Reporting a vulnerability

Do not open a public issue or forum topic for a suspected vulnerability. Use
GitHub's private **Report a vulnerability** workflow in the repository Security
tab. Include the affected version, deployment topology, reproducible request,
observed impact, and any relevant redacted logs. Never include live bearer
tokens, `userAuthSalt`, database credentials, or personal data.

The maintainer will acknowledge a complete report as capacity permits, confirm
whether it is reproducible, and coordinate disclosure after a fix is available.
This is an independently maintained open-source project and does not currently
offer an SLA or guaranteed response time.

## Security boundary

MCP Server authenticates clients and routes only explicitly registered module
tools. It is not a remote shell, generic ProcessWire editor, database console,
or substitute for provider-level authorization and input validation. Every
provider module remains responsible for its own business rules, permissions,
stable identifiers, idempotency, and safe mutation semantics.

Production operators must use HTTPS, an explicit production environment mode,
installation-specific `userAuthSalt`, separate least-privilege credentials per
client, reviewed Host/Origin allowlists, bounded rate limits, and routine audit
review. Development and production credentials must never be shared.

Provider modules that perform identity-sensitive work must resolve their domain
principal from `requestClientContext()`. The context contains only the active
client ID, installation namespace, and normalized scopes. Providers must not
accept a caller-supplied agent or user identity as a substitute, and must not
persist or log bearer credentials.
