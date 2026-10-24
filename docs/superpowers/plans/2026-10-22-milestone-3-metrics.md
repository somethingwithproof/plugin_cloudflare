# Milestone 3: Metrics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the state that discovery already stores into Cacti data sources and graphs. Each Cloudflare account can become one Cacti device whose tunnels are indexed by a script-server data query, and the poller records an RRD value only when the observation behind it is fresh and was collected successfully.

**Architecture:** The collector keeps writing current state to plugin tables; it never writes a time series. `ObservationReader` turns a tunnel row into versioned observations, or into unknown with a reason. `ss_cloudflare.php` reads only plugin tables through that reader and prints `U` for anything it cannot vouch for. The templates are generated from one metric catalog, imported with Cacti's own `import_xml_data()`, and used through Cacti's device, data query and graph APIs.

**Tech Stack:** As in Milestone 2. PHP 7.4 syntax, PDO through `Database`, PHPUnit 9.6, Behat, Eris, Docker (Cacti 1.2.31), rrdtool in the smoke image.

**Spec:** `docs/spec.md` (12 item 3; sections 2.3, 3.4, 3.6, 5, 7 invariant B, 8, 11 and 13), `docs/design.md`, `docs/schema.md`, and `docs/research/cloudflare-api-facts.md` (no GraphQL dataset covers cloudflared tunnels, so every metric comes from REST state already stored).

## Global Constraints

- Everything in the Milestone 1 and 2 plans' Global Constraints and the M2 ledger rulings still applies: PHP 7.4 syntax, bound SQL, no token in any message, `git commit -s`, Cacti style in glue, handlers as functions in `includes/`, new top-level PHP files added to sonar, phpstan, cs, lint74, coverage and `tests/build/package.sh` (M2 R2).
- **Object model.** An account maps to at most one Cacti device, made from the plugin's device template with SNMP version 0, availability method `'0'` (a string; `cli/add_device.php:199` notes that `AVAIL_NONE` fails validation) and `poller_id` 1. The hostname is `<cf_account_id>.cloudflare.invalid`, a reserved name that never resolves. Tunnels are indexes of a script-server data query keyed by `cf_tunnel_id`. Only the admin's "Create Cacti device" action creates a device; nothing creates one automatically.
- **Two realms for device and graph actions.** "Create Cacti device", and turning on `create_graphs`, require `cloudflare_accounts.php` and `api_user_realm_auth('host.php')` (`lib/plugins.php:35`), so the Configure realm alone cannot create Cacti devices.
- **Invariant B.** A value is printed only when every condition below holds; otherwise the field is `U`, never `0`. `window = 2 * discovery_interval + 60` seconds, and `age = now - observed_at`.
  - The account's job `last_status` is `ok` or `partial`.
  - The tunnel's `lifecycle` is `active`.
  - `observed_at` is not null and `-60 <= age <= window`. An age between -60 and 0 counts as 0; below -60 is `clock_skew`.
  - For connection metrics, `connections_attempted_at <= connections_refreshed_at`, so the latest attempt succeeded.
- Observation times: the connection metrics use `connections_refreshed_at`, and health uses `last_seen_at`.
- Collection metrics are the exception. `collect_age` (`now - connections_refreshed_at`) and `collect_failures` (`jobs.consecutive_failures`) are printed for `active` and `unavailable` tunnels because they describe the outage, and are `U` for `deleted` tunnels and for tunnels never refreshed.
- Health numbers: `healthy` 3, `degraded` 2, `down` 1, `inactive` 0. Metric schema version 1 (`Version::METRIC_SCHEMA`, already 1) ships gauges only. The `type` field still distinguishes `GAUGE` and `COUNTER` for later engines.
- `ss_cloudflare.php` never calls the Cloudflare API and never throws. It reads the clock with PHP `time()` on the main poller, the same clock the collector stamps with.
- The data query index lists every tunnel of the account, `deleted` included. A deleted tunnel keeps its index, graph and RRD, and prints `U`. Cacti marks a data source orphaned when its index disappears (`lib/data_query.php:317`), and this avoids that.
- **Data query XML location.** On 1.2.31, Cacti reads a data query XML only from inside `<path_cacti>/resource`, after `realpath()` (`lib/data_query.php:612-620`, `lib/functions.php:8058`). The plugin therefore copies `templates/resource/cloudflare_tunnels.xml` to `<base_path>/resource/script_server/cloudflare_tunnels.xml`. A symbolic link fails the check. The script itself stays in the plugin directory: script server 1.2.31 accepts any include and function file under `base_path` (`script_server.php:264-290`, `:327-361`).
- The device's data query uses reindex method None (`DATA_QUERY_AUTOINDEX_NONE`, `include/global_constants.php:75`). `api_device_save()` sets it for SNMP version 0 (`lib/api_device.php:1073-1077`), but `api_device_update_host_template()` then re-adds the query with the global default (`:1129-1131`, `:1285`). The plugin reindexes itself with `run_data_query()` after each `ok` or `partial` discovery.
- Templates are imported idempotently by hash through `import_xml_data()` (`lib/import.php:25`). The hash version code is `0103`, valid from 1.2.20 to 1.2.31 (`include/global_arrays.php:135-146`). Uninstall keeps devices, graphs, data sources, RRD files, templates and the resource XML, as the existing retention decision requires.
- No GraphQL, no Thold, no cloudflared local metrics; those belong to M4 or are out of scope.
- M3 bumps INFO and `Version::PLUGIN` to `0.3.0`.

