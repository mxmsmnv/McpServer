# Support and release status

MCP Server is intended for technical ProcessWire operators who can review
provider tools, credentials, server logs, and audit evidence. Version 1.x is
the stable compatibility line.

## Where to ask

- Use the ProcessWire support forum for setup questions, provider-development
  discussion, and reproducible non-sensitive problems.
- Use GitHub issues for confirmed bugs and focused feature requests.
- Use GitHub's private vulnerability reporting workflow for security matters;
  follow `SECURITY.md` and do not post secrets publicly.

Support is best-effort. There is no uptime, response-time, migration, or
compatibility SLA. A report should include the MCP Server version, ProcessWire
and PHP versions, client name/version, protocol era, exact non-secret request
shape, response status, and relevant redacted log entries.

## Compatibility policy

Minor releases may update protocol support, provider metadata, configuration,
or CLI output while preserving the documented public contract. Changes that
require operator action are called out in `CHANGELOG.md`. Provider modules
should depend on documented capabilities rather than internal classes or tables.
