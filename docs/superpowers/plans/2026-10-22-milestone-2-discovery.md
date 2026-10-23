# Milestone 2: Tunnel Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Discover Cloudflare Tunnels and their connectors for every enabled account in the Cacti poller. Reconcile them into plugin tables with explicit lifecycle states, and show them read-only in Cacti.

**Architecture:** A background collector, `poller_cloudflare.php`, is launched from the `poller_bottom` hook and leases due jobs from `plugin_cloudflare_jobs`. For each account it pages through `cfd_tunnel`, fetches `/connections` per tunnel, and reconciles the results in one transaction. A failed or partial listing never changes stored state. Pages read only from plugin tables, never from the API.

**Tech Stack:** As in Milestone 1. PHP 7.4 syntax, PDO through `Database`, PHPUnit 9.6, Behat, Eris, Docker (Cacti 1.2.31).

**Spec:** `docs/spec.md` (sections 3.3, 3.4 partially, 5, 6 and 7, invariants A, C, E and F), `docs/design.md`, and `docs/research/cloudflare-api-facts.md` (especially "Live verification").

## Global Constraints

- Everything in the Milestone 1 plan's Global Constraints still applies, including PHP 7.4 syntax, bound SQL, no token in any message, DCO sign-off, and Cacti style in glue.
- Lifecycle states are exactly `active`, `unavailable` and `deleted`. Cloudflare health is stored separately, as the raw `status` (`healthy`, `degraded`, `down`, `inactive`).
- A run only changes state after a complete, successful listing. Any error in the listing (transport, `rate_limited`, `malformed`, pagination gap) leaves every stored row unchanged except the job's own bookkeeping. This is invariant A.
- Identity is the Cloudflare UUID, with `UNIQUE(account_id, cf_tunnel_id)` and `UNIQUE(tunnel_id, cf_connector_id)`. This is invariant C.
- A per-tunnel 401 with code 1001, or a 404, on `/connections` means that tunnel is gone or unknown. It never marks the account `auth_failed`. This was observed live.
- Collection runs only on the main poller (`$poller_id == 1`). It never blocks `poller.php`, because it runs in the background through `exec_background`. It stops starting new accounts after a 50-second budget. This is invariant F.
- Only one collector process runs at a time, via `GET_LOCK('plugin_cloudflare_collector', 0)`, and only one lease per job row. This is invariant E.
- The collector checks `api_plugin_is_enabled('cloudflare')` before each account and exits cleanly if the plugin has been disabled.
- Owner scenario text in features is verbatim (spec section 5).

## Review Focus

- **Pages missing from the listing.** Cloudflare returns a page with fewer items than `per_page`, but the cumulative `count` is below `total_count`, for example because a tunnel was deleted mid-pagination. Treat this as a partial listing: no state change. Test: `testShortPageBeforeTotalIsPartial`.
- **Tunnel deleted between list and connections.** `/connections` returns 401 with code 1001. That tunnel's connectors are left as they were, the tunnel stays as listed, and the account status is unchanged. Test: `testConnections1001DoesNotFailAccount`.
- **Two pollers overlap.** The previous collector is still running when the next minute starts. The second process exits at once with no work. Test: `testSecondCollectorExitsWhenLockHeld`.
- **An account with 1,500 tunnels.** Two pages at `per_page=1000`. All 1,500 are reconciled and none duplicated. Test: `testLargeAccountPaginates`.
- **Plugin disabled mid-run.** Accounts after the disable are skipped, and finished accounts keep their committed results. Test: `testDisableMidRunStopsBeforeNextAccount`.

---

### Task 1: Schema M002 (tunnels and connectors)

**Files:** create `src/Storage/Migrations/M002Discovery.php`; modify `src/Storage/Migrator.php` (`defaultMigrations`) and `docs/schema.md`; test in `tests/Integration/Storage/M002DiscoveryTest.php`.

**Interfaces:**