## Review Focus

- **The collector silently stops.** The job still reads `ok`, but nothing has refreshed the tunnel for hours. Values turn `U` once `age > window`. Test: `testCollectorStoppedGoesUnknownAfterWindow` (Task 2).
- **A tunnel deleted while graphs exist.** It stays in the index and prints `U`, and the sync removes nothing. Tests: `testDeletedTunnelStaysIndexedAndPrintsU` (Task 4) and `testSyncNeverRemovesCactiObjects` (Task 8).
- **An admin deletes the account's device in Cacti.** The sync skips that account and logs once at LOW; only the Create action makes a new device. Test: `testDeletedDeviceIsNotRecreated` (Task 8).
- **Clock skew.** `observed_at` is more than 60 seconds in the future, or the DB and PHP disagree. The value is `U` with reason `clock_skew`, never a negative age. Test: `testFutureObservationBeyondToleranceIsUnknown` (Task 2).
- **The script server gets an unknown index or junk arguments.** These include another account's tunnel, an uppercase UUID, `1;DROP`, empty strings and an unreachable database. Every `get` returns `U` and nothing throws. Test: `testUnknownIndexAndBadInputPrintU` (Task 4).

---

### Task 1: Schema M003 (resources and metric mappings)

**Files:** create `src/Storage/Migrations/M003Metrics.php`; modify `src/Storage/Migrator.php` (`defaultMigrations`, `dropAll` order) and `docs/schema.md`; test in `tests/Integration/Storage/M003MetricsTest.php`.

**Interfaces:**

`plugin_cloudflare_resources` columns:
- `id INT UNSIGNED AI PK`
- `account_id INT UNSIGNED NOT NULL`, with an FK to accounts, `ON DELETE CASCADE`
- `kind ENUM('device','graph') NOT NULL`
- `tunnel_id INT UNSIGNED NOT NULL DEFAULT 0`. It is 0 for the device row. There is no FK: tunnel rows are never deleted, only cascaded with the account.
- `template_hash CHAR(32) NOT NULL DEFAULT ''`, the graph template hash (empty for the device row)
- `host_id INT UNSIGNED NOT NULL`
- `local_graph_id INT UNSIGNED NULL`
- `local_data_id INT UNSIGNED NULL`
- `created_at INT UNSIGNED NOT NULL`
- `UNIQUE account_resource (account_id, kind, tunnel_id, template_hash)`, `UNIQUE graph (local_graph_id)`, `KEY host (host_id)`

`plugin_cloudflare_metric_mappings` columns:
- `metric_name VARCHAR(64) PK`
- `schema_version SMALLINT UNSIGNED NOT NULL`
- `ds_type ENUM('GAUGE','COUNTER') NOT NULL`
- `unit VARCHAR(16) NOT NULL`
- `query_field VARCHAR(32) NOT NULL`
- `data_template_hash CHAR(32) NOT NULL`
- `rrd_ds_name VARCHAR(19) NOT NULL`
- `UNIQUE (data_template_hash, rrd_ds_name)`

