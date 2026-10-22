# Collector protocol

Cacti's poller reads tunnel metrics through `ss_cloudflare.php`, a script
server data query. The script answers from the plugin tables only. It makes
no network calls and never contacts Cloudflare; the collector
(`poller_cloudflare.php`) writes the tables and the script reads them.

This document describes what the script accepts and prints, and what any
other engine that writes the tables must provide for the script to keep
working.

## Calls

The data query XML prepends the device id (`<arg_prepend>|host_id|</arg_prepend>`)
and Cacti appends the verb and its arguments (`lib/utility.php:544` in
1.2.31). The script server calls `ss_cloudflare()` with those arguments. A
reindex runs the same file as a CLI script with the same arguments
(`lib/data_query.php:656`).

| Call | Output |
|---|---|
| `<host_id> index` | One `cf_tunnel_id` per line |
| `<host_id> num_indexes` | The number of indexes |
| `<host_id> query tunnelId` | `<cf_tunnel_id>!<cf_tunnel_id>` per line |
| `<host_id> query tunnelName` | `<cf_tunnel_id>!<name>` per line |
| `<host_id> query <output field>` | `<cf_tunnel_id>!U` per line |
| `<host_id> get <output field> <cf_tunnel_id>` | One value, or `U` |

The output fields are the `queryField` values in the catalog
(`docs/metrics.md`): `health`, `connectors`, `connections`, `versions`,
`collectAge` and `collectFailures`. Output fields are read per poll through
`get`; their `query` form exists so a reindex sees every field and prints
`U` for each tunnel.

## Output grammar

```
listing = *( line LF )
line    = tunnel-id [ "!" value ]
value   = 1*( any character except CR and LF )
get     = 1*DIGIT / "U"
```

`tunnel-id` is a lowercase UUID. Cacti splits each `query` line at the first
`!` (`lib/data_query.php:748`), so a tunnel name may contain `!`. A carriage
return or line feed in a name is printed as a space, because it would start a
new line. The script server adds the trailing newline after `get` itself.

The index is ordered by `cf_tunnel_id` and lists every tunnel of the
account, `deleted` included. Cacti marks a data source orphaned when its index
disappears from a reindex (`lib/data_query.php:317`), so a deleted tunnel keeps
its index, graph and RRD file and prints `U`.

## Unknown values

`get` prints a number only when `ObservationReader` can vouch for it. The
rules, the observation window and the reasons are in `docs/metrics.md`.
Beyond those, the script prints `U` when:

- `host_id` does not match `^[1-9][0-9]{0,9}$`;
- the device is not an account's device in `plugin_cloudflare_resources`;
- the field is not a catalog output field;
- the index is not a lowercase UUID, or is not a tunnel of that account;
- the verb is not one of the forms above;
- anything throws, including a dropped or unreachable database.

For `index` and `query`, the same failures give an empty listing, and
`num_indexes` gives `0` for a failed check. An exception gives `U` for
`num_indexes` and an empty listing for `index` and `query`; a listing line
of `U` would read as a tunnel named `U`. Exceptions are logged at
`POLLER_VERBOSITY_HIGH` with the class name only, never the message.
If `query tunnelName` throws after `index` succeeds in the same reindex,
Cacti drops the cached names until the next reindex, and no data source is
orphaned.

## Tables read

| Table | Columns |
|---|---|
| `plugin_cloudflare_resources` | `account_id`, `host_id` where `kind = 'device'` |
| `plugin_cloudflare_accounts` | `discovery_interval` |
| `plugin_cloudflare_jobs` | `last_status`, `last_error_class`, `consecutive_failures` where `job_type = 'discovery'` |
| `plugin_cloudflare_tunnels` | `cf_tunnel_id`, `name`, `cf_status`, `lifecycle`, `connector_count`, `connection_count`, `connections_refreshed_at`, `connections_attempted_at`, `connections_error_class`, `last_seen_at` |
| `plugin_cloudflare_connectors` | `tunnel_id`, `version` |

The script uses Cacti's database handle, the same one `cloudflare_db()`
returns elsewhere, and writes nothing.

## Main poller only

INFO declares `remote_collect:0`. The plugin's tables live in the main
Cacti database, and the account device is created with `poller_id` 1, so the
script server that reads them runs on the main poller. The script compares
stored times with PHP `time()` on that host, which is the clock the collector
stamps with. A remote poller would read a different database and could run on
a different clock.

## Writing the tables from another engine

Another collector can replace `poller_cloudflare.php` if it writes the same
state. The script then needs no change. To stay compatible, it must:

- store `cf_tunnel_id` as a lowercase UUID, and keep a tunnel's row when the
  tunnel disappears from Cloudflare, setting `lifecycle` to `deleted`;
- set `lifecycle` to `active`, `unavailable` or `deleted`;
- store `cf_status` as Cloudflare's status string (`healthy`, `degraded`,
  `down` or `inactive`);
- set `last_seen_at` each time the tunnel is seen in a listing;
- on a successful connections read, write `connector_count`,
  `connection_count` and the connector rows (one per current connector, with
  its `version`), then set `connections_refreshed_at` and
  `connections_attempted_at` to the same time and clear
  `connections_error_class`;
- on a failed connections read, set `connections_attempted_at` and
  `connections_error_class` and leave the counts and
  `connections_refreshed_at` alone;
- keep one `discovery` job row per account, with `last_status` of `ok`,
  `partial` or `failed`, `last_error_class` on failure, and
  `consecutive_failures` reset to 0 on success;
- write every time as UTC epoch seconds from a clock that agrees with the
  main poller's to within 60 seconds.

`last_error_class` and `connections_error_class` take the `ErrorClass` values
listed in `docs/metrics.md`. Changing what a stored column means changes the
printed metric, so it needs a new `Version::METRIC_SCHEMA`.