`plugin_cloudflare_tunnels` columns:
- `id INT UNSIGNED AI PK`
- `account_id INT UNSIGNED NOT NULL`, with an FK to accounts, `ON DELETE CASCADE`
- `cf_tunnel_id CHAR(36) NOT NULL`
- `name VARCHAR(255) NOT NULL`
- `cf_status VARCHAR(16) NOT NULL`
- `tun_type VARCHAR(32) NOT NULL`
- `lifecycle ENUM('active','unavailable','deleted') NOT NULL DEFAULT 'active'`
- `created_at_cf INT UNSIGNED NULL`
- `conns_active_at INT UNSIGNED NULL`
- `conns_inactive_at INT UNSIGNED NULL`
- `connector_count INT UNSIGNED NULL`
- `connection_count INT UNSIGNED NULL`
- `first_seen_at`, `last_seen_at` and `lifecycle_changed_at`, all `INT UNSIGNED NOT NULL`
- `UNIQUE (account_id, cf_tunnel_id)`, `KEY (lifecycle)`

`plugin_cloudflare_connectors` columns:
- `id INT UNSIGNED AI PK`
- `tunnel_id INT UNSIGNED NOT NULL`, with an FK to tunnels, `ON DELETE CASCADE`
- `cf_connector_id CHAR(36) NOT NULL`
- `version VARCHAR(32) NOT NULL`
- `arch VARCHAR(32) NOT NULL`
- `run_at INT UNSIGNED NULL`
- `connection_count INT UNSIGNED NOT NULL`
- `colos VARCHAR(255) NOT NULL` (comma-joined `colo_name`, sorted)
- `origin_ips VARCHAR(255) NOT NULL`
- `last_seen_at INT UNSIGNED NOT NULL`
- `UNIQUE (tunnel_id, cf_connector_id)`

Steps:
- [ ] Write the tests: `testUpgradeFromV1AddsDiscoveryTables` (migrate M001 only, then all), `testM002Idempotent`, `testTunnelUniquePerAccount`, `testConnectorCascadeOnTunnelDelete` and `testDropAllRemovesDiscoveryTables`.
- [ ] Run them and confirm they fail (RED).
- [ ] Implement. The FK checks follow M001's `information_schema` pattern.
- [ ] Run the tests (GREEN) and update `docs/schema.md`.
- [ ] Commit `feat: add discovery schema`.

### Task 2: Tunnel and connection listing

**Files:** modify `src/Api/CloudflareClient.php`; create `src/Api/TunnelPage.php` and `src/Api/TunnelRecord.php`, `src/Api/ConnectorRecord.php`; test in `tests/Unit/Api/TunnelListingTest.php`; fixtures `tests/fixtures/cloudflare/tunnel-list-p1.json`, `tunnel-list-p2.json`, `tunnel-list-short.json`, `connections-1001.json` (401 body `{"code":1001,"message":"Not authorized"}`) and `tunnel-list-bad-item.json`.

**Interfaces:**
- `listTunnels(string $cfAccountId, string $token): TunnelRecord[]`
  - Requests `GET /accounts/{id}/cfd_tunnel` with `is_deleted=false&per_page=1000&page=N`.
  - Stops when the cumulative item count reaches `result_info.total_count`.
  - Throws `ApiException` class `malformed` when a page returns 0 items before the total is reached, when `result_info` is missing or non-integer, or when any item fails validation. There is no partial result.
  - Hard cap of 50 pages, then `malformed`.
- `listConnections(string $cfAccountId, string $token, string $tunnelId): ConnectorRecord[]` requests `GET /accounts/{id}/cfd_tunnel/{tunnel}/connections`. A 401 with code 1001, or a 404, maps to class `not_found`.
- `TunnelRecord` is immutable: `id` (lowercase UUID), `name` (at most 255 chars, control chars stripped), `status` (one of the four, else `malformed`), `tunType`, `createdAt` (`?int`), `connsActiveAt` (`?int`), `connsInactiveAt` (`?int`).
- `ConnectorRecord` is immutable: `id`, `version`, `arch`, `runAt` (`?int`), `connectionCount` (count of `conns`), `colos` (sorted unique), `originIps` (sorted unique, validated with `filter_var(FILTER_VALIDATE_IP)`; invalid ones dropped).

Steps:
- [ ] Write the tests:
  - `testListsSinglePage`
  - `testLargeAccountPaginates` (1,500 items)
  - `testShortPageBeforeTotalIsPartial`
  - `testMissingResultInfoIsMalformed`
  - `testBadItemFailsWholeListing`
  - `testPageCap`
  - `testConnections1001IsNotFound`
  - `testConnectionsParsesClients`
  - `testTunnelNameControlCharsStripped`
  - `testTokenNeverInMessages` (every failure path)
