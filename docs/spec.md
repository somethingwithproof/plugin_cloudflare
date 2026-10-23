# Cacti Cloudflare Plugin: Requirements

This is the requirements source for the plugin. It is the original project
brief with the owner's amendments applied.

**Owner amendments (2026-10-21):**

- The optional Rust collector is out of scope. Sections 2.3, 9, the collector
  parity BDD scenario, and Milestone 6 are deferred. The PHP collector still
  emits a versioned, documented metric schema so a second engine can be added
  later.
- The plugin must operate on Cacti 1.2.x. Cacti 1.3 compatibility is not a
  release criterion.
- Database access uses PDO.

---

## Role and goal

Plugin name: `cloudflare`. Language: PHP. Target: the current supported Cacti
1.2.x release.

Deliver a functional, distributable plugin, not a prototype. Do not claim
something is implemented or tested unless the repository and test results
demonstrate it. Do not invent Cacti hooks, API methods, database structures,
or Cloudflare metrics. Verify every external interface against source code or
current documentation. Record version assumptions in `docs/compatibility.md`.
Do not make breaking changes to existing Cacti installations.

## 1. Discovery (completed before implementation)

Inspect Cacti, its plugin system, Thold/Monitor/ReportIt patterns, and
Cloudflare's REST, GraphQL Analytics, and Tunnel documentation. Document
unsupported or plan-restricted capabilities and licensing constraints.

## 2. Architectural principles

### 2.1 Zero core modifications
No Cacti core changes. Supported plugin hooks only. Plugin-owned tables.
Native data inputs, graph templates, and RRDtool integration. Established
install and upgrade conventions. No monkey-patching.

### 2.2 Cloudflare must remain optional
Cacti keeps working when Cloudflare is unavailable, credentials expire,
Cloudflare rate-limits, or the plugin is disabled or uninstalled. No
Cloudflare request may block the Cacti UI indefinitely or stall unrelated
device polling.

### 2.3 Collector interface
(Rust deferred.) Normalized metrics define timestamps, units, null handling,
counter semantics, and error classifications in a versioned schema.

### 2.4 Read-only integration
Version 1 does not modify Cloudflare resources. Minimum API permissions.

### 2.5 Security by design
- Encrypt stored credentials; prefer externally provisioned secrets.
- Never log API tokens or authorization headers.
- Enforce Cacti authorization realms.
- Prevent CSRF, XSS, SQL injection, SSRF, privilege escalation.
- Validate external identifiers and responses.
- TLS certificate validation. Strict outbound endpoint controls.
- Never expose cloudflared's local metrics endpoint publicly.

## 3. Required features

### 3.1 Plugin lifecycle
Install, upgrade, disable/re-enable, configuration checks, migrations,
uninstall, permission registration, menu integration, compatibility checks.
Test repeated installation, interrupted upgrades, idempotent migrations.
Uninstall must not remove unrelated Cacti objects or shared data sources.
Where removal could destroy historical monitoring data, require an explicit
documented retention decision.

### 3.2 Account management
Multiple accounts. Each has: account identifier, name, scoped API token
reference, connection status, last successful verification, permission
diagnostics, enabled state, collection settings. Secure connection test.
Handle invalid tokens, revoked permissions, unavailable endpoints, malformed
responses. Do not store Global API Keys.

### 3.3 Tunnel discovery
Discover tunnels (ID, name, status), connectors (ID, version), connection
metadata, and connector relationships. Pagination and incremental sync. Use
the connections endpoint for connector detail. Preserve resources through
temporary API failures. Explicit lifecycle states: active, unavailable,
deleted. Idempotent.

### 3.4 Tunnel monitoring
Availability, connector count, connection count, connector version, health
state, last successful collection timestamp, consecutive collection failures.
Collect latency/throughput/error/traffic only where a documented source
provides them. Distinguish failed collection from a tunnel outage.

### 3.5 Analytics
Capability-aware GraphQL collector (HTTP requests, traffic, error rates,
cache, security). Before enabling a dataset verify scope, permissions, plan
availability, retention/resolution, actual schema, and document units and
aggregation semantics. Unsupported analytics show as unavailable, not zero.
Configurable intervals that respect rate limits.

### 3.6 Graph integration
Data input methods, data templates, graph templates, resource mappings,
automated graph creation. RRDtool-compatible types. Correct handling of
counters, gauges, counter resets, missing samples, unknown values, timestamp
alignment, duplicate observations, unit normalization. Never fabricate
observations. A tunnel must not become a standard SNMP device unless that
matches Cacti's object model and is explicitly enabled.

### 3.7 User interface
Sections: Overview, Accounts, Tunnels, Connectors, Analytics, Settings,
Diagnostics. Cacti UI conventions. Filtering and pagination. Permissions on
every page and action. No SPA framework.

### 3.8 Thold integration
When Thold is installed: tunnel unavailable, connector count below minimum,
repeated collection failures, error rate above threshold. Thold is not a
required dependency. No alert storms during API outages.

## 4. TDD
RED, GREEN, REFACTOR for every production feature. Do not retrofit tests. Do
not weaken assertions. Do not mock the implementation under test. PHPUnit,
PHPStan, Cacti-consistent style checks.