Column changes:
- `plugin_cloudflare_tunnels` gains `connections_error_class VARCHAR(20) NULL`, after `connections_attempted_at`.
- `plugin_cloudflare_accounts` gains `create_graphs TINYINT(1) NOT NULL DEFAULT 0`, after `collect_analytics`.

Steps:
- [ ] Write the tests:
  - `testUpgradeFromV2AddsMetricsTables` (migrate to 2, then all)
  - `testM003Idempotent`
  - `testOneDeviceRowPerAccount` (a duplicate insert gives SQLSTATE 23000)
  - `testGraphIdUnique`
  - `testResourcesCascadeOnAccountDelete`
  - `testDropAllRemovesMetricsTables`
- [ ] Run them and confirm they fail (RED).
- [ ] Implement, using M002's `columnExists` and `foreignKeyExists` checks. `dropAll` drops resources and metric_mappings before accounts.
- [ ] Run the tests (GREEN) and update `docs/schema.md`.
- [ ] Commit `feat: add resources and metric mapping schema`.

### Task 2: Metric catalog and observation reader

**Files:** create `src/Metrics/Catalog.php`, `src/Metrics/Metric.php`, `src/Metrics/Observation.php`, `src/Metrics/ObservationReader.php` and `docs/metrics.md`; tests in `tests/Unit/Metrics/CatalogTest.php` and `tests/Integration/Metrics/ObservationReaderTest.php`.

**Interfaces:**
- `Metric` is immutable: `name`, `queryField`, `dsName`, `type` (`GAUGE` or `COUNTER`), `unit`, `min` (int), `max` (`?int`, null is `U`), and `kind` (`state` or `collection`).
- `Catalog::all(): Metric[]` returns these rows, in this order:

  | name | queryField | dsName | unit | max | kind |
  |---|---|---|---|---|---|
  | `cloudflare_tunnel_health` | `health` | `health` | `state` | 3 | state |
  | `cloudflare_tunnel_connectors` | `connectors` | `connectors` | `connectors` | U | state |
  | `cloudflare_tunnel_connections` | `connections` | `connections` | `connections` | U | state |
  | `cloudflare_tunnel_connector_versions` | `versions` | `versions` | `versions` | U | state |
  | `cloudflare_collection_age` | `collectAge` | `collect_age` | `seconds` | U | collection |
  | `cloudflare_collection_failures` | `collectFailures` | `collect_fail` | `failures` | U | collection |

  All six are `GAUGE` with min 0.
- `Catalog::byField(string $field): ?Metric`.
- `Catalog::HEALTH = ['inactive'=>0,'down'=>1,'degraded'=>2,'healthy'=>3]`.
- `Observation` is immutable:
  - `metric` (string)
  - `value` (`?int`)
  - `observedAt` (`?int`)
  - `reason` (`?string`). A null reason goes with a non-null value. Otherwise it is one of the `ErrorClass` constants (the stored job or tunnel class), `stale`, `clock_skew`, `not_active`, `never_collected` or `unknown_tunnel`.
  - `schemaVersion` (int)
- `Observation::rrdValue(): string` returns the integer as a string, or `'U'`.
- `ObservationReader(Database $db, Clock $clock)`:
  - `accountForHost(int $hostId): ?int` reads the `kind='device'` resource row.
  - `tunnelIndex(int $accountId): array<string, string>` maps `cf_tunnel_id` to name, for every lifecycle, ordered by `cf_tunnel_id`.
  - `observe(int $accountId, string $cfTunnelId): array<string, Observation>` is keyed by `queryField`. It makes one query joining tunnels, jobs and accounts, plus `COUNT(DISTINCT version)` from connectors.
  - `static window(int $interval): int` returns `2 * $interval + 60`.
  - The rules are the Invariant B lines in Global Constraints.

Steps:
- [ ] Write the tests:
  - `testCatalogNamesAndDsNamesAreUniqueAndShort` (DS names match `^[a-z_]{1,19}$`)
  - `testHealthMapping` (a data provider over the four statuses)
  - `testFreshSuccessfulTunnelHasValues` (connectors 2, connections 4, versions 1, health 3)
  - `testFailedJobMakesStateUnknown` (`last_status='failed'` gives reason `transient` and `U`; `collectFailures` is still printed)
  - `testFailedConnectionsAttemptIsUnknown` (`attempted > refreshed`, with `connections_error_class='transient'`)
  - `testCollectorStoppedGoesUnknownAfterWindow` (interval 300: age 660 gives a value, 661 gives `stale`)
  - `testFutureObservationBeyondToleranceIsUnknown` (+61 s gives `clock_skew`; +60 s gives age 0)
  - `testUnavailableKeepsCollectionMetricsOnly`
  - `testDeletedTunnelAllUnknown`
  - `testZeroConnectorsAfterSuccessfulReadIsRealZero`
  - `testConnectorRestartKeepsGauge` (a new connector id at the same count gives the same values, with nothing negative)
  - `testUnknownTunnelIsUnknown`
