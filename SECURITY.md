# Security policy

## Supported versions

MCP Server 1.x is the supported release line. Operators should update the complete module,
including its Composer `vendor/` directory, rather than copying individual
files between releases.

| Version | Security support |
| --- | --- |
| 1.x | Supported |
| 0.x and earlier | Unsupported |

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
