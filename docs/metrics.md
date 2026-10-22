# Metrics

The plugin graphs tunnel state that discovery already stores. Nothing on the
poller path calls Cloudflare: `ObservationReader` (`src/Metrics`) reads the
plugin tables and turns each tunnel into one observation per metric. An
observation has a value, or a null value and a reason. Null prints as `U`.

## Catalog

`Catalog::all()` is the single list that templates, data source names and the
script server's fields come from. Every metric is a `GAUGE` with minimum 0.

| Metric | Query field | DS name | Unit | Max | Kind |
|---|---|---|---|---|---|
| `cloudflare_tunnel_health` | `health` | `health` | state | 3 | state |
| `cloudflare_tunnel_connectors` | `connectors` | `connectors` | connectors | U | state |
| `cloudflare_tunnel_connections` | `connections` | `connections` | connections | U | state |
| `cloudflare_tunnel_connector_versions` | `versions` | `versions` | versions | U | state |
| `cloudflare_collection_age` | `collectAge` | `collect_age` | seconds | U | collection |
| `cloudflare_collection_failures` | `collectFailures` | `collect_fail` | failures | U | collection |

`versions` is the number of distinct cloudflared versions among the tunnel's
current connectors. `collectAge` is the time since the tunnel's connections
were last read successfully, and `collectFailures` is the discovery job's
consecutive failure count.

## Health

| Cloudflare status | Value |
|---|---|
| `healthy` | 3 |
| `degraded` | 2 |
| `down` | 1 |
| `inactive` | 0 |

A status outside this list is unknown with reason `malformed`.

## When a value is printed

A missing value must never be recorded as zero. A zero in an RRD looks like a
real outage and cannot be told apart from one later, so anything the reader
cannot vouch for is `U`.

```
window = 2 * discovery_interval + 60
age    = now - observed_at
```

`now` is the poller's clock. `observed_at` is `last_seen_at` for health and
`connections_refreshed_at` for the connection metrics (`connectors`,
`connections`, `versions`). A state metric has a value only when all of these
hold:

- the account's discovery job `last_status` is `ok` or `partial`;
- the tunnel's `lifecycle` is `active`;
- `observed_at` is set and `-60 <= age <= window`. An age between -60 and 0 is
  clock drift and counts as 0;
- for connection metrics, `connections_attempted_at <= connections_refreshed_at`,
  so the latest attempt succeeded.

The checks run in that order and the first one that fails gives the reason:

1. Job status. No job row or `never` gives `never_collected`. Any status other
   than `ok` or `partial` gives the job's `last_error_class`, or `transient` if
   none is stored.
2. Lifecycle. `unavailable` or `deleted` gives `not_active`.
3. `observed_at` is null: `never_collected`.
4. Age below -60: `clock_skew`. Age above the window: `stale`.
5. Connection metrics only: a later attempt than refresh gives the tunnel's
   `connections_error_class`, or `transient` if none is stored.

A failing job comes first because it explains the rest. Discovery marks
tunnels `unavailable` after repeated outages, so the job's error class is the
more useful reason.

With the default interval of 300 seconds the window is 660 seconds. A tunnel
whose job still reads `ok` but which has not been refreshed for 661 seconds
prints `U` with reason `stale`.

### Collection metrics

`collectAge` and `collectFailures` describe the collector itself, so they stay
printed during an outage. They ignore job status, the `unavailable`
lifecycle and the window. They are unknown when:

- the tunnel is `deleted` (`not_active`);
- the tunnel's connections were never refreshed, or the account has no job row
  (`never_collected`);
- for `collectAge`, the refresh time is more than 60 seconds in the future
  (`clock_skew`).

Their `observed_at` is the read time.

## Reasons

| Reason | Meaning |
|---|---|
| an `ErrorClass` value | The stored class of the failed job or connections read |
| `stale` | Older than the window |
| `clock_skew` | More than 60 seconds in the future |
| `not_active` | The tunnel is `unavailable` or `deleted` |
| `never_collected` | No successful read has been stored yet |
| `unknown_tunnel` | The account has no tunnel with this exact id |

The `ErrorClass` values are `auth`, `permission`, `not_found`, `rate_limited`,
`transient`, `malformed` and `config`.

A tunnel id is matched exactly. The column collation ignores case, so the
reader rejects anything that is not a lowercase UUID before querying.

## Sampling

Cacti samples the latest observation on every poll. Discovery runs once per
interval, so a gauge repeats the same value between discoveries. The RRD
timestamp is the poll time, which can be up to one discovery interval after
`observed_at`.

## Schema version

Observations carry `Version::METRIC_SCHEMA`, which is 1. Version 1 ships
gauges only, so counter resets cannot occur. The `type` field still separates
`GAUGE` from `COUNTER` for later versions. Renaming a metric or DS name, or
changing a type or bound, needs a new schema version because templates and
existing RRD files depend on them.
