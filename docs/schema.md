# Schema

Tables are created by plugin migrations (`src/Storage/Migrations`), applied by
`Migrator` under `GET_LOCK('plugin_cloudflare_migrate', 10)`. All tables are
InnoDB, utf8mb4. Timestamps are UTC epoch seconds in `INT UNSIGNED` columns.
The applied version is recorded last, so an interrupted migration is finished
by running it again.

## plugin_cloudflare_migrations

| Column | Type | Notes |
|---|---|---|
| version | INT UNSIGNED | Primary key |
| applied_at | INT UNSIGNED | |

## plugin_cloudflare_accounts

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED | Primary key, auto increment |
| cf_account_id | CHAR(32) | Unique |
| name | VARCHAR(100) | |
| token_source | ENUM('vault','file') | |
| token_ciphertext | TEXT NULL | `v1:` format, vault source only |
| token_file | VARCHAR(255) NULL | File source only |
| enabled | TINYINT(1) | Default 1 |
| status | VARCHAR(20) | Default `unknown` |
| status_message | VARCHAR(255) | Default empty |
| permissions | TEXT NULL | JSON |
| last_checked_at | INT UNSIGNED NULL | |
| last_verified_at | INT UNSIGNED NULL | |
| collect_tunnels | TINYINT(1) | Default 1 |
| collect_analytics | TINYINT(1) | Default 0 |
| discovery_interval | INT UNSIGNED | Seconds, default 300 |
| created_at | INT UNSIGNED | |
| updated_at | INT UNSIGNED | |

Keys: primary `id`; unique `cf_account_id`.

## plugin_cloudflare_jobs

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED | Primary key, auto increment |
| job_type | VARCHAR(32) | |
| account_id | INT UNSIGNED | Foreign key to accounts, `ON DELETE CASCADE` |
| lease_owner | VARCHAR(64) NULL | |
| lease_until | INT UNSIGNED NULL | |
| next_run_at | INT UNSIGNED | Default 0 |
| last_started_at | INT UNSIGNED NULL | |
| last_finished_at | INT UNSIGNED NULL | |
| last_success_at | INT UNSIGNED NULL | Added by migration 2; set when a run finishes `ok` or `partial`, kept on failure |
| last_status | VARCHAR(20) | Default `never` |
| last_error_class | VARCHAR(20) NULL | |
| consecutive_failures | INT UNSIGNED | Default 0 |
| outage_failures | INT UNSIGNED | Added by migration 2; default 0 |

Keys: primary `id`; unique `(job_type, account_id)`; foreign key
`fk_cf_jobs_account`.

## plugin_cloudflare_tunnels

Added by migration 2. Lifecycle is the plugin's view of the tunnel; `cf_status`
is Cloudflare's raw health (`healthy`, `degraded`, `down`, `inactive`).

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED | Primary key, auto increment |
| account_id | INT UNSIGNED | Foreign key to accounts, `ON DELETE CASCADE` |
| cf_tunnel_id | CHAR(36) | Cloudflare UUID |
| name | VARCHAR(255) | |
| cf_status | VARCHAR(16) | |
| tun_type | VARCHAR(32) | |
| lifecycle | ENUM('active','unavailable','deleted') | Default `active` |
| created_at_cf | INT UNSIGNED NULL | |
| conns_active_at | INT UNSIGNED NULL | |
| conns_inactive_at | INT UNSIGNED NULL | |
| connector_count | INT UNSIGNED NULL | |
| connection_count | INT UNSIGNED NULL | |
| first_seen_at | INT UNSIGNED | |
| last_seen_at | INT UNSIGNED | |
| lifecycle_changed_at | INT UNSIGNED | |

Keys: primary `id`; unique `(account_id, cf_tunnel_id)`; key `lifecycle`;
foreign key `fk_cf_tunnels_account`.

## plugin_cloudflare_connectors

Added by migration 2.

| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED | Primary key, auto increment |
| tunnel_id | INT UNSIGNED | Foreign key to tunnels, `ON DELETE CASCADE` |
| cf_connector_id | CHAR(36) | Cloudflare UUID |
| version | VARCHAR(32) | |
| arch | VARCHAR(32) | |
| run_at | INT UNSIGNED NULL | |
| connection_count | INT UNSIGNED | |
| colos | VARCHAR(255) | Comma-joined `colo_name`, sorted |
| origin_ips | VARCHAR(255) | |
| last_seen_at | INT UNSIGNED | |

Keys: primary `id`; unique `(tunnel_id, cf_connector_id)`; foreign key
`fk_cf_connectors_tunnel`.

## Retention

Uninstall drops the plugin's own tables, which hold inventory, job state and
encrypted credentials. All of it can be rediscovered or re-entered. Uninstall
does not remove Cacti devices, graphs, data sources, RRD files or templates
created for Cloudflare. Those hold historical monitoring data, and removing
them stays an explicit administrator action in Cacti.