- [ ] Run them and confirm they fail (RED).
- [ ] Implement.
- [ ] Write `docs/metrics.md`. It covers the catalog table, the health numbers, the Invariant B rules with the window formula, the reason values, and schema version 1. It explains that Cacti samples the latest observation on every poll, so a gauge can repeat between discoveries and the RRD timestamp is the poll time, up to one discovery interval after `observed_at`. It also states that gauges only means counter resets cannot occur in v1.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: normalize tunnel state into versioned observations`.

### Task 3: Record the per-tunnel failure class

**Files:** modify `src/Discovery/DiscoveryService.php` (the connections failure branch), `src/Discovery/TunnelRepository.php` (`markConnectionsAttempted`) and `src/Discovery/Reconciler.php` (`applyConnections`); tests in `tests/Integration/Discovery/DiscoveryServiceTest.php` and `ReconcilerTest.php`.

**Interfaces:**
- `TunnelRepository::markConnectionsAttempted(int $tunnelId, int $now, ?string $errorClass = null): int` writes `connections_error_class`.
- `Reconciler::applyConnections` sets `connections_error_class = NULL` in the same `UPDATE` that stamps `connections_refreshed_at`.

Steps:
- [ ] Write the tests: `testConnectionsFailureRecordsClass` (transient, and not_found from a 401 with code 1001) and `testSuccessfulReadClearsClass`.
- [ ] Run them and confirm they fail (RED).
- [ ] Implement.
- [ ] Run them (GREEN), plus the whole Discovery suite.
- [ ] Commit `feat: keep the last connections failure class per tunnel`.

### Task 4: Script server functions

**Files:** create `ss_cloudflare.php`, `includes/script_server.php` and `docs/collector-protocol.md`; tests in `tests/Unit/Glue/ScriptServerTest.php` and `tests/Integration/Glue/ScriptServerHandlersTest.php`.

**Interfaces:**
- `ss_cloudflare.php` follows `scripts/ss_host_cpu.php`:
  - With `$called_by_script_server` unset, it includes `../../include/cli_check.php` and prints `call_user_func_array('ss_cloudflare', $argv)`. This path serves reindex, which runs the script as a CLI (`lib/data_query.php:656-658`).
  - It defines `ss_cloudflare($host_id = '', $cmd = '', $arg1 = '', $arg2 = '')` in this file, because script server 1.2.31 rejects a function defined outside `base_path` (`script_server.php:358`). The function delegates to `cloudflare_ss()`.
  - It begins with `error_reporting(0)`.
- `cloudflare_ss(string $hostId, string $cmd, string $arg1, string $arg2, ?ObservationReader $reader = null) : string`. Arguments arrive in the order the XML's `<arg_prepend>|host_id|</arg_prepend>` and Cacti's `arg_get field "index"` give them (`lib/utility.php:544`).
  - `index` prints one `cf_tunnel_id` per line.
  - `num_indexes` returns the count.
  - `query tunnelId|tunnelName` prints `id!value` lines.
  - `query <output field>` prints `id!U` lines.
  - `get <field> <index>` returns `Observation::rrdValue()`.
  - `host_id` must match `^[1-9][0-9]{0,9}$`. The field must be `tunnelId`, `tunnelName` or a catalog `queryField`, and the index must pass `Ids::isUuid`. A failed check gives an empty listing, or `U` for `get`. Any `Throwable` is caught and gives `U`, and only its class is logged, at `POLLER_VERBOSITY_HIGH`.
- `docs/collector-protocol.md` covers:
  - the call forms above and the output grammar
  - the `U` rules (see `docs/metrics.md`)
  - which tables are read, and that the script makes no network I/O
  - the main-poller requirement (INFO `remote_collect:0`)
  - the fields a second engine must write to stay compatible

Steps:
- [ ] Write the tests:
  - `testIndexListsAllLifecyclesSorted`
  - `testQueryNamesUseDelimiter` (a name containing `!` is kept whole after the first delimiter, matching `lib/data_query.php:748`)
  - `testGetReturnsReaderValue`
  - `testDeletedTunnelStaysIndexedAndPrintsU`
  - `testUnknownIndexAndBadInputPrintU` (all the Review Focus inputs, plus a closed PDO)
  - `testScriptDefinesFunctionInItsOwnFile` (`ReflectionFunction::getFileName()` is `ss_cloudflare.php`)
  - `testScriptMakesNoTransportCalls` (static: no `CloudflareClient` and no `curl_` in `ss_cloudflare.php` or `includes/script_server.php`)
- [ ] Run them and confirm they fail (RED).
- [ ] Implement, and apply M2 R2 for `ss_cloudflare.php`.
- [ ] Write the protocol doc.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: serve tunnel observations to the script server`.