- [ ] Run them and confirm they fail (RED).
- [ ] Implement.
- [ ] Run them (GREEN), plus `make stan cs lint74`.
- [ ] Commit `feat: list tunnels and connections`.

### Task 3: Credential resolver extraction

**Files:** create `src/Account/CredentialResolver.php`; modify `src/Account/AccountService.php` to use it; test in `tests/Integration/Account/CredentialResolverTest.php`.

**Interfaces:** `CredentialResolver(AccountRepository $repo, ?TokenVault $vault)` provides `resolve(int $accountId): string`. It reads token material, decrypts or reads the file, and validates with `TokenPolicy`. It throws `SecretException` and never logs. It performs no authorization; its callers are `AccountService::verify` (already authorized) and the collector (system context, no page reaches it). The class docblock states this.

Steps:
- [ ] Write the tests: `testResolvesVaultToken`, `testResolvesFileToken`, `testEmptyDecryptRejected` and `testAccountServiceStillPasses` (the existing AccountServiceTest must stay green).
- [ ] Implement and refactor `AccountService`.
- [ ] Run the tests (GREEN).
- [ ] Commit `refactor: share token resolution with the collector`.

### Task 4: Reconciler

**Files:** create `src/Discovery/Reconciler.php` and `src/Discovery/TunnelRepository.php`; tests in `tests/Integration/Discovery/ReconcilerTest.php`.

**Interfaces:**
- `TunnelRepository(Database $db)`:
  - `forAccount(int $accountId, array $filters = [], int $limit = 50, int $offset = 0): array`
  - `count(int $accountId = 0, array $filters = []): int`
  - `connectors(int $tunnelId): array`
  - `markAccountUnavailable(int $accountId, int $now): int`
- `Reconciler(Database $db, Clock $clock)`:
  - `applyListing(int $accountId, TunnelRecord[] $tunnels): array`. In one transaction it upserts each tunnel to `active`, sets `last_seen_at = now`, and keeps `first_seen_at`. Rows absent from the listing that are not already `deleted` become `deleted`, with `lifecycle_changed_at` set. Returns `['added'=>n,'updated'=>n,'deleted'=>n]`.
  - `applyConnections(int $tunnelRowId, ConnectorRecord[] $connectors): void`. In one transaction it upserts the present connectors, deletes absent connector rows (connectors are ephemeral processes), and sets `tunnels.connector_count` and `connection_count`.
  - `markUnavailable(int $accountId): int`. Every `active` tunnel of that account becomes `unavailable`; deleted rows are untouched. It is called after repeated failures (see Task 5) and never deletes anything.

Steps:
- [ ] Write the tests:
  - `testFirstListingAddsAll`
  - `testRepeatListingIsIdempotent` (an Eris property: applying the same listing N times leaves an identical table, including ids)
  - `testAbsentTunnelBecomesDeletedButRowKept`
  - `testReappearingTunnelReactivates`
  - `testMarkUnavailableNeverDeletes`
  - `testConnectorsReplacedPerTunnel`
  - `testCountsUpdated`
  - `testTransactionRollsBackOnFailure` (inject a failing statement)
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: reconcile discovered tunnels with lifecycle states`.

### Task 5: Jobs and scheduling

**Files:** create `src/Jobs/JobRepository.php` and `src/Jobs/Lease.php`; tests in `tests/Integration/Jobs/JobRepositoryTest.php`.

**Interfaces:**
- `JobRepository(Database $db, Clock $clock)`:
  - `ensure(string $type, int $accountId): void` (`INSERT IGNORE`)
  - `due(string $type, int $limit): array` (job rows that are enabled for an enabled account, with `next_run_at <= now` and no live lease)
  - `lease(int $jobId, string $owner, int $ttl): ?Lease` (an atomic `UPDATE ... WHERE id=? AND (lease_until IS NULL OR lease_until < ?)` with rowCount 1)
  - `succeed(Lease $l, int $nextRunAt): void`
  - `fail(Lease $l, string $errorClass, int $nextRunAt): int` (returns `consecutive_failures`)
  - `release(Lease $l): void`
- Scheduling rules, implemented in Task 6 and exposed here as a pure function:
  - `static nextRun(int $now, int $interval, ?string $errorClass, ?int $retryAfter, int $failures): int`
  - Success gives `now + interval`.
  - `rate_limited` gives `now + max(retryAfter ?? 60, 60)`.
  - `transient` gives `now + min(interval * 2^failures, 3600)`.
  - `auth` and `permission` give `now + 3600`.
  - `config` and `malformed` give `now + interval`.
- Owner string: `hostname:pid`.

Steps:
- [ ] Write the tests:
  - `testEnsureIdempotent`
  - `testLeaseIsExclusive` (two connections)
  - `testExpiredLeaseCanBeTaken`
  - `testFailIncrementsAndSucceedResets`
  - `testNextRunTable` (a data provider over every rule)
  - `testDueSkipsDisabledAccounts`
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: lease and schedule collection jobs`.

