# Compatibility

This page records the versions the plugin supports, what is tested, and the external interfaces it depends on. Cacti facts come from the
`release/1.2.25` and `release/1.2.31` tags of Cacti; Cloudflare facts come
from [`research/cloudflare-api-facts.md`](research/cloudflare-api-facts.md).

## Summary

| Component | Supported | Tested |
|---|---|---|
| Cacti | 1.2.25 through 1.2.31 (`compat = 1.2.25` in INFO) | 1.2.31 by `make smoke`; 1.2.25 by `make smoke CACTI_REF=release/1.2.25`, passed on 2026-10-22 |
| PHP | 7.4 through 8.4 | Unit tests on 7.4, 8.1, 8.2, 8.3, 8.4; integration on 7.4 and 8.3; smoke on 8.0; `php -l` on 7.4 |
| Database | MariaDB 10.6 and later, MySQL 8.0 and later | CI: MariaDB 10.11, MySQL 8.4. Integration tests and BDD features also run locally on 2026-10-22 against MariaDB 10.6.28 and MySQL 8.0.46; not part of CI |
| PHP extensions | `curl`, `openssl`, `pdo_mysql` | Checked by `plugin_cloudflare_check_config()` |
| Cacti 1.3 | Not a release target | Not tested |

## Cacti

### PHP floor

The plugin keeps PHP 7.4 syntax. Cacti 1.2.25 through 1.2.30 have no
Composer platform check and run on PHP 7.4, and INFO declares
`compat = 1.2.25`.

Cacti 1.2.31 requires PHP 8.0 or later. Its `composer.json` declares
`"php": ">=8.0"`, and `include/vendor/composer/platform_check.php` stops on
older versions. `include/global.php:555` loads that check through the
Composer autoloader. On 1.2.31 the plugin therefore runs on PHP 8.0 or later,
and the install smoke uses the `php:8.0-apache` image.

### Hooks and APIs used

The plugin changes no Cacti core files. It registers four hooks and two
realms in `plugin_cloudflare_install()`:

| Interface | Use | Reference (1.2.31) |
|---|---|---|
| `config_arrays` hook | Adds "Cloudflare Accounts" under Configuration when enabled | `include/global_arrays.php` |
| `config_settings` hook | Adds the Settings > Cloudflare tab and runs pending migrations | `include/global_settings.php` |
| `draw_navigation_text` hook | Breadcrumbs for `cloudflare_accounts.php` | `lib/functions.php` |
| `poller_bottom` hook | Starts the background collector after each polling cycle, on the main poller only | `poller.php:943`, `poller.php:956` |
| `exec_background()` | Launches `poller_cloudflare.php` detached from `poller.php`. The plugin passes the arguments as a string, because arrays are accepted only from 1.2.31 | `lib/poller.php:132` |
| `api_plugin_register_realm()` | "Cloudflare: View" (`cloudflare.php`) and "Cloudflare: Configure" (`cloudflare_accounts.php`) | `lib/plugins.php` |
| `api_plugin_valid_entrypoint()` | Requires registration calls to come from a function whose name contains install, upgrade or setup, so registration stays inside `plugin_cloudflare_install()` | `lib/plugins.php:1124` |
| `api_plugin_uninstall()` | Cacti removes hooks, realms and `plugin_config` after the plugin's uninstall function returns | `lib/plugins.php:801` |
| `api_plugin_disable_hooks()`, `api_plugin_load_realms()` | Cacti keeps realms loaded and the `config_arrays` hook active while a plugin is disabled, so each plugin entry point checks `api_plugin_is_enabled()` | `lib/plugins.php:1103`, `lib/plugins.php:1268` |
| Realm check in `include/auth.php` | Enforces a page's realm by its basename | `include/auth.php:183` |
| `$database_sessions` | The plugin reuses Cacti's PDO handle | `lib/database.php:129` |
| `db_connect_real()` | Opens Cacti's connection with the arguments Cacti uses when no handle exists yet | `include/global.php:363` |

### The `poller_bottom` hook on upgrade

Version 0.2.0 added the `poller_bottom` hook. `api_plugin_register_hook()`
inserts a hook that is not a configuration hook with status 0, which is
disabled, unless the caller asks for it to be enabled (`lib/plugins.php:1030`,
with the `$enable` branch at `lib/plugins.php:1048`). Cacti does not enable a plugin again when it is
already enabled, so an upgrade from 0.1.0 would leave the new hook off and the
collector would never start. `plugin_cloudflare_upgrade()` therefore registers
the hook, for an installation older than 0.2.0, with the plugin's current
enabled state. A new install registers it with the other hooks, and Cacti
enables all of a plugin's hooks when the plugin is enabled.

The hook runs only on the main poller. `poller.php` also runs `poller_bottom`
on remote pollers (`poller.php:956`), and the hook returns without doing
anything when `poller_id` is not 1. INFO declares `remote_collect:0`.