### Task 5: Templates generated from the catalog

**Files:** create `tests/build/generate_templates.php`, `templates/cloudflare_templates.xml` (generated) and `templates/resource/cloudflare_tunnels.xml` (generated); modify `Makefile` (a `templates` target) and `tests/build/package.sh` (require `cloudflare/templates/` and `cloudflare/ss_cloudflare.php`); test in `tests/Unit/Templates/TemplatesTest.php`.

**Interfaces:**
- `php tests/build/generate_templates.php <out dir>` writes both files from `Catalog::all()` and is byte-for-byte reproducible.
- Object hashes are `md5('plugin_cloudflare/' . <key>)`, where `<key>` is a fixed path such as `data_query`, `data_template`, `data_template_item/connectors`, `graph_template/connectors` or `graph_template_item/connectors/2`.
- XML element names are `hash_<type><0103><md5>`. The type codes are those in `include/global_arrays.php:1342`: `00` graph template, `01` data template, `02` host template, `04` data query, `08` data template item, `10` graph template item, `11` data query graph, and so on.
- Fields follow the export functions: `lib/export.php:25` (graph templates), `:180` (data templates), `:638` (host templates) and `:718` (data queries).
- The data template uses the built-in "Get Script Server Data (Indexed)" method, hash `332111d8b54ac8ce939af87a7eac0c06` (`cacti.sql:1111` in 1.2.25 and 1.2.31). Every DS is `GAUGE` with heartbeat 600, and min and max come from the catalog.
- Objects:
  - Device template "Cloudflare Account", with the data query attached.
  - Data query "Cloudflare Tunnels", with `xml_path` `<path_cacti>/resource/script_server/cloudflare_tunnels.xml`.
  - Data template "Cloudflare Tunnel", with six DS.
  - Graph templates "Cloudflare Tunnel - Connectors" (connectors, connections, versions), "Cloudflare Tunnel - Health" (health) and "Cloudflare Tunnel - Collection" (collect_age, collect_fail). Each title is `|host_description| - |query_tunnelName| - <suffix>`.
- The resource XML follows `resource/script_server/host_cpu.xml`:
  - `script_path` `|path_cacti|/plugins/cloudflare/ss_cloudflare.php`
  - `script_function` `ss_cloudflare`
  - `script_server` `php`
  - `arg_prepend` `|host_id|`, plus `arg_num_indexes`
  - `output_delimiter` `!`
  - `index_order` `tunnelId`, `index_order_type` `alphabetic`
  - input fields `tunnelId` and `tunnelName`, and one output field per catalog row

Steps:
- [ ] Write the tests:
  - `testGeneratedFilesMatchCommitted` (regenerate into a temp dir and compare bytes)
  - `testBothXmlFilesParse` (`simplexml_load_string`)
  - `testHashesStable` (a pinned md5 of the sorted hash list)
  - `testHashVersionIs0103`
  - `testOutputFieldsMatchCatalogAndScript` (the resource XML output fields equal `Catalog` query fields and the fields `cloudflare_ss` accepts)
  - `testDsNamesMatchCatalog`
  - `testScriptPathHasNoDotDot` (`lib/data_query.php:2389` rejects `..`)
- [ ] Run them and confirm they fail (RED).
- [ ] Implement the generator, run `make templates`, and commit the output.
- [ ] Run the tests (GREEN), then `make package`.
- [ ] Commit `feat: generate Cacti templates from the metric catalog`.

