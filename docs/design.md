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
includes/polling.php      poller_bottom hook and the collector's entry function
includes/templates.php    template import, and the copy of the data query XML
includes/cacti_objects.php  device creation, reindex and graph creation
includes/script_server.php  the functions behind ss_cloudflare.php
includes/viewer.php       viewer page handlers: overview, tunnels, connectors
cloudflare.php            viewer page dispatcher
cloudflare_accounts.php   admin page: accounts, verify, diagnostics
poller_cloudflare.php     CLI collector, launched in the background
ss_cloudflare.php         script server entry point for the tunnel data query
src/                      domain, API client, storage, secrets, metrics
templates/                Cacti template XML and the data query XML
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
  `tunnels`, `connectors`, and the `outage_failures` and `last_success_at`
  columns on `jobs`. Migration 3 (M3): `resources`, `metric_mappings`, and the
  `connections_error_class` and `create_graphs` columns on `tunnels` and
  `accounts`. This spreads the tables over three migrations, so the upgrade path gets
  exercised during development.
- Core Cacti never calls an upgrade function (agent survey of
  `lib/plugins.php`). `plugin_cloudflare_upgrade()` compares the INFO version
  with `plugin_config.version`, runs pending migrations, and writes the new
  version. It is called from the `config_settings` hook and at the top of
  every plugin page, the same way Thold does it.

## Uninstall and retention

Uninstall drops the plugin's own tables, which hold inventory, job state and
encrypted credentials. All of it can be rediscovered or re-entered.
Uninstall does not remove Cacti devices, graphs, data sources, RRD files,
templates or the copied data query XML created for Cloudflare. Those hold historical monitoring data, and
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
    collector), not when the account is saved. The web
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
- Timeouts: UI requests use a 5 second connect and a 10 second total timeout
  by default. The collector uses 5 and 20 seconds by default. The total
  timeouts come from the two timeout settings. No UI request retries more
  than once.
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

Outage-class failures (`transient`, `rate_limited`, `auth` and `permission`)
that happen before a complete listing count toward the account's
`outage_failures`. After three consecutive ones, the account's active tunnels
become `unavailable`. An outage never deletes anything, and the next complete
listing restores the tunnels and resets the count. These failures never
produce metric values. `config` and `malformed` failures do not count, because
they say nothing about whether Cloudflare could be read. Retries differ by
caller:

- UI requests, such as Verify, retry once at most. A 429 sleeps for
  `retry-after` only when it is 2 seconds or less; a longer value fails the
  request at once as `rate_limited`, so a page request never waits out a long
  limit.
- The collector retries at most twice. A 429 honours `retry-after` up to 30
  seconds; a longer value defers the job instead of sleeping.

On Verify, a 403 from both token verify endpoints records
`permission_denied` with a fixed message that the token lacks the
permissions needed to verify it; a 401 records `auth_failed`.

## Discovery

- `includes/polling.php` runs in `poller_bottom` on the main poller only
  (`poller_id` 1) and starts `poller_cloudflare.php` with `exec_background`,
  so `poller.php` never waits for it. It starts nothing when no enabled
  account collects tunnels.
- The collector takes `GET_LOCK('plugin_cloudflare_collector', 0)` and exits
  quietly when another collector holds it. Each job row also has a lease, so
  two runs cannot work on one account.
- A run starts no new account after a 50 second budget, and makes at most 200
  connection requests, shared across the accounts that are due. An account
  stopped by either limit is recorded `partial` and resumes on the normal
  schedule, with the tunnels attempted least recently first. Before each
  account the collector reads `plugin_config.status` directly and stops when
  the plugin is disabled. `api_plugin_is_enabled()` is not used there because
  it caches its first answer for the life of the process.
- An account's tunnels come from one complete listing. A failed or
  incomplete listing never deletes a tunnel and leaves every tunnel row
  unchanged; three consecutive outage-class failures before a complete
  listing mark the account's active tunnels `unavailable`, and the next
  complete listing restores them. Rows are upserted on
  `(account_id, cf_tunnel_id)` and never deleted and re-inserted, so a tunnel
  keeps its row id. A tunnel absent from a complete listing becomes
  `deleted`, and one that returns becomes `active`.
- The listing pins page 1's `total_count`, and a later page that reports a
  different total fails the run as `malformed`, so a tunnel deleted between
  pages cannot shift the next page and hide another tunnel. A delete and a
  create between pages keep the total unchanged, so on an account with more
  than 1000 tunnels that pair can still hide one tunnel and mark it `deleted`
  for one cycle; the next complete listing makes it `active` again.
- The lifecycle is `active`, `unavailable` or `deleted`. Cloudflare's own
  health is stored apart from it as `cf_status`.
- Connections are read per tunnel. Cloudflare answers an unknown tunnel with
  401 and code 1001 or with 404; both mean the tunnel is gone, so they are
  not counted against the token. Connectors that a read no longer reports
  are removed.
- The viewer pages only read. Their handlers catch `Throwable`, log the class
  name and show a fixed message, because Cacti disables a plugin whose page
  fails with an uncaught error.

## Cacti objects

### Object model

An account maps to at most one Cacti device. The device uses the plugin's
Cloudflare Account host template, SNMP version 0, availability method `'0'`
and `poller_id` 1, and its hostname is `<cf_account_id>.cloudflare.invalid`
(`includes/cacti_objects.php:103-130`). The availability method is the string
`'0'` because Cacti's own `cli/add_device.php:199` notes that `AVAIL_NONE`
fails validation. Only the administrator's Create Cacti Device action creates
a device, and `plugin_cloudflare_resources` records which one the plugin made
(`src/Metrics/ResourceRepository.php:67`).