Core Cacti never calls a plugin upgrade function, so
`plugin_cloudflare_upgrade()` runs from `plugin_cloudflare_check_config()`,
from the `config_settings` hook on `settings.php` and `plugins.php`, and at
the top of `cloudflare_accounts.php` and `cloudflare.php`.

Every Cacti function and hook above exists in `release/1.2.25`. The page
calls `CactiSecureHeaders::getNonceAttribute()` only when that class exists,
because older 1.2 releases do not have it.

### The smoke on 1.2.25

`make smoke CACTI_REF=release/1.2.25` builds the same PHP 8.0 image from the
1.2.25 tag, tagged `cf-smoke-cacti:1.2.25`, and runs every smoke step,
including device creation, graph creation and the RRD checks. On 2026-10-22
all steps passed. `cli/plugin_manage.php` in 1.2.25 enables a plugin only
together with `--install`, so for an installed plugin the smoke calls
`api_plugin_enable()`, as `plugins.php` does.

Helpers added after 1.2.25, such as `cacti_redact_sensitive()` and
`cacti_plugin_path()`, are not called. The plugin carries its own redaction.

### Known issue: poller output warnings on some MariaDB releases

On MariaDB 10.11.19, 11.4.13 and 11.8.9, the `DELETE` that Cacti runs on
the MEMORY table `poller_output`, after its join `SELECT` in
`process_poller_output()`, leaves rows behind: in a reproduction it removed
2 of 3 rows. MariaDB 10.6.28, 10.11.10 and MySQL 8.4 removed all 3. On the
affected releases Cacti logs "There are N Data Sources not returning all
data" and "Poller Output Table not Empty" on every poll for any data source
with more than one poller item, including Cacti's own, not only the
plugin's. The RRD values are unaffected: the update is built before the
`DELETE`, and Cacti removes the leftover rows on its next pass. The install
smoke pins MariaDB 10.11.10 for this reason, and fails on either warning.

## PHP

`composer.json` sets `config.platform.php = 7.4.33`, so the dev tools resolve
for PHP 7.4. PHPStan runs with `phpVersion: 70400`. The plugin has no runtime
Composer dependencies; `includes/autoload.php` loads `src/` with a bundled
PSR-4 loader when `vendor/` is absent, which is the case in a release archive.

`#[\SensitiveParameter]` marks token parameters. PHP 8.2 and later hide
those arguments from stack traces. PHP 8.0 and 8.1 ignore the unknown
attribute, and PHP 7.4 reads the line as a comment. See
[security.md](security.md#known-limitations).

## Database

The schema uses InnoDB, utf8mb4, a foreign key with `ON DELETE CASCADE`,
`GET_LOCK()` for migrations, and `INT UNSIGNED` epoch timestamps
([schema.md](schema.md)). Tests run with Cacti's session `sql_mode`, which
removes the strict modes.

MariaDB and MySQL report one failure differently. Dropping a table that
another table references fails with SQLSTATE `23000` and error 1451 on
MariaDB, and with SQLSTATE `HY000` and error 3730 on MySQL (seen on 8.0.46
and 8.4.11). `MigratorTest` accepts both.

## Cloudflare API

The plugin makes read-only `GET` requests to `https://api.cloudflare.com`
only. Verify makes the first four requests below, from the web interface when
an administrator runs it. The collector makes the last two.

| Request | Purpose | Reference |
|---|---|---|
| `GET /client/v4/accounts/{account_id}/tokens/verify` | Check the token's status | [Token verification](research/cloudflare-api-facts.md#1-token-verification) |
| `GET /client/v4/user/tokens/verify` | Fallback when the account endpoint answers with an auth or permission error, as it does for user-owned tokens | [Live verification](research/cloudflare-api-facts.md#live-verification-2026-10-21) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?per_page=1` | Probe for tunnel read permission | [Cloudflare Tunnel](research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/zones?account.id={account_id}&per_page=5` | Probe for zone read permission | [Token permissions](research/cloudflare-api-facts.md#3-token-permissions) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel?is_deleted=false&per_page=1000&page=N` | List the account's tunnels, up to 50 pages | [Cloudflare Tunnel](research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |
| `GET /client/v4/accounts/{account_id}/cfd_tunnel/{tunnel_id}/connections` | List one tunnel's connectors and connections | [Cloudflare Tunnel](research/cloudflare-api-facts.md#2-cloudflare-tunnel-cfd_tunnel) |

The token permissions these requests need are in
[token-permissions.md](token-permissions.md).

Error handling follows the live observations in the research file: HTTP 400
with error code 6003 or 6111 means a malformed token and is classed as an
authentication failure. Requests from the web interface, such as Verify, are
retried at most once. A 429 there is retried after the `retry-after` delay
when that is 2 seconds or less, after the normal 1 second backoff when the
header is absent, and not at all when the delay is longer. The collector
retries at most twice and waits for a `retry-after` of up to 30 seconds;
a longer delay fails the request, and the job runs again after that delay,
but not sooner than 60 seconds.
Cloudflare answers a connections request for an unknown tunnel with HTTP 401
and error code 1001, which the client classes as `not_found` and not as an
authentication failure.