### Task 6: Template installation and 0.3.0 upgrade

**Files:** create `includes/templates.php` and `src/Metrics/MetricMappingRepository.php`; modify `setup.php`, `INFO`, `src/Version.php` and `tests/Support/CactiGlueStubs.php` (`import_xml_data`, `is_error_message`, with signature and file:line); tests in `tests/Unit/Glue/TemplateInstallTest.php`, `tests/Integration/Glue/SetupDatabaseTest.php` and `tests/Integration/Metrics/MetricMappingRepositoryTest.php`.

**Interfaces:**
- `cloudflare_templates_install() : bool`:
  1. Copies the resource XML to `$config['base_path'] . '/resource/script_server/cloudflare_tunnels.xml'` when that file is absent or its sha256 differs.
  2. Includes `lib/import.php` and calls `import_xml_data($xml, false, $profile_id)`, where `$profile_id` is ``SELECT id FROM data_source_profiles WHERE `default`='on'``. This runs only when the setting `cloudflare_templates_sha256` differs from the sha256 of the shipped XML, and the setting is written after a successful import.
  3. Calls `MetricMappingRepository::sync(Catalog::all(), $dataTemplateHash)`.
  - It returns false and logs one LOW line naming the path when the copy fails (unwritable directory). It never throws.
- `cloudflare_templates_ready() : ?array{host_template_id:int, data_query_id:int, data_template_id:int, graphs:array<string, array{graph_template_id:int, snmp_query_graph_id:int}>}` resolves IDs by hash. It returns null when any object or the resource file is missing.
- `MetricMappingRepository(Database $db)`: `sync(Metric[] $metrics, string $dataTemplateHash): void` (upsert by name, delete names not in the catalog) and `all(): array`.
- Setup:
  - `plugin_cloudflare_install()` calls `cloudflare_templates_install()` after the schema.
  - `plugin_cloudflare_upgrade()` calls it after migrations when the version changes.
  - Uninstall keeps every Cacti object and the resource XML. It only drops plugin tables and settings, as today.

Steps:
- [ ] Write the tests:
  - `testInstallCopiesResourceAndImportsOnce` (a second call makes no `import_xml_data` call)
  - `testChangedTemplateReimports`
  - `testUnwritableResourceDirLogsAndReturnsFalse`
  - `testReadyNullUntilImported`
  - `testMappingsSyncFromCatalog`
  - `testUpgradeFrom020RunsM003AndImportsTemplates` (installed version 0.2.0 ends at 0.3.0 with M003 applied)
  - `testUninstallKeepsResourceXml`
- [ ] Run them and confirm they fail (RED).
- [ ] Implement, and bump the version to 0.3.0.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: import tunnel templates on install and upgrade`.

### Task 7: Create Cacti device action

**Files:** create `src/Metrics/ResourceRepository.php` and `includes/cacti_objects.php`; modify `includes/accounts_page.php` (action 5 "Create Cacti device"), `includes/accounts.php` (the `create_graphs` checkbox), `src/Account/AccountService.php` and `Account.php` (the `create_graphs` flag), and `tests/Support/CactiGlueStubs.php` (`api_device_save`, `api_device_dq_add`, `run_data_query`); tests in `tests/Integration/Metrics/ResourceRepositoryTest.php`, `tests/Integration/Glue/AccountsPageHandlersTest.php` and `tests/Integration/Account/AccountServiceTest.php`.

**Interfaces:**
- `ResourceRepository(Database $db, Clock $clock)`:
  - `device(int $accountId): ?array`
  - `recordDevice(int $accountId, int $hostId): void` replaces the account's device row in one transaction. It throws `InvalidArgumentException` when another account already owns `$hostId`.
  - `graphs(int $accountId): array`
  - `recordGraph(int $accountId, int $tunnelId, string $templateHash, int $hostId, int $localGraphId, int $localDataId): void`
- `cloudflare_create_device(Account $account) : string` returns one of `created`, `exists`, `templates_missing`, `denied` or `failed`.
  1. Check both realms.
  2. Check `cloudflare_templates_ready()`.
  3. A device row whose `host` still exists gives `exists`. One whose host is gone falls through, because this action is the admin's explicit request.
  4. Call `api_device_save('0', $host_template_id, 'Cloudflare: ' . $name, $cf_account_id . '.cloudflare.invalid', '', 0, '', '', 161, 500, '', '0', 1, 23, 400, 1, '', '[None]', '', '[None]', '', '', 10, 1, 1, 0)` (`lib/api_device.php:939` in both versions). When `is_error_message()`, return `failed`.
  5. Call `api_device_dq_add($host_id, $data_query_id, DATA_QUERY_AUTOINDEX_NONE)` (`lib/api_device.php:552`), which also runs the data query.
  6. Call `recordDevice`.
- `create_graphs` is saved through `AccountService::save`. Turning it on also requires `host.php`, which the handler checks before calling the service. A denied change keeps the old value.
- The action is a POST with csrf-magic, like actions 1 to 4, and the result is shown through `raise_message`.

Steps:
- [ ] Write the tests:
  - `testRecordDeviceReplacesRowAndRejectsSharedHost`
  - `testCreateDeviceCallsCactiApisWithPinnedArguments` (`snmp_version` 0, availability `'0'`, `poller_id` 1, `.cloudflare.invalid`)
  - `testCreateDeviceIdempotentWhenHostExists`
  - `testCreateDeviceAfterAdminDeletedHostMakesNewDevice`
  - `testConfigureWithoutHostRealmDenied` (no Cacti call is recorded)
  - `testTemplatesMissingRefuses`
  - `testCreateGraphsFlagNeedsHostRealm`
- [ ] Run them and confirm they fail (RED).
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: create a Cacti device for an account on request`.

