# Design Decisions

This records the decisions behind the implementation of `docs/spec.md`.
Facts about Cacti come from the `release/1.2.31` tag. Facts about Cloudflare
come from `docs/research/cloudflare-api-facts.md`, which cites its sources.

## Runtime floor

- Plugin code must parse and run on PHP 7.4. INFO says `compat = 1.2.25`,
  and Cacti 1.2.25 through 1.2.30 ship no Composer platform check, so they
  can run on PHP 7.4. No enums, readonly properties, `match`, constructor
  promotion, named arguments, union types, `mixed`, or `str_contains`.
- Cacti 1.2.31 itself needs PHP 8.0 or later. Its `composer.json` requires
  `php >=8.0`, and `include/vendor/composer/platform_check.php` stops on
  anything older; `include/global.php:555` loads it through the Composer
  autoloader. The plugin's 7.4 floor therefore matters only for 1.2.25
  through 1.2.30. The install smoke runs on `php:8.0-apache`, the lowest
  PHP that 1.2.31 accepts, and `php -l` under PHP 7.4 checks the floor.
- INFO `compat = 1.2.25`. Helpers added after 1.2.25 (for example
  `cacti_redact_sensitive`, `cacti_plugin_path`) are not called; the plugin
  carries its own redaction.
- `ext-curl` is not required by Cacti 1.2. The plugin checks
  `extension_loaded('curl')` in `plugin_cloudflare_check_config()` and refuses
  to enable without it.

## Code layout

Cacti glue is procedural, as in Thold and Syslog. Logic that can be tested
without Cacti lives in `src/` under `Cacti\PluginCloudflare\` and has no
dependency on Cacti globals. Only the glue files call Cacti functions.

```
setup.php                 lifecycle, hook and realm registration, upgrade check
INFO
includes/bootstrap.php    loads src/ (vendor autoloader if present, else a
                          bundled PSR-4 loader), builds services from Cacti state
includes/settings.php     config_arrays, config_settings, draw_navigation_text
includes/polling.php      poller_bottom hook (M2)
cloudflare.php            viewer pages: overview, tunnels, connectors, analytics
cloudflare_accounts.php   admin page: accounts, verify, diagnostics
poller_cloudflare.php     CLI collector, launched in the background (M2)
ss_cloudflare.php         script server functions that read cached state (M3)
src/                      domain, API client, storage, secrets
templates/                Cacti template XML (M3)
```

The release archive is self-contained. `vendor/` holds dev tools only, so
`includes/bootstrap.php` registers a small PSR-4 autoloader for `src/` and the
plugin has no runtime Composer dependencies.

## Database access (PDO)

- `src/Storage/Database.php` wraps a `PDO` handle. Every query is a prepared
  statement with bound values. Failed statements throw
  `DatabaseException`, which carries the SQLSTATE but never the bound values.
- In production the handle is Cacti's own connection, which is
  `$database_sessions["$database_hostname:$database_port:$database_default"]`
  (`lib/database.php:129`). The plugin then shares Cacti's credentials, TLS
  settings and session. Cacti leaves that handle in `ERRMODE_SILENT`, so
  `Database` checks return values itself and never changes attributes on the
  shared handle.
- Cacti sets `MYSQL_ATTR_FOUND_ROWS = true`, so `rowCount()` reports matched
  rows. Lease and compare-and-set updates put their whole condition in
  `WHERE`, which makes matched and changed rows the same thing.
- Tests use a real MariaDB 10.11 container with Cacti's session `sql_mode`
  (strict modes removed, as in `lib/database.php:118-126`) and a PDO handle
  built with the same flags.

## Schema and migrations

- Tables are created by plugin migrations, not
  `api_plugin_db_table_create()`. That helper cannot express foreign keys or
  composite unique keys and drops tables silently on uninstall.
- `plugin_cloudflare_migrations(version INT PK, applied_at INT)` records
  applied migrations. Every statement in a migration is idempotent (`CREATE
  TABLE IF NOT EXISTS`, column and index checks first). MySQL DDL commits
  implicitly, so an interrupted migration is recovered by re-running the
  same statements, not by rollback. The version row is written last.
- `GET_LOCK('plugin_cloudflare_migrate', 10)` serializes concurrent
  upgrades.
- Timestamps are `INT UNSIGNED` epoch seconds (UTC), so time zone handling
  never depends on the session.
- Migration 1 (M1): `accounts`, `jobs`, `migrations`. Migration 2 (M2):
  `tunnels`, `connectors`. Migration 3 (M3): `resources`, `metric_mappings`.
  This spreads the tables over three migrations, so the upgrade path gets
  exercised during development.
- Core Cacti never calls an upgrade function (agent survey of
  `lib/plugins.php`). `plugin_cloudflare_upgrade()` compares the INFO version
  with `plugin_config.version`, runs pending migrations, and writes the new
  version. It is called from the `config_settings` hook and at the top of
  every plugin page, the same way Thold does it.

## Uninstall and retention

Uninstall drops the plugin's own tables, which hold inventory, job state and
encrypted credentials. All of it can be rediscovered or re-entered.
Uninstall does not remove Cacti devices, graphs, data sources, RRD files or
templates created for Cloudflare. Those hold historical monitoring data, and
removing them stays an explicit administrator action in Cacti. The README
says this.

## Credentials

- Global API Keys are rejected: 37 lowercase hex characters, or any request
  for an email plus key. Only scoped API tokens are accepted, matching
  `^[A-Za-z0-9_-]{40,256}$`.
- Two token sources:
  - `file`: an absolute path to a file containing only the token. This is the
    externally provisioned option, and it works for both the web server and
    the poller user. The file must be a regular file of no more than 4 KiB,
    must not be a symbolic link, and must not be readable or writable by
    others. These checks run when the file is read (at Verify, and in the
    collector from Milestone 2), not when the account is saved. The web
    server user and the poller user can differ, so a check made by the web
    server at save time could pass for a file the poller cannot read, or
    fail for one it can. Save checks only that the path is absolute. A bad
    file therefore shows up at the first Verify, as "Token file is missing
    or insecure".
  - `vault`: AES-256-GCM via `openssl_encrypt`, with a 12-byte random nonce
    and a 16-byte tag. The additional authenticated data is
    `plugin_cloudflare:account:<cf_account_id>`, so a ciphertext cannot be
    moved to another account row. The stored format is
    `v1:` + base64(nonce . tag . ciphertext). The 32-byte key is read from
    the file named in the `cloudflare_key_file` setting (default
    `/etc/cacti/cloudflare.key`, raw 32 bytes or 64 hex characters). If the
    key is missing, vault storage is disabled and only `file` is offered.
    The plugin never generates or writes the key; the README shows the
    command to create it.
- Tokens are never rendered back to any page, never logged, and never put in
  exception messages. The edit form shows only whether a token is set.
- A save that leaves the token field blank keeps the existing token.

## Outbound HTTP

- `src/Http/CurlTransport.php` is the only code that performs network I/O.
  It accepts URLs only from an `OutboundPolicy` origin allowlist. The
  production list is exactly `https://api.cloudflare.com`. It enforces
  `CURLOPT_PROTOCOLS = HTTPS`, no redirects, peer and host TLS verification,
  a connect timeout, a total timeout, and a 5 MiB response cap.
