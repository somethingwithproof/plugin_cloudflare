# Zone analytics and alerting

Enable Collect Analytics on a Cloudflare account with Zone Read, Analytics Read for the selected zones and Account Analytics Read. Analytics runs only on the main poller. No API request runs from the viewer or a script-server read.

The collector lists the account’s zones and checks `settings.httpRequestsAdaptiveGroups` per zone. Disabled datasets or limits that cannot cover the window are recorded as unsupported. The first version collects requests, edge response bytes, HTTP 5xx counts and sampling interval. Cache and security-event datasets are not collected yet.

Queries cover a complete five-minute UTC window ending at least two minutes ago. Data is sampled: it is not a billing total. Requests and bytes are divided by 300 and stored as GAUGE rates; HTTP 5xx is a percentage of requests. A successful empty aggregate is zero traffic. A missing aggregate, partial GraphQL error, failed account job, disabled account or stale cache produces `U`; zero traffic has an unknown error percentage. Age is seconds since the last cached sample, rather than the end of the measurement window.

Each run permits at most 20 analytics HTTP requests and approximately 15 seconds before continuing tunnel discovery with the remaining collector budget. A request already in progress may finish after the soft deadline; analytics timeouts are three seconds with no immediate retry. Rate-limit responses defer the account job. Zone listing has a 20-page safety limit. Zones awaiting a budget slot are ordered by their last attempt. Large accounts can exceed the freshness window and show gaps. The read-only analytics page shows at most 200 zones.

## Graph setup

1. Enable Collect Analytics and save.
2. Choose Create Cacti Device for the account. Repeat this action for an existing device to install the zone templates; the existing device is preserved. This needs both Configure and Cacti device-management permission.
3. In the device’s Associated Data Queries, add Cloudflare Zones and reindex it.
4. Create the Traffic, HTTP 5xx and Collection graphs for the desired zones through Cacti’s normal graph workflow. Zone graphs are opt-in; the tunnel Create Graphs option does not create them.

The templates use `ss_cloudflare_zones.php`, which checks the host-to-account association and reads only cached records. The data query uses Cloudflare zone tags as indexes. Every output is a GAUGE; imported templates do not replace tunnel hashes. The sampling interval and age remain separate series from traffic.

## Thold

Import `templates/cloudflare_thold_templates.xml` using Thold’s template importer after the corresponding Cacti templates exist. It supplies optional thresholds for tunnel service state, missing connectors, repeated collection failures, stale tunnel data, zone HTTP 5xx percentage and stale zone data. Set notification receivers and review thresholds before creating enabled thresholds. The low health threshold includes inactive tunnels; apply it only to tunnels expected to run.

Traffic and state metrics become unknown during an outage. A separate age threshold detects stale collection, avoiding a fabricated zero or a false traffic recovery. Use Maint’s existing device maintenance and Thold suppression; the plugin creates no parallel notification engine and does not automatically create maintenance schedules.

## Retirement

A Configure user with Cacti device-management permission can choose Retire tunnel for a deleted tunnel, review the confirmation, and submit the CSRF-protected POST. Active and unavailable tunnels are refused. The backend checks device and graph permissions and validates recorded graph/data-source identities before removing anything. Shared data sources are preserved. Failed removals remain retryable. Discovery of the same tunnel clears retirement and permits graph creation again. Automatic retention is not implemented.

## Verification and limits

Docker unit and database tests exercise synthetic Cloudflare responses, capability denial, partial errors, zero traffic, missing fields, account/host isolation, outage gating, CSV filtering, retirement guards and rediscovery. They do not establish live Cloudflare plan behavior or deployment readiness. All six Thold templates imported successfully into Thold 1.8.3 on Cacti 1.2.31 in Docker. Notification firing, recovery and Maint suppression have not been qualified end to end; importing templates does not establish those flows. Hardware and deployment evidence remain deferred.

API contracts: [dataset settings](https://developers.cloudflare.com/analytics/graphql-api/features/discovery/settings/), [HTTP aggregate fields](https://developers.cloudflare.com/analytics/graphql-api/migration-guides/graphql-api-analytics/), [GraphQL limits](https://developers.cloudflare.com/analytics/graphql-api/limits/).
