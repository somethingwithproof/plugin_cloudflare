# Compatibility

This page records the versions the plugin supports, what is tested, and the external interfaces it depends on. Cacti facts come from the
`release/1.2.25` and `release/1.2.31` tags of Cacti; Cloudflare facts come
from [`research/cloudflare-api-facts.md`](research/cloudflare-api-facts.md).

## Summary

| Component | Supported | Tested |
|---|---|---|
| Cacti | 1.2.25 through 1.2.31 (`compat = 1.2.25` in INFO) | 1.2.31 by `make smoke`; 1.2.25 by `make smoke CACTI_REF=release/1.2.25`. Both passed all 22 steps on 2026-10-22 |
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

### Cacti APIs and tables used for devices, graphs and templates

Version 0.3.0 calls these Cacti functions, all with the signatures of both
releases. Lines are in the `release/1.2.25` and `release/1.2.31` tags.

| Interface | Use | 1.2.25 | 1.2.31 |
|---|---|---|---|
| `api_user_realm_auth()` | Checks the `host.php` realm | `lib/plugins.php:35` | `lib/plugins.php:35` |
| `api_device_save()` | Creates the device (26 arguments, `poller_id` 1) | `lib/api_device.php:939` | `lib/api_device.php:939` |
| Reindex method None for SNMP version 0 | `api_device_save()` sets it | `lib/api_device.php:1073-1077` | `lib/api_device.php:1073-1077` |
| `api_device_update_host_template()` | Called from `api_device_save()`; re-adds the template's queries with the global default method | call `:1131`, function `:1226`, `REPLACE` `:1266` | call `:1131`, function `:1245`, `REPLACE` `:1285` |
| `api_device_dq_add()` | Sets reindex method None again, then runs the query | `lib/api_device.php:552-578` | `lib/api_device.php:552-578` |
| `api_device_remove()` | Removes a host the plugin created but could not record | `lib/api_device.php:45` | `lib/api_device.php:45` |
| `DATA_QUERY_AUTOINDEX_NONE` | Reindex method None | `include/global_constants.php:75` | `include/global_constants.php:75` |
| `run_data_query()` | Reindex with `$force` true | `lib/data_query.php:25` | `lib/data_query.php:25` |
| Orphaning and title refresh in `run_data_query()` | Orphan mark; `$force` title refresh | `lib/data_query.php:317`, `:356-368` | `lib/data_query.php:317`, `:356-368` |
| `get_data_query_array()` | Reads the data query XML | `lib/data_query.php:599`, no path check, `file_exists()` at `:612` | `lib/data_query.php:599`, path check `:612-620` |
| `cacti_path_is_within()` | The 1.2.31 path check, using `realpath()` | not present | `lib/functions.php:8058` |
| `query_script_host()` | Runs the script as a CLI script for index, query and num_indexes | `lib/data_query.php:635`, script server prefix `:646` | `lib/data_query.php:645`, script server prefix `:656` |
| `output_delimiter` split | Splits a listing line at the first `!` | `lib/data_query.php:738` | `lib/data_query.php:748` |
| Script server `get` call | `<arg_prepend>`, `get`, field, index | `lib/utility.php:431` (index unquoted) | `lib/utility.php:544` (index quoted) |
| Script server include and function checks | Where `ss_cloudflare.php` may live | no base path check (`script_server.php:261`, `:278`) | `script_server.php:264-290`, `:327-361` |
| `get_best_data_query_index_type()` | Index type for a new graph | `lib/data_query.php:2285` | `lib/data_query.php:2318` |
| `import_xml_data()` | Imports the templates by hash | `lib/import.php:25`, failure returns `:39`, `:56`, `:177-227`, `result` `fail` `:901`, `:1209`, `:1477` | `lib/import.php:25`, failure returns `:39`, `:56`, `:177-227`, `result` `fail` `:978`, `:1286`, `:1554` |
| Template hash version `0103` | Hash prefix of the shipped XML | `include/global_arrays.php:135-140` (1.2.20 through 1.2.25) | `include/global_arrays.php:135-146` (1.2.20 through 1.2.31) |
| `create_complete_graph_from_template()` | Creates a graph and its data source | `lib/template.php:1480` | `lib/template.php:1480` |
| `data_source_exists()` | Lets a tunnel's later graphs reuse its data source | `lib/template.php:2194`, call `:1596`, reuse `:1615` | `lib/template.php:2194`, call `:1594`, reuse `:1613` |
| `push_out_host()` | Pushes a new data source to the poller | `lib/utility.php:687` | `lib/utility.php:834` |
| `retemplate_graphs()`, `update_replication_crc()` | Loaded because the import calls them | `lib/template.php:873`, `lib/utility.php:29` | `lib/template.php:873`, `lib/utility.php:29` |
| `host.php` includes | The libraries the plugin loads before saving a device | `host.php:26-39` | `host.php:26-39` |
| `cli/add_device.php`, `cli/add_graphs.php` | Model for the device arguments and for adopting existing graphs | `add_device.php:199`, `add_graphs.php:659-716` | `add_device.php:199`, `add_graphs.php:659-716` |
| Realm for `host.php` | Realm 3, Sites/Devices/Data | `include/global_arrays.php:1227`, `:1285` | `include/global_arrays.php:1240`, `:1298` |
| `read_config_option()`, `set_config_option()` | Template hash and missing-host settings | `lib/functions.php:679`, `:419` | `lib/functions.php:680`, `:418` |
| `db_fetch_cell_prepared()` | Reads Cacti tables | `lib/database.php:568` | `lib/database.php:665` |
| `raise_message()`, `is_error_message()` | Result of device creation and of `api_device_save()` | `lib/functions.php:993`, `:890` | `lib/functions.php:996`, `:893` |