- Timeouts: UI requests use a 5 second connect and 10 second total timeout.
  The collector uses 5 and 20 seconds. No UI request retries more than once.
- Path parameters are validated before a URL is built. An account ID is
  `^[0-9a-f]{32}$`. Tunnel and connector IDs are lowercase UUIDs.
- Tests may build a policy that allows `http://127.0.0.1:<port>` to exercise
  the real curl transport against `php -S`. Production builds the policy
  from a constant and exposes no setting for it.

## Error classification

Every API failure maps to exactly one class, which the domain uses in place
of HTTP details:

| Class | Cause |
|---|---|
| `auth` | 401, error code 10000, token status not `active` |
| `permission` | 403 |
| `not_found` | 404 |
| `rate_limited` | 429 |
| `transient` | 5xx, timeout, DNS failure, connection refused, TLS handshake failure |
| `malformed` | invalid JSON, a missing `success` field, a wrong `result` type |
| `config` | an invalid ID or a disallowed origin, raised before any I/O |

`transient` and `rate_limited` failures never change the lifecycle state of
discovered resources and never produce metric values. Both are retried at
most twice. A 429 honours `retry-after` up to 30 seconds; a longer value
defers the job instead of sleeping.

## Authorization

- Realm "Cloudflare: View" covers `cloudflare.php` and is not admin-only.
- Realm "Cloudflare: Configure" covers `cloudflare_accounts.php` and is
  granted to admin at install.
- `include/auth.php` enforces the realm from the page basename
  (`include/auth.php:183`). Each action handler also re-checks with
  `api_user_realm_auth('cloudflare_accounts.php')` before any write, so a
  page that is later reused cannot skip the check.
- Every state-changing action is a POST protected by Cacti's csrf-magic,
  which `include/auth.php` loads for plugin pages.

## Testing

- PHPUnit 9.6, which runs on PHP 7.4 through 8.4, covers unit and DB
  integration tests. Behat 3 runs the BDD scenarios. Eris provides
  property-based tests where it fits: ID validation, the token vault round
  trip, and reconciliation idempotence.
- Static analysis is PHPStan with `phpVersion: 70400`. Style is
  php-cs-fixer with Cacti's rules (tabs, single quotes, same-line braces).
  `php -l` runs under PHP 7.4 in Docker.
- The Cloudflare API is faked at the transport boundary (`HttpTransport`)
  from recorded fixtures. That is the only mock. Storage tests use real
  MariaDB.
- BDD scenarios drive the application services (`AccountService`,
  `DiscoveryService`, ...) against real MariaDB and the fake transport. A
  separate Docker smoke suite installs the plugin into a real Cacti 1.2.31
  with `cli/plugin_manage.php` and checks realms over HTTP.