### Task 8: Graph sync after discovery

**Files:** modify `includes/cacti_objects.php`, `includes/polling.php` (`cloudflare_collector_main` calls the sync after `runDue`) and `tests/Support/CactiGlueStubs.php` (`create_complete_graph_from_template`, `get_best_data_query_index_type`, `push_out_host`); tests in `tests/Integration/Glue/CactiObjectsTest.php` and `tests/Integration/Glue/PollingTest.php`.

**Interfaces:**
- `cloudflare_sync_cacti_objects(array $outcomes, int $budget_seconds = 20, int $max_graphs = 50) : array{reindexed:int, graphs:int, skipped:int}`. It runs for each account whose outcome is `ok` or `partial` and that has a device row.
  - When `host` has no row with that id and `deleted = ''`, the account is skipped and logged at LOW once per distinct `host_id` (setting `cloudflare_missing_host_<account_id>`).
  - Otherwise it calls `run_data_query($host_id, $data_query_id, false, true)` (`lib/data_query.php:25`, same signature in 1.2.25). This picks up new tunnels and renames. `$force` is required for renames: without it the title cache refreshes only when an index changes (`lib/data_query.php:356-368`).
  - When `create_graphs = 1`, it handles each `active` tunnel and each graph template that has no `graph` resource row. If `graph_local` already has a matching `(host_id, snmp_query_id, snmp_index, graph_template_id)`, that graph is recorded and not created. Otherwise it calls `create_complete_graph_from_template($gt, $host_id, ['snmp_query_id'=>…, 'snmp_index_on'=>get_best_data_query_index_type($host_id, $dq), 'snmp_query_graph_id'=>…, 'snmp_index'=>$cf_tunnel_id], $empty)` (`lib/template.php:1480`, the pattern in `cli/add_graphs.php:629-716`), then `push_out_host($host_id, $local_data_id)` and `recordGraph`.
  - A tunnel with a `graph` row for a template is never given that graph again, even after the admin deletes it.
  - It never removes any Cacti object. It stops at the budget or at `$max_graphs`, and catches `Throwable` per account, logging the class.
- The second and later graph templates for a tunnel reuse its data source (`lib/template.php:1613`), so each tunnel has one RRD.

Steps:
- [ ] Write the tests:
  - `testReindexAfterSuccessfulDiscoveryOnly`
  - `testRenameRefreshesTitles` (`run_data_query` is called with `$force` true)
  - `testCreatesThreeGraphsPerActiveTunnelOnce` (a second sync creates nothing)
  - `testAdoptsExistingGraph`
  - `testAdminDeletedGraphNotRecreated`
  - `testCreateGraphsOffOnlyReindexes`
  - `testDeletedDeviceIsNotRecreated`
  - `testSyncNeverRemovesCactiObjects` (no remove calls are recorded across lifecycle changes)
  - `testGraphCapAndBudget`
  - `testSyncFaultDoesNotFailCollector`