### Task 6: Discovery service and collector

**Files:** create `src/Discovery/DiscoveryService.php`; tests in `tests/Integration/Discovery/DiscoveryServiceTest.php`.

**Interfaces:**
- `DiscoveryService(AccountRepository $accounts, CredentialResolver $creds, CloudflareClient $client, Reconciler $rec, JobRepository $jobs, Clock $clock, callable $stillEnabled, ?callable $log = null)`
- `runDue(int $budgetSeconds = 50): array` returns per-account outcomes. For each due `discovery` job:
  1. Check `$stillEnabled()`.
  2. Take the lease.
  3. Resolve the token.
  4. Call `listTunnels`.
  5. Call `applyListing`.
  6. For each active tunnel, call `listConnections` then `applyConnections`. A `not_found` skips that tunnel. Any other class for that tunnel is logged and leaves its connectors unchanged.
  7. On success, call `succeed`.
  8. On failure, call `fail` with `nextRun`. After 3 consecutive failures, call `markUnavailable`.
- No new account is started once `budgetSeconds` has elapsed.
- Log lines are fixed text plus the account's numeric id, the error class, and counts. They never carry the token or the name.

Steps:
- [ ] Write the tests:
  - `testDiscoversThreeTunnels`
  - `testOutageLeavesTunnelsIntact` (a transient failure means no state change and the failure counter goes up)
  - `testThreeFailuresMarkUnavailable`
  - `testRateLimitDefersByRetryAfter`
  - `testConnections1001DoesNotFailAccount`
  - `testDisableMidRunStopsBeforeNextAccount`
  - `testBudgetStopsNewAccounts` (FrozenClock advanced inside the fake)
  - `testLogsNeverContainToken`
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: run tunnel discovery for due accounts`.

### Task 7: Poller glue

**Files:** create `poller_cloudflare.php` and `includes/polling.php`; modify `setup.php` (register the `poller_bottom` hook in install and in upgrade, through a function whose name matches the entry-point rule) and `includes/bootstrap.php` (`cloudflare_discovery_service()`); tests in `tests/Unit/Glue/PollingContractTest.php`.

**Interfaces:**
- `cloudflare_poller_bottom()` in `includes/polling.php`. It returns at once unless the plugin is enabled, the poller id is 1, and at least one account is enabled. It calls `exec_background(read_config_option('path_php_binary'), '-q ' . escapeshellarg(<plugin>/poller_cloudflare.php))`. Check the `exec_background` signature in 1.2.31 at `lib/poller.php:132`.
- `poller_cloudflare.php`:
  - Refuses non-CLI callers.
  - Includes `include/cli_check.php` (1.2.31) or `include/global.php` the way core CLI scripts do.
  - Takes `GET_LOCK('plugin_cloudflare_collector', 0)` on Cacti's connection, and exits 0 with no output when the lock is held.
  - Ensures a discovery job exists for each enabled account.
  - Runs `runDue(50)`.
  - Logs one summary line through `cacti_log` at `POLLER_VERBOSITY_MEDIUM`.
  - Always releases the lock.
- Upgrade path: a 1.0.0 install upgraded to this version gets the hook registered, and runs migration M002.

Steps:
- [ ] Write the tests:
  - `testInstallRegistersPollerBottom`
  - `testUpgradeRegistersPollerBottom`
  - `testPollerBottomSkipsWhenDisabledOrRemotePoller`
  - `testSecondCollectorExitsWhenLockHeld` (an integration test that runs the script in a subprocess with the lock held)
  - `testCollectorRefusesWebInvocation` (static)
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: launch the collector from the poller`.