Tables the plugin reads or writes in Cacti's schema, at their `CREATE TABLE`
line in `cacti.sql` (1.2.25 / 1.2.31). The plugin reads them with prepared
statements; the writes go through the Cacti functions above.

| Table | Use | Lines |
|---|---|---|
| `host` | Existence and `deleted` flag of the device | 1876 / 1877 |
| `host_snmp_query` | Reindex method, written by `api_device_dq_add()` | 1986 / 1987 |
| `host_snmp_cache` | The indexes of the last reindex, checked before a graph is made | 1959 / 1960 |
| `graph_local` | Finds a graph that exists for a tunnel and template | 1601 / 1602 |
| `graph_templates_item`, `data_template_rrd` | Find the data source of an existing graph | 1778 / 1779, 1557 / 1558 |
| `data_source_profiles` | The default profile the import needs | 1304 / 1305 |
| `settings` | `cloudflare_templates_sha256` and `cloudflare_missing_host_<account id>`, through `set_config_option()` | 2411 / 2412 |
| `host_template`, `snmp_query`, `data_template`, `graph_templates`, `snmp_query_graph` | Resolve the imported objects by hash | 2003 / 2004, 2471 / 2471, 1510 / 1511, 1656 / 1657, 2491 / 2491 |

The line differences between the two releases are the reason the table lists
both. Every function above exists in both releases. The behavior that differs
is the data query path check, the script server's base path checks and the
quoting of the index in the `get` call.

### The 1.2.31 resource-path rule

From 1.2.31, `get_data_query_array()` refuses a data query XML that does not
resolve, through `realpath()`, to a place inside `<path_cacti>/resource`
(`lib/data_query.php:612-620`, `lib/functions.php:8058`). 1.2.25 reads any
existing file. The plugin installs its XML at
`<base_path>/resource/script_server/cloudflare_tunnels.xml` in both, so one
template XML works on both releases. A symbolic link from `resource/` into the
plugin directory fails the 1.2.31 check, so the plugin copies the file and
does not link it. The poller's script path stays in the plugin directory,
which the 1.2.31 script server accepts because it is under `base_path`.

### The smoke on 1.2.25

`make smoke CACTI_REF=release/1.2.25` builds the same PHP 8.0 image from the
1.2.25 tag, tagged `cf-smoke-cacti:1.2.25`, and runs every smoke step,
including device creation, graph creation and the RRD checks. The smoke has 22
steps (`tests/docker/smoke.sh`). On 2026-10-22 all 22 passed on 1.2.31 and on
1.2.25, with MariaDB 10.11.10.

The device and graph steps (5f to 5j) create the device over HTTP, run one due
poll that reindexes and creates 9 graphs for the three fixture tunnels, and
check blog's RRD for `connectors` 1, `connections` 1 and `health` 3. Under a
Cloudflare 503 they check that `connectors`, `connections` and `health` read
`U`, and that the values return and the graph count stays at 9 after Cloudflare
answers again. Step 6 checks that uninstall leaves the 9 graphs in place. `cli/plugin_manage.php` in 1.2.25 enables a plugin only
together with `--install`, so for an installed plugin the smoke calls
`api_plugin_enable()`, as `plugins.php` does.

Helpers added after 1.2.25, such as `cacti_redact_sensitive()` and
`cacti_plugin_path()`, are not called. The plugin carries its own redaction.

### Known issue: poller output warnings on some MariaDB releases

On MariaDB 10.11.19, 11.4.13 and 11.8.9, the `DELETE` that Cacti runs on the
MEMORY table `poller_output`, after its join `SELECT` in
`process_poller_output()` (`lib/poller.php:539` in 1.2.31), leaves rows
behind: in a reproduction it removed 2 of 3 rows. MariaDB 10.6.28, 10.11.10
and MySQL 8.4 removed all 3. On the affected releases Cacti logs "There are N
Data Sources not returning all data" (`lib/poller.php:857`) and "Poller Output
Table not Empty" (`poller.php:608`, which also calls `admin_email()`) on every
poll for any data source with more than one poller item, including Cacti's
own, not only the plugin's. The RRD values are unaffected: the update is built
before the `DELETE`, and Cacti removes the leftover rows on its next pass. The
install smoke pins MariaDB 10.11.10 for this reason, and fails on either
warning.

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