- [ ] Run them and confirm they fail (RED).
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: reindex tunnels and create graphs after discovery`.

### Task 9: BDD scenarios

**Files:** create `features/cloudflare_outage.feature`, `features/metrics_integrity.feature` and `features/bootstrap/MetricsContext.php`; modify `features/bootstrap/World.php`, `features/bootstrap/DiscoveryContext.php` and `behat.yml.dist`.

**Interfaces:**
- The owner's text is used verbatim: "Cloudflare becomes unavailable" under `Feature: Cloudflare outage`, and "An API request fails" under `Feature: Metrics integrity`.
- Outage:
  - "unrelated Cacti polling must continue": `cloudflare_poller_bottom()` returns after one `exec_background` call and throws nothing.
  - "observations must be classified as unavailable": after three transient failures, every state metric is `U` with reason `transient`, and the tunnels are `unavailable`.
  - "graph history must remain intact": resource rows are unchanged and the glue stubs record no remove calls.
- Integrity:
  - Start with `connection_count` 4 and a fresh refresh.
  - A transient failure on `/connections` makes `cloudflare_ss(... 'get','connections', id)` return `U`, never `0`.
  - The stored row still says 4.
- The M2 step "existing resource associations must be preserved" also asserts that `plugin_cloudflare_resources` is unchanged.

Steps:
- [ ] Write the features. Run `behat --strict` and confirm it fails.
- [ ] Implement the contexts.
- [ ] Run it again (GREEN).
- [ ] Commit `test: add outage and metrics integrity scenarios`.

### Task 10: End-to-end metrics in the smoke stack

**Files:** modify `tests/docker/smoke.sh`, `tests/docker/screenshots.sh` and `docs/images/` (add `tunnel-graph.png`).

**Interfaces:** These steps come after M2's 5e, with each poll followed by `wait_for_run` and `sleep 2`, because rrdtool refuses two updates in the same second.
1. Assert that `resource/script_server/cloudflare_tunnels.xml` exists and the three graph templates are present by hash.
2. Create the device through HTTP with action 5, and set `create_graphs` through the edit form.
3. Make the job due (`next_run_at = 0`) and poll, so the sync reindexes and creates graphs. Assert three graphs for each active tunnel, and resource rows that match `graph_local`.
4. Poll twice. For blog's RRD, `rrdtool lastupdate` shows `connectors` 1 and `health` matching the fixture status.
5. Touch `fail-503`, make the job due, and poll. The job ends `failed|transient`. Poll again, and `lastupdate` shows `U` for `connectors`, `connections` and `health`. `rrdtool fetch AVERAGE -s -900` has no `0.0000000000e+00` row for `connectors`.
6. Remove `fail-503`.

Screenshots: add `tunnel-graph.png` from `graph_image.php?local_graph_id=<blog connectors graph>`.

Steps:
- [ ] Extend the smoke test and run it; it fails.
- [ ] Fix whatever the real Cacti exposes (the expected places are template fields and the run order).
- [ ] Run `make smoke` (GREEN) and `make screenshots`.
- [ ] Run `make smoke CACTI_REF=release/1.2.25` once, and record the result in `docs/compatibility.md`.
- [ ] Commit `test: exercise metrics end to end in the smoke stack`.

### Task 11: Docs

**Files:** modify `README.md` (metrics are now available, the screenshot, how to create the device, and the resource XML copy), `docs/design.md` (the object model, the reindex rule, the data query XML location, and that `ss_cloudflare.php` reads only plugin tables), `docs/security.md` (the second realm for device and graph actions, the `.invalid` hostname, and that nothing is written outside `resource/script_server`), `docs/compatibility.md` (every Cacti API and table in this plan with its file:line in 1.2.25 and 1.2.31, and the 1.2.31 resource-path rule), `CHANGELOG.md` and the known limitations.

**Interfaces:** The known limitations section must state these:
- Tunnels beyond the per-run connections cap are refreshed less often than the window, and show gaps.
- Admin edits to imported templates are overwritten when a plugin upgrade changes them.
- The device must stay on the main poller.
- Deleted tunnels stay in the index.

Steps:
- [ ] Update the docs to match the code.
- [ ] Run `make ci` and `make package`.
- [ ] Commit `docs: describe tunnel metrics and graphs`.