Tunnels are not devices. They are the indexes of one script server data
query, keyed by `cf_tunnel_id`, so a new tunnel needs no new device and a
Cacti graph for a tunnel follows the usual data query path. The index lists
every tunnel of the account, `deleted` ones included
(`src/Metrics/ObservationReader.php:81-92`). Cacti marks a data source
orphaned when its index leaves a reindex (`lib/data_query.php:317`, the same
line in 1.2.25 and 1.2.31), so dropping a deleted tunnel from the index would
orphan its history. A deleted tunnel keeps its index, graphs and RRD file,
and prints `U`.

A host the administrator deletes is not recreated by the collector. Create
Cacti Device replaces the record. A device already recorded for another
account is refused, because two accounts on one host would print each other's
tunnels into one data query.

### Reindex

The device's data query uses reindex method None
(`DATA_QUERY_AUTOINDEX_NONE`, `include/global_constants.php:75`), so Cacti
schedules no reindex of its own. `api_device_save()` sets None for SNMP
version 0 (`lib/api_device.php:1073-1077`), but it then calls
`api_device_update_host_template()` (`:1131`), which adds the template's data
query again with the global default method (`:1266-1269` in 1.2.25,
`:1285-1288` in 1.2.31). `cloudflare_create_device_locked()` therefore calls
`api_device_dq_add()` with None afterwards. That call replaces the row and
runs the query once (`lib/api_device.php:552-578`).

The plugin reindexes instead. After each discovery that ends `ok` or `partial`,
the collector calls `run_data_query()` for the account's device with `force`
set (`includes/cacti_objects.php:284-286`), then creates missing graphs. `force`
is always on. Without it Cacti refreshes data source and graph titles only for
indexes that changed or were orphaned (`lib/data_query.php:356-363`), and a
tunnel rename changes no index. A run that stopped between the cache update
and the title update would also never be repaired by a later run, because the
later run would see no change. With `force` every run refreshes the titles
(`:363-368`). The cost is a title refresh for the device's graphs and data
sources on each run.

### Where Cacti reads the data query XML

Cacti 1.2.31 resolves a data query's `xml_path` with `realpath()` and refuses
a path that is not inside `<path_cacti>/resource`
(`lib/data_query.php:612-620`, `cacti_path_is_within()` at
`lib/functions.php:8058`). A path in the plugin directory fails, and so does a
symbolic link from `resource/` into the plugin, because `realpath()` follows
it. Cacti 1.2.25 only checks that the file exists
(`lib/data_query.php:612`). The plugin therefore copies
`templates/resource/cloudflare_tunnels.xml` to
`<base_path>/resource/script_server/cloudflare_tunnels.xml` and the template
XML points there. It is the plugin's only write outside its own directory, and
`docs/security.md` describes how it is done.

The script stays in the plugin directory. Script server 1.2.31 accepts an
include file and a function whose source file is under `base_path`
(`script_server.php:264-290`, `:327-361`), and `ss_cloudflare()` is defined in
the file that the poller item names.

### One RRD per tunnel

Each of the three graph templates (Connectors, Health, Collection) maps all
six output fields of the data query, so the first graph of a tunnel creates the
data source and the other two reuse it through `data_source_exists()`
(`lib/template.php:1594`, with the reuse at `:1613`, in 1.2.31). A tunnel has
one data source and one RRD file with six data sources inside, shared by its
three graphs. The install smoke checks 9 graphs, 3 data sources and 18 poller
items for the three fixture tunnels (`tests/docker/smoke.sh:728-763`). Sharing
keeps the RRD count at one per tunnel and means a metric appears in whichever
graph is useful without being polled twice.

### What the script reads

`ss_cloudflare.php` reads only the plugin's tables
(`docs/collector-protocol.md`). It never calls Cloudflare and never throws, so
a Cloudflare outage cannot delay the poller, and a failure prints `U` or an
empty listing, never 0. The collector alone writes those tables. The values
and the conditions for printing them are in `docs/metrics.md`.

### Locks

Two named locks serialize work that would otherwise create objects twice.
`plugin_cloudflare_device_<account id>` (30 second wait,
`includes/cacti_objects.php:46,65`) makes a double submit of Create Cacti
Device wait and then find the device. `plugin_cloudflare_templates` (10 second
wait, `includes/templates.php:28,62`) keeps two processes that upgrade at once
from importing the templates together, since the import would create each
object twice. Both are `GET_LOCK()` locks, so MySQL releases them when the
connection ends even if a release fails.

## Authorization

- Realm "Cloudflare: View" covers `cloudflare.php` and is not admin-only.
- Realm "Cloudflare: Configure" covers `cloudflare_accounts.php` and is
  granted to admin at install.
- `include/auth.php` enforces the realm from the page basename
  (`include/auth.php:183`). Each action handler also re-checks with
  `api_user_realm_auth('cloudflare_accounts.php')` before any write, so a
  page that is later reused cannot skip the check.
- Create Cacti Device, and turning on Create Graphs, also need the Cacti realm
  of `host.php`, because the plugin then creates Cacti devices and graphs. The
  menu entry is hidden without it, and the server checks both realms again.
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