### Task 8: Viewer pages

**Files:** create `cloudflare.php` and `includes/viewer.php`; modify `includes/settings.php` (menu and nav for the view pages; the console menu entry is visible to the View realm); tests in `tests/Unit/Glue/ViewerPageTest.php`.

**Interfaces:**
- `cloudflare.php` has the actions `overview` (the default), `tunnels` and `connectors` (with a `tunnel_id` filter). It uses Cacti tab navigation in the style of thold's `thold_graph.php` tabs.
- Overview: a per-account table showing name, status, active, unavailable and deleted tunnel counts, the last successful discovery time, and consecutive failures. An account with failures shows "Collection failing" instead of tunnel health.
- Tunnels: a filter (account, lifecycle, Cloudflare status, search), sortable allow-listed columns, and pagination via `html_nav_bar`.
- Connectors: read-only rows for one tunnel.
- All handlers are functions in `includes/viewer.php`, so tests reach them (the lesson from the M1 coverage gate). The page is a thin dispatcher with the auth include, the enabled guard and a switch. Every value goes through `html_escape`. The page reads only through `TunnelRepository` and `AccountRepository::all()`, and has no mutations, so there is no POST.

Steps:
- [ ] Write the tests:
  - `testOverviewShowsCollectionFailingNotHealthy`
  - `testTunnelsFilterAndSortAllowList`
  - `testEscapesTunnelNames` (a name with `<script>`)
  - `testDisabledPluginDenied`
  - `testNoMutationPaths`
- [ ] Implement.
- [ ] Run the tests (GREEN).
- [ ] Commit `feat: add tunnel and connector views`.

### Task 9: BDD scenarios

**Files:** create `features/tunnel_discovery.feature` and `features/api_rate_limiting.feature`; create `features/bootstrap/DiscoveryContext.php`; modify `features/bootstrap/World.php` and `behat.yml.dist`.

**Interfaces:**
- Owner text verbatim:
  - "Discover tunnels successfully"
  - "Repeated discovery does not create duplicates"
  - "Cloudflare returns HTTP 429"
- For the 429 scenario, "it must not block the Cacti user interface" is shown by the discovery running in the collector with a bounded sleep (at most 30 seconds, recorded by `RecordingSleeper`), plus no page code calling the API. Assert that the viewer helper functions make zero transport requests.
- The outage and metrics-integrity scenarios belong to Milestone 3, because they reference graphs.

Steps:
- [ ] Write the features. Run `behat --strict` and confirm it fails.
- [ ] Implement the contexts.
- [ ] Run it again (GREEN).
- [ ] Commit `test: add discovery and rate limit scenarios`.

### Task 10: End-to-end discovery in the smoke stack

**Files:** create `tests/docker/fake-cloudflare/` (a small PHP server that serves the fixtures over TLS with a test CA); modify `tests/docker/compose.cacti.yml` (resolve `api.cloudflare.com` to the fake via a network alias, and add the test CA to the web container's trust store), `tests/docker/smoke.sh` (new steps) and `tests/docker/screenshots.sh`.

**Interfaces:**
- The production code is unchanged. Only the container's DNS and CA bundle differ.
- New smoke steps:
  1. Add an account through HTTP.
  2. Run `php poller.php --force`.
  3. Assert that three tunnels are in `plugin_cloudflare_tunnels`, and that the Tunnels page as the viewer shows them.
  4. Make the fake return 503, run the poller, and assert the rows are unchanged.
  5. Disable the plugin and confirm the poller launches nothing.
- Screenshots: add `tunnels-list.png` and `overview.png`.

Steps:
- [ ] Extend the smoke test and run it against the current code; it fails.
- [ ] Implement the fake server and the compose changes.
- [ ] Run `make smoke` (GREEN) and `make screenshots`.
- [ ] Commit `test: exercise discovery end to end in the smoke stack`.

### Task 11: Docs

**Files:** modify `README.md` (move discovery to "available" and add the screenshots), `docs/security.md` (collector process model, lock, budget), `docs/compatibility.md` (the new hook), `CHANGELOG.md` and `docs/token-permissions.md` (the tunnel read permission is now used by discovery).

Steps:
- [ ] Update the docs to match the code.
- [ ] Run `make ci`.
- [ ] Commit `docs: describe tunnel discovery`.
