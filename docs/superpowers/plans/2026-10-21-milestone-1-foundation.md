# Milestone 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver an installable Cacti 1.2.x plugin, `cloudflare`, with migrations, realms, a hardened Cloudflare API client, secure multi-account configuration with a connection test, unit and integration tests, and executable BDD scenarios.

**Architecture:** Procedural Cacti glue (`setup.php`, `includes/`, page scripts) delegates to a Cacti-independent library in `src/` (`Cacti\PluginCloudflare\`). Storage is PDO through one `Database` wrapper, and network I/O goes through one `CurlTransport` behind an origin allowlist. Read `docs/design.md` before starting any task. It holds the binding decisions.

**Tech Stack:** PHP 7.4+ syntax, PDO MySQL/MariaDB, ext-curl, ext-openssl, PHPUnit 9.6, Behat 3, Eris, PHPStan, php-cs-fixer, Docker (MariaDB 10.11, Cacti 1.2.31).

**Spec:** `docs/spec.md`, with decisions in `docs/design.md` and Cloudflare facts in `docs/research/cloudflare-api-facts.md`.

## Global Constraints

- Repo root: `/Users/thomasvincent/Developer/github.com/somethingwithproof/plugin_cloudflare`. The plugin directory name inside Cacti is `cloudflare`.
- PHP 7.4 syntax only (see `docs/design.md` "Runtime floor"). `composer.json` sets `config.platform.php = 7.4.33`.
- Cacti style: tab indentation, single quotes, same-line braces, `print` over `echo` in glue, lowercase `true/false/null`, no spaces inside parentheses. Cacti return-type spacing is `function f() : void` in glue only; `src/` follows php-cs-fixer config.
- `src/` must not reference Cacti functions or globals. Only `setup.php`, `includes/*.php` and the page scripts may.
- Every SQL statement is prepared with bound values. Identifiers are never interpolated from input.
- Tokens never appear in logs, exception messages, HTML or test output. Every test that handles a token asserts this.
- Each new PHP file starts with the plugin's GPL-2.0-or-later header, copied from the first 19 lines of `setup.php`: the copyright line `Copyright (C) 2026 Thomas Vincent`, the GPL notice, and the `Cloudflare plugin for Cacti` block with the repository URL. `testEveryPhpFileStartsWithLicenseHeader` enforces it.
- Commits: Conventional Commits, `git commit -s`, no Co-Authored-By line, one bullet max in the body. Commit locally only; do not push.
- Test DB: `docker compose -f tests/docker/compose.db.yml up -d`. Tests read `CF_TEST_DSN`, `CF_TEST_DB_USER` and `CF_TEST_DB_PASS` and are skipped with a clear message when the DB is unreachable. CI never skips.

## Review Focus

- Admin saves an account and leaves the token blank to keep the existing one. The stored token must be unchanged (Task 7 `testSaveWithBlankTokenKeepsExistingToken`).
- The key file is replaced or deleted after tokens were stored. Decryption fails with class `config`, the account status becomes `error` with "token cannot be decrypted", and nothing crashes (Task 4 `testDecryptWithWrongKeyFails`, Task 7 `testVerifyWithUndecryptableTokenMarksError`).
- Cloudflare returns HTTP 200 with `success: false`. This is treated as an error by code, never as success (Task 6 `testSuccessFalseWith200IsError`).
- The plugin is upgraded while a page load and the poller both trigger the upgrade check. Migrations run exactly once (Task 3 `testConcurrentMigrationRunsOnce`).
- The account ID is pasted with uppercase letters or surrounding spaces. It is normalized by trim and lowercase, then validated (Task 7 `testAccountIdIsNormalized`).

---

### Task 1: Tooling and test harness

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `.gitignore`, `.gitattributes`, `Makefile`, `tests/bootstrap.php`, `tests/docker/compose.db.yml`, `tests/Support/TestDatabase.php`, `tests/Unit/HarnessTest.php`

**Interfaces:**
- Produces: `Cacti\PluginCloudflare\Tests\Support\TestDatabase::pdo(): \PDO`, a fresh connection with Cacti's flags (`ATTR_ERRMODE = ERRMODE_SILENT` after connect, `MYSQL_ATTR_FOUND_ROWS = true`, charset utf8, session `sql_mode` with STRICT_TRANS_TABLES, STRICT_ALL_TABLES, TRADITIONAL, NO_ZERO_DATE, NO_ZERO_IN_DATE, ONLY_FULL_GROUP_BY and NO_AUTO_VALUE_ON_ZERO removed). It also produces `TestDatabase::reset(): void`, which drops every `plugin_cloudflare_%` table, and `TestDatabase::skipIfUnavailable(TestCase $t): void`.
- Make targets: `make test` (unit), `make test-integration`, `make bdd`, `make stan`, `make cs`, `make lint74` (runs `php -l` on every non-vendor PHP file inside `php:7.4-cli`), `make db-up`, `make db-down`, `make ci` (all of these).

- [ ] **Step 1:** Write `tests/Unit/HarnessTest.php::testAutoloadsPluginNamespace`, asserting `class_exists('Cacti\\PluginCloudflare\\Version')`. Add `src/Version.php` only in Step 3.
- [ ] **Step 2:** Run `vendor/bin/phpunit --testsuite unit`. It must fail with the class not found.
- [ ] **Step 3:** Create `src/Version.php` with `final class Version { const PLUGIN = '1.0.0'; const METRIC_SCHEMA = 1; }`. Create the composer dev deps: `phpunit/phpunit ^9.6`, `behat/behat` (latest version installable with platform 7.4), `giorgiosironi/eris` (latest version supporting phpunit 9), `phpstan/phpstan ^1.12`, `friendsofphp/php-cs-fixer` (latest supporting 7.4 platform), plus PSR-4 `Cacti\PluginCloudflare\` to `src/` and `Cacti\PluginCloudflare\Tests\` to `tests/`. PHPUnit suites are `unit` (`tests/Unit`) and `integration` (`tests/Integration`). The compose file runs `mariadb:10.11` on host port 33061 with a tmpfs datadir and a healthcheck. Set PHPStan to level 6, `phpVersion: 70400`, paths `src tests setup.php includes`. php-cs-fixer uses tabs, `single_quote`, `braces_position` same line for control structures and functions in glue, and is scoped to `src tests`.
- [ ] **Step 4:** Run `make test && make lint74 && make stan && make cs`. All pass.
- [ ] **Step 5:** Commit: `chore: add test harness and tooling`.

### Task 2: Identifier validation and PDO database wrapper

**Files:**
- Create: `src/Support/Ids.php`, `src/Storage/Database.php`, `src/Storage/DatabaseException.php`
- Test: `tests/Unit/Support/IdsTest.php`, `tests/Integration/Storage/DatabaseTest.php`

**Interfaces:**
- Produces `final class Ids` with static methods `isAccountId(string $s): bool` (`^[0-9a-f]{32}$`), `isUuid(string $s): bool` (lowercase canonical 8-4-4-4-12 hex), and `normalizeAccountId(string $s): string` (trim plus lowercase, no validation).
- Produces `final class Database`:
  - `__construct(\PDO $pdo)`
  - `execute(string $sql, array $params = []): int` returns affected or matched rows.
  - `fetchAll(string $sql, array $params = []): array`
  - `fetchRow(string $sql, array $params = []): ?array`
  - `fetchCell(string $sql, array $params = [])` returns a scalar or `null`.
  - `lastInsertId(): int`
  - `transactional(callable $fn)` runs inside `beginTransaction`/`commit`, rolls back on any `\Throwable` and rethrows. A nested call joins the outer transaction.
  - `acquireLock(string $name, int $timeoutSeconds): bool` and `releaseLock(string $name): void` use `GET_LOCK`/`RELEASE_LOCK`.
- Produces `DatabaseException extends \RuntimeException` with `getSqlState(): string`. Its message contains the SQLSTATE and the driver message, never the parameter values.

- [ ] **Step 1:** Write the tests.
  - `IdsTest`: `testAccountIdAcceptsLowercaseHex32`, `testAccountIdRejects` (data provider covering uppercase, 31 and 33 characters, non-hex, a trailing newline, and an empty string), `testUuidAcceptsFixtureIds` (ids from `tests/fixtures/cloudflare/tunnel-list.json`), `testUuidRejectsUppercaseAndBraces`, and the Eris property `testRandomHex32AlwaysValid`.
  - `DatabaseTest`: `testRoundTripWithBoundParams`, `testFailedStatementThrowsWithSqlStateWithoutParams` (insert into a missing table with param `'s3cr3t-value'`; the message must not contain `s3cr3t-value`), `testTransactionalRollsBackOnException`, `testNestedTransactionalJoinsOuter`, `testLockIsExclusiveAcrossConnections` (two `TestDatabase::pdo()` handles; the second `acquireLock(...,0)` returns false), and `testWorksWithErrmodeSilentHandle`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter 'IdsTest|DatabaseTest'`. They must fail with the classes missing.
- [ ] **Step 3:** Implement. `Database` never calls `setAttribute` on the handle. It checks `prepare`/`execute` return values and `errorInfo()`.
- [ ] **Step 4:** Run the tests. They pass, and `make stan cs lint74` passes.
- [ ] **Step 5:** Commit: `feat: add PDO database wrapper and id validation`.

### Task 3: Migrations (schema v1)

**Files:**
- Create: `src/Storage/Migrator.php`, `src/Storage/Migration.php` (interface), `src/Storage/Migrations/M001Foundation.php`, `docs/schema.md`
- Test: `tests/Integration/Storage/MigratorTest.php`

**Interfaces:**
- Consumes: `Database` (Task 2).
- Produces:
  - `interface Migration { public function version(): int; public function statements(Database $db): array; }`. `statements` returns a list of SQL strings, each idempotent on its own.
  - `final class Migrator`:
    - `__construct(Database $db, array $migrations)`
    - `pending(): array` lists versions.
    - `migrate(): array` lists versions applied this run, and throws `DatabaseException` if the lock is not acquired within 10 seconds.
    - `currentVersion(): int`
    - `static defaultMigrations(): array`
  - `Migrator::dropAll(): void` drops all plugin tables in FK-safe order, for uninstall.
- Schema M001. InnoDB, utf8mb4, all times `INT UNSIGNED`:
  - `plugin_cloudflare_migrations`: `version INT UNSIGNED PK`, `applied_at`.
  - `plugin_cloudflare_accounts` has these columns:
    - `id INT UNSIGNED AI PK`
    - `cf_account_id CHAR(32) NOT NULL UNIQUE`
    - `name VARCHAR(100) NOT NULL`
    - `token_source ENUM('vault','file') NOT NULL`
    - `token_ciphertext TEXT NULL`
    - `token_file VARCHAR(255) NULL`
    - `enabled TINYINT(1) NOT NULL DEFAULT 1`
    - `status VARCHAR(20) NOT NULL DEFAULT 'unknown'`
    - `status_message VARCHAR(255) NOT NULL DEFAULT ''`
    - `permissions TEXT NULL` (JSON)
    - `last_checked_at INT UNSIGNED NULL`
    - `last_verified_at INT UNSIGNED NULL`
    - `collect_tunnels TINYINT(1) NOT NULL DEFAULT 1`
    - `collect_analytics TINYINT(1) NOT NULL DEFAULT 0`
    - `discovery_interval INT UNSIGNED NOT NULL DEFAULT 300`
    - `created_at`, `updated_at`
  - `plugin_cloudflare_jobs` has these columns:
    - `id INT UNSIGNED AI PK`
    - `job_type VARCHAR(32) NOT NULL`
    - `account_id INT UNSIGNED NOT NULL`, FK to accounts `ON DELETE CASCADE`
    - `lease_owner VARCHAR(64) NULL`
    - `lease_until INT UNSIGNED NULL`
    - `next_run_at INT UNSIGNED NOT NULL DEFAULT 0`
    - `last_started_at`, `last_finished_at`
    - `last_status VARCHAR(20) NOT NULL DEFAULT 'never'`
    - `last_error_class VARCHAR(20) NULL`
    - `consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0`
    - `UNIQUE (job_type, account_id)`

- [ ] **Step 1:** Write the tests:
  - `testFreshInstallCreatesAllTables`
  - `testMigrateTwiceIsNoop`: the second call returns `[]`.
  - `testInterruptedMigrationResumes`: run only the first two statements of M001 by hand, then `migrate()`. It succeeds and the version is 1.
  - `testConcurrentMigrationRunsOnce`: hold the lock from a second connection, then `migrate()` throws; release it and `migrate()` applies.
  - `testAccountIdUniqueConstraint`
  - `testJobsUniquePerTypeAndAccount`
  - `testDeletingAccountCascadesJobs`
  - `testDropAllRemovesOnlyPluginTables`: create an unrelated table `host_unrelated` first and assert that it survives.
- [ ] **Step 2:** Run the tests. They must fail.
- [ ] **Step 3:** Implement. Check for indexes and FKs through `information_schema` before adding them.
- [ ] **Step 4:** Run the tests. They pass. Write `docs/schema.md`: a table per entity, columns, keys, and the retention note from `docs/design.md`.
- [ ] **Step 5:** Commit: `feat: add idempotent schema migrations`.

### Task 4: Credential handling

**Files:**
- Create: `src/Secret/TokenPolicy.php`, `src/Secret/TokenVault.php`, `src/Secret/TokenFile.php`, `src/Secret/SecretException.php`, `src/Support/Redactor.php`
- Test: `tests/Unit/Secret/TokenPolicyTest.php`, `tests/Unit/Secret/TokenVaultTest.php`, `tests/Unit/Secret/TokenFileTest.php`, `tests/Unit/Support/RedactorTest.php`

**Interfaces:**
- Produces:
  - `TokenPolicy::validate(string $token): void`. It throws `SecretException` with code `SecretException::GLOBAL_KEY` for a 37-character lowercase hex value, and `SecretException::INVALID` when the token does not match `^[A-Za-z0-9_-]{40,256}$`. Exception messages never include the input.
  - `TokenVault`:
    - `static fromKeyFile(string $path): TokenVault` throws `SecretException::KEY_UNAVAILABLE` if the file is missing, unreadable, world-readable, or not 32 raw bytes or 64 hex characters.
    - `encrypt(string $token, string $aad): string` returns the `v1:` format.
    - `decrypt(string $blob, string $aad): string` throws `SecretException::DECRYPT_FAILED`.
    - `static aadFor(string $cfAccountId): string`.
  - `TokenFile::read(string $path): string` checks for an absolute path, a regular file, size at most 4096, not world-readable, then trims and runs `TokenPolicy::validate`. It throws `SecretException::FILE_UNAVAILABLE` or `SecretException::FILE_INSECURE`.
  - `Redactor::redact(string $text, array $secrets): string` replaces each non-empty secret with `[REDACTED]`, and also scrubs any `Authorization: Bearer \S+`.
- The `SecretException` class constants listed above, plus `getReason(): string` (`'global_key'`, `'invalid'`, `'key_unavailable'`, `'decrypt_failed'`, `'file_unavailable'`, `'file_insecure'`).

- [ ] **Step 1:** Write the tests:
  - `testRejectsGlobalApiKey`
  - `testRejectsShortAndIllegalChars`
  - `testExceptionMessageNeverContainsInput`
  - `testRoundTrip` (Eris: random tokens and account ids)
  - `testCiphertextDiffersEachTime`
  - `testDecryptWithWrongAadFails`
  - `testDecryptWithWrongKeyFails`
  - `testTamperedTagFails`
  - `testKeyFileWorldReadableRejected`
  - `testHexKeyAccepted`
  - `testRelativeTokenPathRejected`
  - `testTokenFileWorldReadableRejected`
  - `testTokenFileTooLargeRejected`
  - `testRedactsBearerHeaderAndSecrets`

  Tests create files in `sys_get_temp_dir()` with explicit `chmod`.
- [ ] **Step 2:** Run the tests. They must fail.
- [ ] **Step 3:** Implement with `openssl_encrypt('aes-256-gcm', ..., OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16)` and `random_bytes(12)`. Compare tags only through `openssl_decrypt`'s own failure.
- [ ] **Step 4:** Run the tests. They pass, and `make stan cs lint74` passes.
- [ ] **Step 5:** Commit: `feat: add token vault and token file sources`.

### Task 5: Outbound HTTP transport

**Files:**
- Create: `src/Http/HttpTransport.php` (interface), `src/Http/Request.php`, `src/Http/Response.php`, `src/Http/OutboundPolicy.php`, `src/Http/CurlTransport.php`, `src/Http/TransportException.php`, `tests/Support/FakeTransport.php`, `tests/Support/stub-server.php`
- Test: `tests/Unit/Http/OutboundPolicyTest.php`, `tests/Integration/Http/CurlTransportTest.php`

**Interfaces:**
- Produces:
  - `Request`: `__construct(string $method, string $url, array $headers = [], ?string $body = null)`, with getters. `__debugInfo()` hides the header values.
  - `Response`: `__construct(int $status, array $headers, string $body)`, with `status()`, `header(string $name): ?string` (case-insensitive), and `body()`.
  - `OutboundPolicy`:
    - `__construct(array $origins)`
    - `static production(): self`, which allows only `https://api.cloudflare.com`.
    - `assertAllowed(string $url): void`, which throws `TransportException` with class `config`. It compares scheme, host and port exactly and rejects userinfo.
  - `HttpTransport::send(Request $r, int $connectTimeout, int $totalTimeout): Response`. It throws `TransportException` for network-level failures only. HTTP error statuses are returned as a `Response`.
  - `TransportException`: `getErrorClass(): string`, which is `transient` or `config`, and `getDetail(): string`.
  - `CurlTransport`: `__construct(OutboundPolicy $p, int $maxBytes = 5242880)`.
  - `FakeTransport`:
    - `queue(Response|TransportException $next): void`, called once per response or exception
    - `requests(): Request[]`
    - `static fixture(string $name, int $status = 200, array $headers = []): Response` loads from `tests/fixtures/cloudflare`

- [ ] **Step 1:** Write the tests. All hit `php -S 127.0.0.1:<free port> tests/Support/stub-server.php`, started in `setUpBeforeClass`. The stub has routes `/ok`, `/redirect`, `/slow?s=3`, `/big` (6 MiB) and `/status?code=503`.
  - `OutboundPolicyTest`:
    - `testProductionAllowsOnlyCloudflareApi`
    - `testRejects`, with a provider covering `http://api.cloudflare.com`, `https://api.cloudflare.com.evil.test`, `https://user@api.cloudflare.com`, `https://api.cloudflare.com:8443`, `file:///etc/passwd` and `gopher://x`
  - `CurlTransportTest`:
    - `testGetOk`
    - `testDoesNotFollowRedirect`: returns 302, and the stub's target route was never hit
    - `testTotalTimeoutIsTransient`: `/slow?s=3` with a 1 second total timeout throws with class `transient` in under 2.5 seconds
    - `testConnectionRefusedIsTransient`
    - `testOversizedBodyIsRejected`
    - `testNonAllowlistedUrlNeverConnects`
    - `testHttpErrorStatusReturnedNotThrown`
- [ ] **Step 2:** Run the tests. They must fail.
- [ ] **Step 3:** Implement `CurlTransport` with these options:
  - `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` set to the scheme of the allowed origin
  - `FOLLOWLOCATION` false
  - `SSL_VERIFYPEER` true and `SSL_VERIFYHOST` 2
  - `CONNECTTIMEOUT` and `TIMEOUT`
  - a `WRITEFUNCTION` that aborts past `maxBytes`

  `TransportException::getDetail()` uses `curl_error` text. A test asserts that the text never contains the `Authorization` value.
- [ ] **Step 4:** Run the tests. They pass, and `make stan cs lint74` passes.
- [ ] **Step 5:** Commit: `feat: add allowlisted curl transport`.

### Task 6: Cloudflare API client

**Files:**
- Create: `src/Api/CloudflareClient.php`, `src/Api/ApiException.php`, `src/Api/ErrorClass.php` (string constants), `src/Api/RetryPolicy.php`, `src/Api/TokenStatus.php`, `src/Support/Clock.php` (interface `now(): int`), `src/Support/SystemClock.php`, `src/Support/Sleeper.php` (interface `sleep(int $seconds): void`), `src/Support/SystemSleeper.php`, `tests/Support/FrozenClock.php`, `tests/Support/RecordingSleeper.php`, `tests/fixtures/cloudflare/token-verify-active.json`, `tests/fixtures/cloudflare/token-verify-expired.json`, `tests/fixtures/cloudflare/auth-error.json` (code 10000), `tests/fixtures/cloudflare/tunnel-list-page1.json`
- Test: `tests/Unit/Api/CloudflareClientTest.php`, `tests/Unit/Api/RetryPolicyTest.php`

**Interfaces:**
- Consumes: `HttpTransport`, `Request`, `Response`, `TransportException` (Task 5), `Ids` (Task 2), `Redactor` (Task 4).
- Produces:
  - `ErrorClass::AUTH`, `PERMISSION`, `NOT_FOUND`, `RATE_LIMITED`, `TRANSIENT`, `MALFORMED`, `CONFIG`, with the values `'auth'`, `'permission'`, `'not_found'`, `'rate_limited'`, `'transient'`, `'malformed'` and `'config'`.
  - `ApiException extends \RuntimeException`: `getErrorClass(): string`, `getHttpStatus(): ?int`, `getApiCodes(): int[]`, `getRetryAfter(): ?int`. Its message is built from the error class, the HTTP status and the API codes and messages, passed through `Redactor`.
  - `RetryPolicy`: `__construct(Clock $c, Sleeper $s, int $maxRetries = 2, int $maxRetryAfter = 30)` and `run(callable $attempt)`.
    - It retries only `transient` and `rate_limited`.
    - The backoff is 1 second, then 2 seconds.
    - For 429, it sleeps for `retry-after` when that is at most `maxRetryAfter`. Otherwise it rethrows at once.
    - It never sleeps more than `maxRetries` times.
  - `CloudflareClient`: `__construct(HttpTransport $t, RetryPolicy $r, string $baseUrl = 'https://api.cloudflare.com/client/v4', int $connectTimeout = 5, int $totalTimeout = 20)`.
    - `verifyToken(string $cfAccountId, string $token): TokenStatus` tries `GET /accounts/{id}/tokens/verify` first. On `auth` or `permission` it falls back to `GET /user/tokens/verify`.
    - `probe(string $cfAccountId, string $token, string $path): bool` returns true on success, false on `permission` or `auth`, and rethrows everything else.
    - `request(string $method, string $path, string $token, array $query = []): array` returns the decoded envelope.
  - `TokenStatus`: `__construct(string $id, string $status, ?int $expiresOn)`, `isActive(): bool`.
- Envelope rules:
  - A non-JSON body, a missing `success`, or a non-array `errors` is `malformed`.
  - `success: false` is an error even with HTTP 200, classified by the HTTP status first and then by the code (10000 means `auth`).
  - 401 is `auth`, 403 `permission`, 404 `not_found`, 429 `rate_limited`, any 5xx `transient`, and any other 4xx `malformed`.

- [ ] **Step 1:** Write the tests:
  - `testVerifyActiveToken` (fixture)
  - `testVerifyFallsBackToUserEndpoint` (first 401, then 200; assert both request paths)
  - `testExpiredTokenStatus` (`isActive` false)
  - `testSuccessFalseWith200IsError`
  - `testAuthCode10000IsAuth`
  - `testHttp403IsPermission`
  - `testHttp429HonoursRetryAfter` (`retry-after: 3`, then success; the sleeper recorded `[3]`)
  - `testHttp429RetryAfterTooLongIsNotSlept` (`retry-after: 120`; throws `rate_limited`, sleeper `[]`)
  - `testRetryBudgetExhausted` (three 503s; throws `transient`, sleeper `[1,2]`)
  - `testMalformedJson`
  - `testMissingSuccessField`
  - `testInvalidAccountIdNeverSends` (class `config`, zero requests)
  - `testTokenNeverInExceptionMessage` (each failure path; assert `strpos($e->getMessage(), $token) === false`)
  - `testAuthorizationHeaderIsBearer`
  - `testTimeoutsArePassedToTransport`
- [ ] **Step 2:** Run the tests. They must fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. They pass, and `make stan cs lint74` passes.
- [ ] **Step 5:** Commit: `feat: add Cloudflare REST client with bounded retries`.

### Task 7: Account repository and service

**Files:**
- Create: `src/Account/Account.php`, `src/Account/AccountRepository.php`, `src/Account/AccountService.php`, `src/Account/ValidationException.php`, `src/Auth/Authorizer.php` (interface), `src/Auth/AccessDenied.php`, `src/Auth/CallbackAuthorizer.php`
- Test: `tests/Integration/Account/AccountServiceTest.php`

**Interfaces:**
- Consumes: `Database`, `Migrator` (Tasks 2-3), `TokenVault`, `TokenFile`, `TokenPolicy` (Task 4), `CloudflareClient`, `ApiException`, `ErrorClass` (Task 6), `Clock`.
- Produces:
  - `Authorizer::canConfigure(): bool`. `CallbackAuthorizer` takes a `callable(): bool`. In glue it is `function () { return api_user_realm_auth('cloudflare_accounts.php'); }`.
  - `Account`: a read model with public getters for every column except `token_ciphertext`. It has `hasToken(): bool` and no getter that returns the secret.
  - `AccountRepository(Database $db)`:
    - `find(int $id): ?Account`
    - `all(): Account[]`, ordered by name then id
    - `insert(array $row): int`
    - `update(int $id, array $row): void`
    - `delete(int $id): void`
    - `tokenMaterial(int $id): array`, which returns `['source','ciphertext','file','cf_account_id']` and is used only by `AccountService`
  - `AccountService(AccountRepository $repo, Authorizer $auth, CloudflareClient $client, ?TokenVault $vault, Clock $clock)`:
    - `save(?int $id, array $input): int`. Input keys are `cf_account_id`, `name`, `token_source`, `token` (may be blank on update), `token_file`, `enabled`, `collect_tunnels`, `collect_analytics` and `discovery_interval` (60 to 86400). It throws `AccessDenied` before reading input when `canConfigure()` is false. It throws `ValidationException` with `getErrors(): array<string,string>` keyed by field.
    - `delete(int $id): void`
    - `verify(int $id): Account`. It decrypts or reads the token and calls `verifyToken`. It then probes `/accounts/{id}/cfd_tunnel?per_page=1` (`tunnel_read`) and `/zones?account.id={id}&per_page=5` (`zone_read`). It sets `status` to one of `ok`, `auth_failed`, `permission_denied`, `unreachable`, `rate_limited`, `error` or `token_inactive`. It sets `status_message` (no token), `permissions` as JSON `{"tunnel_read":bool,"zone_read":bool}`, and `last_checked_at`, and sets `last_verified_at` only on `ok`.
    - `list(): Account[]`. Listing requires no configure right, because it carries no secrets.
  - All three mutating methods (`save`, `delete`, `verify`) check `canConfigure()` first.

- [ ] **Step 1:** Write the tests against real MariaDB, `FakeTransport` and a temp key file:
  - `testSaveValidAccountEncryptsToken` (the DB column starts `v1:` and does not contain the token)
  - `testAccountIdIsNormalized`
  - `testRejectsGlobalApiKey`
  - `testDuplicateAccountIdIsValidationError`
  - `testSaveWithBlankTokenKeepsExistingToken`
  - `testUnauthorizedSaveChangesNothing` (the row count and row values are unchanged; throws `AccessDenied`)
  - `testUnauthorizedDeleteAndVerifyDenied`
  - `testVerifySuccessSetsOkAndPermissions`
  - `testVerifyRevokedPermissionSetsPermissionDenied` (verify ok, tunnel probe 403; `tunnel_read` false and status `permission_denied`)
  - `testVerifyInvalidTokenSetsAuthFailed`
  - `testVerifyUnreachableKeepsLastVerifiedAt`
  - `testVerifyWithUndecryptableTokenMarksError`
  - `testVaultUnavailableRejectsVaultSource`
  - `testFileSourceStoresPathNotToken`
  - `testListNeverExposesSecrets` (`var_export` of the `list()` result has no `v1:` and no token)
- [ ] **Step 2:** Run the tests. They must fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. They pass, and `make stan cs lint74` passes.
- [ ] **Step 5:** Commit: `feat: add account configuration service`.

### Task 8: Cacti plugin glue and account UI

**Files:**
- Create: `INFO`, `setup.php`, `index.php` (redirect, as in Thold), `includes/bootstrap.php`, `includes/settings.php`, `includes/autoload.php`, `cloudflare_accounts.php`, `locales/.gitkeep`
- Test: `tests/Unit/Glue/SetupContractTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces:
  - `INFO`: `name = cloudflare`, `version = 1.0.0`, `longname = Cloudflare`, `compat = 1.2.25`, `capabilities = online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0`, `requires =` (empty).
  - `setup.php` functions:
    - `plugin_cloudflare_install()` registers hooks `config_arrays`, `config_settings` and `draw_navigation_text` with file `includes/settings.php`. It registers realms `cloudflare.php` ("Cloudflare: View", admin false) and `cloudflare_accounts.php` ("Cloudflare: Configure", admin true), then runs `plugin_cloudflare_setup_database()`.
    - `plugin_cloudflare_uninstall()` calls `Migrator::dropAll()`, then deletes `settings` rows `LIKE 'cloudflare\_%'`. That is all it does.
    - `plugin_cloudflare_version()` parses INFO.
    - `plugin_cloudflare_check_config()` requires `extension_loaded('curl')`, `extension_loaded('openssl')` and `extension_loaded('pdo_mysql')`, and runs the upgrade.
    - `plugin_cloudflare_upgrade()` compares versions, migrates, and updates `plugin_config.version`.
    - `plugin_cloudflare_setup_database()`
    - Function names must match `/(install|upgrade|setup)/i` where they register hooks (see `api_plugin_valid_entrypoint`).
  - `includes/bootstrap.php`:
    - `cloudflare_db(): Database`, wrapping `$database_sessions["$database_hostname:$database_port:$database_default"]`
    - `cloudflare_vault(): ?TokenVault`, from setting `cloudflare_key_file` (default `/etc/cacti/cloudflare.key`); returns null on `KEY_UNAVAILABLE`
    - `cloudflare_client(int $connect, int $total): CloudflareClient`, with the production policy
    - `cloudflare_account_service(): AccountService`
  - `includes/settings.php`:
    - `$menu[__('Configuration')]['plugins/cloudflare/cloudflare_accounts.php'] = __('Cloudflare Accounts', 'cloudflare')`
    - nav entries for `cloudflare_accounts.php:` and `cloudflare_accounts.php:edit`
    - a settings tab `cloudflare` with `cloudflare_key_file` (textbox), `cloudflare_ui_timeout` (default 10), `cloudflare_poller_timeout` (default 20) and `cloudflare_log_level` (dropdown of POLLER_VERBOSITY values)
  - `cloudflare_accounts.php` handles these actions:
    - `save` (POST)
    - `actions` (POST, with bulk delete, verify, enable and disable)
    - `edit`
    - the default list, with filter, sort and pagination via `validate_store_request_vars`, `html_start_box`, `html_header_sort_checkbox`, `html_nav_bar` and `draw_actions_dropdown`

    Output escaping goes through `html_escape`. The token field is `textbox_password` with an empty value and the help text "Leave blank to keep the current token". The vault source is hidden when `cloudflare_vault()` is null. Status messages use `raise_message`.

- [ ] **Step 1:** Write `SetupContractTest`. It loads `setup.php` with the Cacti functions it calls replaced by recording stubs declared in `tests/Support/CactiGlueStubs.php`. The stubs must match the 1.2.31 signatures quoted in `docs/design.md` and in the Cacti survey; copy the signatures from `git -C ~/Developer/github.com/somethingwithproof/cacti show release/1.2.31:lib/plugins.php`. The tests are:
  - `testInstallRegistersExpectedHooksAndRealms` (exact list)
  - `testVersionParsesInfo`
  - `testCheckConfigFailsWithoutCurl` (inject an extension checker through the `$cloudflare_extension_loaded` global callable, defaulting to `extension_loaded`)
  - `testEveryPhpFileStartsWithLicenseHeader`
  - `testAccountsPageChecksRealmOnEveryMutation` (static scan: each `case 'save'` and `case 'actions'` path calls `cloudflare_account_service()`, whose `AccountService` enforces `Authorizer`; and the page includes `./include/auth.php`)
- [ ] **Step 2:** Run the tests. They must fail.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the tests. They pass, and `make stan cs lint74` passes.
- [ ] **Step 5:** Commit: `feat: add plugin lifecycle and account management page`.

### Task 9: BDD scenarios for Milestone 1

**Files:**
- Create: `behat.yml.dist`, `features/account_configuration.feature`, `features/permission_enforcement.feature`, `features/bootstrap/AccountContext.php`, `features/bootstrap/World.php` (shared real DB, FakeTransport, FrozenClock, temp key file, and a capturing log sink)
- Modify: `Makefile` (`bdd` target)

**Interfaces:**
- Consumes: `AccountService`, `FakeTransport`, `TestDatabase`, `CallbackAuthorizer`.
- Produces: a `World::logLines(): string[]` sink. The `AccountService` used in BDD writes status changes through a `Psr`-free `callable(string): void` logger that glue binds to `cacti_log`. Add an optional `?callable $log` as the last constructor parameter of `AccountService` (default no-op) and log on every status transition. This counts as a Task 7 extension, so add the test `testStatusTransitionsAreLoggedWithoutToken` to `AccountServiceTest` first.

- [ ] **Step 1:** Write the features using the spec's exact Given, When and Then wording from section 5:
  - "Administrator adds a valid Cloudflare account"
  - "User lacks configuration permission"
  - "Unauthorized user attempts credential access", where "request account credential details" maps to `AccountService::list()` plus `find()`, and asserts that no `token`, `ciphertext` or token value is present in the results or logs, and that `tokenMaterial` is not reachable through the public service

  Add a fourth scenario, "Invalid token is reported as authentication failure".
- [ ] **Step 2:** Run `vendor/bin/behat`. The steps are undefined or failing.
- [ ] **Step 3:** Implement the contexts.
- [ ] **Step 4:** Run `vendor/bin/behat`. All scenarios pass.
- [ ] **Step 5:** Commit: `test: add BDD scenarios for account configuration`.

### Task 10: Docker install smoke against Cacti 1.2.31

**Files:**
- Create: `tests/docker/compose.cacti.yml`, `tests/docker/Dockerfile.cacti`, `tests/docker/entrypoint.sh`, `tests/docker/smoke.sh`

**Interfaces:**
- The image builds from `php:7.4-apache` with the extensions Cacti needs (copy the list from Cacti's `tests/e2e/Dockerfile` at `release/1.2.31`) plus curl. Cacti source comes from `git -C ~/Developer/github.com/somethingwithproof/cacti archive release/1.2.31`, produced by `make cacti-src` into `tests/docker/build/cacti.tar`, which is gitignored. The plugin is mounted at `/var/www/html/cacti/plugins/cloudflare`. The DB is seeded from Cacti's `cacti.sql`. The entrypoint writes `include/config.php`.
- `smoke.sh` runs these steps and exits non-zero on the first failure:
  1. `php cli/plugin_manage.php --plugin=cloudflare --install --enable` (check the real flags with `--help` first).
  2. Assert that the `plugin_cloudflare_%` tables exist and `plugin_config.status = 1`.
  3. Disable, re-enable, then install again with no error and tables intact.
  4. Log in as admin over HTTP (handle the csrf-magic token) and get `plugins/cloudflare/cloudflare_accounts.php`, which returns 200 with the "Cloudflare Accounts" title.
  5. Create a user `viewer` with only the "Cloudflare: View" realm, using SQL modeled on `cli/add_perms.php` or `user_auth_realm` inserts. Getting the accounts page as `viewer` must return the permission-denied page.
  6. Uninstall. The plugin tables are gone, and `host`, `graph_local` and `data_template` row counts are unchanged from before the install.
  7. `grep -r` of `log/cacti.log` for the test token string finds nothing.

- [ ] **Step 1:** Write `smoke.sh` first and run it against the stack with the plugin directory empty. It must fail at step 1.
- [ ] **Step 2:** Make the stack build and run with the real plugin. The smoke passes.
- [ ] **Step 3:** Add a `make smoke` target.
- [ ] **Step 4:** Commit: `test: add Cacti 1.2.31 docker install smoke`.

### Task 11: CI and Milestone 1 docs

**Files:**
- Create: `.github/workflows/ci.yml`, `docs/compatibility.md`, `docs/security.md`, `docs/token-permissions.md`, `README.md`, `CHANGELOG.md`, `SECURITY.md`

**Interfaces:**
- CI jobs:
  - `lint` (php-cs-fixer dry run, PHPStan, `php -l` on 7.4)
  - `unit` (PHP 7.4, 8.1, 8.2, 8.3, 8.4)
  - `integration` (MariaDB 10.11 and MySQL 8.4 service containers, PHP 7.4 and 8.3)
  - `bdd`
  - `smoke` (the docker compose stack)
  - `audit` (`composer audit`)
  - `package` (build `cloudflare-<version>.tar.gz` via `git archive` with `.gitattributes` export-ignore for tests, docs/superpowers, vendor and dev configs; assert that `setup.php`, `INFO`, `src/` and `includes/` are present and `tests/` is absent)

  Pin actions to full commit SHAs (the owner keeps a pin table; the conductor supplies the SHAs). Set `permissions: contents: read`.
- `docs/compatibility.md` covers:
  - Cacti 1.2.25 through 1.2.31, with the hooks used and the line references from the survey
  - PHP 7.4 through 8.4
  - MariaDB 10.6+ and MySQL 8.0+
  - Cloudflare endpoints and permissions used, with links into `docs/research`
- `docs/token-permissions.md` lists the exact permission names from the research file: "Cloudflare Tunnel Read" (or the One Connectors equivalents) and "Zone Read". Analytics permissions are marked as Milestone 4.
- README covers what the plugin does today (Milestone 1 scope only), install steps, key-file creation, the retention note, and how to run tests. It claims nothing beyond what is implemented.

- [ ] **Step 1:** Write the workflow. Validate it locally with `actionlint` if installed. Run `make ci` locally and record the output.
- [ ] **Step 2:** Write the docs.
- [ ] **Step 3:** Commit: `ci: add workflow and milestone 1 documentation`.

---

## Later milestones

Each later milestone gets its own plan in this directory, written when the previous one is complete. The plans build on the interfaces above.

- **M2 Discovery:** migration M002 (`tunnels`, `connectors`), `CloudflareClient::listTunnels()` (page/per_page pagination, stopping when the cumulative `count` reaches `total_count` or on an empty page; a partial failure aborts the reconcile), `listConnections()`, `Reconciler` (states `active`, `unavailable`, `deleted`; a failure never changes state; Eris idempotence property), job leasing on `plugin_cloudflare_jobs`, `poller_bottom` and `exec_background` to `poller_cloudflare.php`, the `cloudflare.php` Overview, Tunnels and Connectors views, and the BDD discovery, outage and 429 scenarios.
- **M3 Metrics:** migration M003 (`resources`, `metric_mappings`), a normalized observation schema v1 (`docs/collector-protocol.md`), and `ss_cloudflare.php` script-server functions that read cached state and emit `U` when stale. These cover a "Cloudflare Account" device template (availability none, created only by an explicit admin action), a data query that indexes tunnels per account, data and graph templates exported from a real 1.2.31 instance, and the BDD metrics-integrity scenario.
- **M4 Analytics and alerting:** GraphQL settings-node capability probing, `httpRequestsAdaptiveGroups` collection with documented units, "unavailable" rendering, the Thold template integration when Thold is present, and outage suppression via `consecutive_failures`.
- **M5 Hardening:** TLA+ models for invariants A, C, E and F with TLC run in CI, the fault-injection matrix from spec section 6, and the full documentation set from spec section 11.
