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
| last_status | VARCHAR(20) | Default `never` |
| last_error_class | VARCHAR(20) NULL | |
| consecutive_failures | INT UNSIGNED | Default 0 |

Keys: primary `id`; unique `(job_type, account_id)`; foreign key
`fk_cf_jobs_account`.

## Retention

Uninstall drops the plugin's own tables, which hold inventory, job state and
encrypted credentials. All of it can be rediscovered or re-entered. Uninstall
does not remove Cacti devices, graphs, data sources, RRD files or templates
created for Cloudflare. Those hold historical monitoring data, and removing
them stays an explicit administrator action in Cacti.
