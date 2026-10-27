# MCP Server testing plan

## Scope and risk

- Classification: C (admin Process module) with security-sensitive API transport.
- Owner: module maintainer.
- Supported runtime: ProcessWire 3.0.200+, PHP 8.2+.
- Uninstall policy: preserve-user-data (client identities and audit history).

The principal risks are credential acceptance, scope enforcement, provider
dispatch, admin permissions, schema validation and accidental disclosure of
tokens or arguments. Provider business operations belong to provider test suites.
MCP Server tests must not publish content or call real external services.

## Test commands

Run each script in a separate PHP process; regression.php supplies isolated
ProcessWire service doubles and must not be included by a running site.

```sh
composer validate --strict
composer install --no-dev --prefer-dist --optimize-autoloader
composer audit
find . -path './.git' -prune -o -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/contract.php
php tests/regression.php
php tests/security-regression.php
php tests/processwire-integration.php --root=/path/to/development-site
php tests/http-integration.php --root=/path/to/development-site --url=http://127.0.0.1:8766/mcp/ --execute
```

CI runs syntax, contract and behavioral regressions on PHP 8.2 and 8.5.
The last two commands require a separately provisioned development site with
MCP Server and ProcessMcpServer installed and their admin page present.
ProcessWire integration uses its real dispatcher and permission checks; a
separate WireTests adapter is not required for these standalone checks.

## Test environment and cleanup

Use a disposable database or dedicated local test site. Back up its module
configuration before enabling the endpoint. Set `environment_mode=development`,
an explicit `public_base_url` matching the test URL, and a narrow host allowlist.
Run the local HTTP server bound to loopback. Never point the suite at production.
The URL host must match the configured public site host. `--insecure` is only
for a separately approved local TLS fixture; normal tests verify certificates.

HTTP fixtures are labeled `http-<timestamp>-<random>` and their clients are
revoked in a finally block. Revoked client metadata and audit evidence remain
intentionally retained. Configure one non-read provider tool whose required
inputs make an empty argument object invalid: the suite tests denial/schema
failure without invoking a business mutation. Inspect that schema before use.

The ProcessWire suite temporarily selects existing users in memory, restores
the original user, and never creates accounts or changes roles/content. It
checks an existing non-admin member when available. Restore all temporary
endpoint settings and stop task-created servers and browser sessions afterward.
Do not delete retained audit data as part of routine cleanup.

## Automated journeys and failure paths

1. Credential selection: twenty clients, unknown and matching invalid prefixes,
   collisions, missing/empty legacy prefixes, old raw-token hash migration,
   namespace changes, pepper mismatch, malformed, revoked and expired tokens.
   Count real password verifications rather than using timing thresholds.
2. Provider dispatch: the real bundled SDK injects RequestContext, preserves
   arguments and defaults, and rejects extra, mistyped and out-of-range inputs
   before the handler runs. Named-parameter handlers remain compatible.
3. Admin: render populated/empty Tools inventories with escaping and the active
   navigation tab. Real ProcessWire dispatch opens Tools and retains Providers;
   guest and member without `mcp-server-admin` are denied.
4. HTTP: authentication, discovery, tool calls, insufficient scope, missing
   tools, malformed JSON-RPC, body limit, Host/Origin, expiry, revocation, rate
   limits, isolated schema/provider failure and persisted audit evidence.

Read-only MCP calls may be replayed. This patch adds no content mutations or
CSRF-protected forms; credential administration CSRF behavior is unchanged.
There are no external delivery/payment services in these tests.

## Agent-led release scenarios

Open Tools anonymously (login required), sign in with a development fixture
administrator, follow Tools/Providers/Documentation links, inspect names,
scopes and the context example. Check desktop/mobile navigation, keyboard
focus and browser errors. Member access denial is covered by the real
ProcessWire dispatcher. Dark mode is tested when the target admin theme offers
it; the module inherits the theme and introduces no new theme controls.

## Lifecycle and rollback

Fresh install, uninstall and reinstall require a disposable site/database and
are release checks when lifecycle/storage code changes. Version 1.0.4 does not
change that code or storage schema. Its upgrade check is an existing 1.0.3
installation refreshed against 1.0.4, with valid legacy and current tokens.
Runtime dependencies are installed from the unchanged Composer lock file.

Restore the complete previous runtime to roll back 1.0.4. Credentials keep their
existing format and need no rotation. Before rollback or deployment, retain a
backup of module files and the consuming site's normal database backup.

## Release evidence: 1.0.4 (2026-10-27)

- Development site: local `kontor-lifecycle`, dedicated `kontor_e2e_lifecycle`
  database; ProcessWire 3.0.259, PHP 8.5.8, installed metadata 104 after refresh.
- 42 contract, 27 behavioral, 21 ProcessWire and 16 real HTTP checks passed.
- HTTP testing exposed an existing Host allowlist defect; corrected and the
  complete HTTP suite passed, including Host rejection with HTTP 403.
- Browser: guest login boundary, fixture administrator Tools and Documentation;
  desktop and 390px mobile Tools rendered correctly; browser console had no
  warnings/errors.
- Composer validation/install/audit passed; no dependency changes required.
- Test credentials revoked; endpoint configuration restored after testing.
- Fresh lifecycle and the minimum ProcessWire version were not rerun locally.
  PHP 8.2 validation is delegated to CI and must be checked before publication.


## Release evidence: 1.0.5 (2026-10-27)

- Security reproduction: the new final-dispatch denial test failed on the
  unchanged 1.0.4 reference handler because a read client executed a publish
  fixture. The fixture only increments an in-memory counter.
- Fix coverage: 41 security regressions cover final dispatch, the complete
  4-by-4 scope hierarchy, authenticated identity, request/reference binding,
  success/error/denial audit, batch rejection, malformed IDs and digest-only
  persistence. A real bundled-SDK 2025-03-26 handshake and mixed batch bypass
  the outer envelope gate in the test and still cannot execute publish for read.
- Local checks: 42 contract, 27 existing behavioral, 21 ProcessWire and 29 real
  HTTP checks passed on ProcessWire 3.0.259 / PHP 8.5.8. HTTP checks cover legacy
  and modern single messages, batch rejection, session continuity, per-element
  denied audit and absence of duplicate audit entries for one invocation.
- No UI/lifecycle/storage schema/dependency changes; focused API and dispatcher
  checks apply. Fresh install/uninstall and browser layout testing were not
  repeated for this patch. PHP 8.2 and 8.5 are checked by CI before publication.
- Development endpoint settings are backed up and restored after validation;
  issued test clients are revoked and the legacy test session is deleted.
- Upgrade complete runtime files to 1.0.5; credentials do not need rotation.
  JSON-RPC batches are no longer supported in any protocol era. If rollback
  is necessary, disable the endpoint before restoring an older runtime.