## 5. BDD
`features/` holds executable Gherkin run with Behat, backed by automated
steps. The scenario wording below is the owner's and is used verbatim. Steps
may append further `And` checks, but must not reword these lines. The
collector-parity scenario is deferred along with the Rust collector.

```gherkin
Feature: Account configuration

  Scenario: Administrator adds a valid Cloudflare account
    Given I am authenticated as a Cacti administrator
    And I have a valid scoped Cloudflare API token
    When I configure a Cloudflare account
    And I verify the connection
    Then the account should be saved
    And the connection status should indicate success
    And the token must not appear in application logs

  Scenario: User lacks configuration permission
    Given I am authenticated without Cloudflare configuration privileges
    When I attempt to configure an account
    Then the request must be rejected
    And no configuration should change

Feature: Tunnel discovery

  Scenario: Discover tunnels successfully
    Given a configured Cloudflare account
    And the Cloudflare API returns three tunnels
    When the discovery process executes
    Then Cacti should store exactly three discovered tunnels
    And each tunnel should retain its Cloudflare identifier

  Scenario: Repeated discovery does not create duplicates
    Given three tunnels have already been discovered
    When the discovery process executes again with unchanged results
    Then the tunnel count must remain three
    And existing resource associations must be preserved

Feature: Cloudflare outage

  Scenario: Cloudflare becomes unavailable
    Given Cacti is monitoring existing devices
    And the Cloudflare API becomes unavailable
    When the polling cycle executes
    Then unrelated Cacti polling must continue
    And Cloudflare observations must be classified as unavailable
    And existing graph history must remain intact

Feature: API rate limiting

  Scenario: Cloudflare returns HTTP 429
    Given the API request limit has been reached
    When the Cloudflare collector receives HTTP 429
    Then it must apply bounded retry handling
    And honor supported retry information
    And defer collection when the retry budget is exhausted
    And it must not block the Cacti user interface

Feature: Metrics integrity

  Scenario: An API request fails
    Given a tunnel previously reported four connections
    When the next metrics request fails
    Then the observation must be marked unknown
    And the system must not record zero connections
    And the previous historical samples must remain unchanged

Feature: Permission enforcement

  Scenario: Unauthorized user attempts credential access
    Given I have permission to view Cloudflare graphs
    And I do not have permission to administer Cloudflare accounts
    When I request account credential details
    Then the request must be denied
    And no credential material must be returned
```

## 6. Adversarial and failure tests
Expired token, wrong permissions, timeouts, DNS failure, TLS failure, 429,
500/502/503, malformed JSON, missing fields, wrong types, empty lists, partial
pagination, duplicate tunnel records, stale connector state, DB transaction
failure, interrupted migration, concurrent discovery, collector crash,
unsupported GraphQL dataset, clock skew, missing intervals, large accounts,
duplicate scheduled collection, temporary outage, plugin disable during
collection. Deterministic fixtures, controllable clocks, no live account
required; live tests optional with dedicated credentials.

## 7. Formal specification
TLA+/PlusCal for discovery/reconciliation, concurrent collectors, failure and
recovery, migration states, duplicate-job suppression. Invariants:
- A: an outage never deletes previously discovered resources.
- B: a failed collection never produces a fabricated zero.
- C: repeated discovery never creates duplicate identities.
- D: only authorized users change configuration.
- E: concurrent collection cannot produce inconsistent ownership or duplicate
  committed observations.
- F: plugin failures cannot disable unrelated polling.
State which invariants are modeled and which are integration-tested.

## 8. Database
Namespaced tables: `plugin_cloudflare_accounts`, `_tunnels`, `_connectors`,
`_resources`, `_jobs`, `_metric_mappings`. Validated against Cacti's
supported databases. Uniqueness via constraints. Tested migrations. No
time-series duplication of RRD data.

## 10. CI
Unit, BDD, static analysis, style, PHP version matrix, DB integration, plugin
install, upgrade, security scanning, dependency audit, build artifact
verification. Reproducible local environment (Docker Compose). No production
credentials. No publishing without authorization.

## 11. Documentation
README, installation, configuration, token permissions, metric catalog,
architecture, schema, developer guide, collector protocol, troubleshooting,
security, compatibility matrix, release notes, coverage report, known
limitations. Distinguish Free vs paid-plan features. Document behavior when
Cloudflare is unreachable.

## 12. Milestones
1. Foundation: installable plugin, migrations, permissions, API client,
   account configuration, unit tests, BDD scenarios.
2. Discovery: tunnel/connector enumeration, pagination, reconciliation, sync
   jobs, inventory UI.
3. Metrics: health collector, normalized observations, data sources, graph
   templates, polling integration.
4. Analytics and alerting: GraphQL analytics, Thold, outage alert suppression.
5. Hardening: security, fault injection, compatibility, performance, docs.

Each milestone leaves the repository consistent and testable.

## 13. Definition of done
Installs on supported Cacti without core changes; secure account
configuration; discovery works against fixtures and optional live tests;
graph templates populated from real observations; Cloudflare failures do not
affect other polling; BDD, unit, integration, security checks pass;
migrations, install, upgrade, disable/re-enable verified; docs match code; no
placeholders; limitations documented. At least 85% meaningful coverage of new
collection and domain logic; full branch coverage of authorization and
credential paths where practicable.
