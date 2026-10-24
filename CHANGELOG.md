## ChangeLog

--- 0.3.0 (unreleased) ---

Milestone 3: tunnel metrics and graphs. Zone analytics and alerting are not
included.

* feature: ss_cloudflare.php is a script server data query that reads each tunnel's health, connectors, connections, connector versions, collection age and collection failures from the plugin tables and never calls Cloudflare
* feature: Health, connectors, connections and versions are printed only when the account's last discovery was ok or partial, the tunnel is active and the reading is no older than twice the Discovery Interval plus 60 seconds; otherwise they are U, so a gap in a graph never records a zero
* feature: Collection age and collection failures stay printed during an outage and are U only for a deleted tunnel or one whose connections were never refreshed
* feature: The Cloudflare Account host template, the Cloudflare Tunnels data query and the Connectors, Health and Collection graph templates are imported at install and upgrade, and a tunnel's three graphs share one RRD file
* feature: Create Cacti Device action on the Cloudflare Accounts page makes the account's device with the hostname <account ID>.cloudflare.invalid; it needs both the Cloudflare: Configure and Sites/Devices/Data realms
* feature: Create Graphs account setting; after each ok or partial discovery the collector reindexes the device and creates the missing graphs of active tunnels, at most 50 graphs and 20 seconds per run
* feature: Install and upgrade copy the data query XML to resource/script_server/cloudflare_tunnels.xml, the plugin's only write outside its own directory; uninstall keeps the file with the devices, graphs, RRD files and templates
* feature: Upgrade adds the resources and metric_mappings tables, the create_graphs account column and the connections_error_class tunnel column
* feature: The collector skips its run and logs one line while a plugin upgrade is pending

--- 0.2.0 (unreleased) ---

Milestone 2: tunnel and connector discovery. Graphs and analytics are not
included.

* feature: Discovery lists each account's tunnels and their connectors through the Cloudflare Tunnel read permission, every Discovery Interval
* feature: Lifecycle states active, unavailable and deleted, kept apart from the status Cloudflare reports; three consecutive failures to read Cloudflare mark an account's active tunnels unavailable, and the next complete listing restores them
* feature: poller_bottom hook starts a background collector on the main poller, one run at a time, limited to 50 seconds and 200 connection requests per run
* feature: Management > Cloudflare Tunnels with Overview, Tunnels and Connectors pages for the Cloudflare: View realm
* feature: Collector Request Timeout setting now applies to the background collector
* feature: Upgrade from 0.1.0 adds the tunnel and connector tables and registers the poller_bottom hook with the plugin's current enabled state
* feature: After replacing the files, open Console > Configuration > Plugins or Cloudflare Accounts once as an admin, so the migration runs and the poller hook is registered; until then nothing is collected

--- 0.1.0 (unreleased) ---

Milestone 1: account configuration and a connection test. Tunnel discovery,
graphs and analytics are not included.

* feature: Install, upgrade, disable and uninstall through Cacti's plugin manager, with idempotent schema migrations serialized by a database lock
* feature: Cloudflare: View and Cloudflare: Configure realms; Configure is granted to admin at install
* feature: Cloudflare Accounts page under Configuration to add, edit, delete, enable, disable and verify accounts
* feature: Scoped API tokens stored in a token file or encrypted in the Cacti database with AES-256-GCM; Global API Keys are refused
* feature: Verify checks the token and probes tunnel and zone read permissions, recording a status on the account
* feature: Settings > Cloudflare tab for the vault key path and request timeouts
* feature: Outbound requests limited to https://api.cloudflare.com with TLS verification, no redirects and a response size cap
