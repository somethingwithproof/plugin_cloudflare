## ChangeLog

--- Unreleased ---

* fix: Exclude the SPDX header assertion string from REUSE metadata parsing
* feature: Add the Operator realm and Operations page with current observations and personal acknowledgments. Existing administrators inherit Operator access; operators inherit monitoring access without configuration rights.
* feature: Explicit retirement of deleted tunnels, preserving shared data sources and using Cacti APIs for removal
* feature: Capability-aware cached zone analytics, gauge graph templates, a Zone Analytics tab and optional Thold templates
* feature: Export filtered tunnel inventories and connector details as CSV, with spreadsheet formula protection

--- 0.3.0 (2026-11-15) ---

Milestone 3: tunnel metrics and graphs. Zone analytics and alerting are not
included.

* issue: The tunnel listing ends at the first page shorter than per_page instead of trusting total_count, which the API schema describes as the total without search parameters; an account whose total_count also counted deleted tunnels would otherwise fail discovery as malformed
* issue: The Cloudflare Tunnels page no longer runs the plugin upgrade, so a user with only Cloudflare: View cannot start a migration or template import
* feature: Token File Directory setting; when set, a token file outside that directory is refused before it is opened
* feature: ss_cloudflare.php is a script server data query that reads each tunnel's health, connectors, connections, connector versions, collection age and collection failures from the plugin tables and never calls Cloudflare
* feature: Health, connectors, connections and versions are printed only when the account's last discovery was ok or partial, the tunnel is active and the reading is no older than twice the Discovery Interval plus 60 seconds; otherwise they are U, so a gap in a graph never records a zero
* feature: Collection age and collection failures stay printed during an outage and are U only for a deleted tunnel or one whose connections were never refreshed
* feature: The Cloudflare Account host template, the Cloudflare Tunnels data query and the Connectors, Health and Collection graph templates are imported at install and upgrade, and a tunnel's three graphs share one RRD file
* feature: Create Cacti Device action on the Cloudflare Accounts page makes the account's device with the hostname <account ID>.cloudflare.invalid; it needs both the Cloudflare: Configure and Sites/Devices/Data realms
* feature: Create Graphs account setting; after each ok or partial discovery the collector reindexes the device and creates the missing graphs of active tunnels, at most 50 graphs and 20 seconds per run
* feature: Install and upgrade copy the data query XML to resource/script_server/cloudflare_tunnels.xml, the plugin's only write outside its own directory; uninstall keeps the file with the devices, graphs, RRD files and templates
* feature: Upgrade adds the resources and metric_mappings tables, the create_graphs account column and the connections_error_class tunnel column
* feature: The collector skips its run and logs one line while a plugin upgrade is pending
* feature: Translations into Spanish (es-ES, es-419), French, German, Simplified Chinese and Japanese; Cacti 1.2 has no Latin American Spanish locale, so es-419 takes effect only once Cacti adds one
* feature: locales/build_gettext.sh regenerates locales/po/cacti.pot and the catalogs; CI and make i18n-check fail on a stale template, a missing, untranslated or fuzzy entry, or an out-of-date .mo; the release archive ships locales/po and locales/LC_MESSAGES
* fix: Account validation errors and the stored account status messages are translated when shown; they stay English in the database
* chore: The PHP namespace is now Somethingwithproof\Cloudflare instead of Cacti\PluginCloudflare; this plugin is not part of the Cacti project. Code outside the plugin does not use its classes, so nothing else changes

--- 0.2.0 (not released separately; included in 0.3.0) ---

Milestone 2: tunnel and connector discovery. Graphs and analytics are not
included.

* feature: Discovery lists each account's tunnels and their connectors through the Cloudflare Tunnel read permission, every Discovery Interval
* feature: Lifecycle states active, unavailable and deleted, kept apart from the status Cloudflare reports; three consecutive failures to read Cloudflare mark an account's active tunnels unavailable, and the next complete listing restores them
* feature: poller_bottom hook starts a background collector on the main poller, one run at a time, limited to 50 seconds and 200 connection requests per run
* feature: Management > Cloudflare Tunnels with Overview, Tunnels and Connectors pages for the Cloudflare: View realm
* feature: Collector Request Timeout setting now applies to the background collector
* feature: Upgrade from 0.1.0 adds the tunnel and connector tables and registers the poller_bottom hook with the plugin's current enabled state
* feature: After replacing the files, open Console > Configuration > Plugins or Cloudflare Accounts once as an admin, so the migration runs and the poller hook is registered; until then nothing is collected

--- 0.1.0 (not released separately; included in 0.3.0) ---

Milestone 1: account configuration and a connection test. Tunnel discovery,
graphs and analytics are not included.

* feature: Install, upgrade, disable and uninstall through Cacti's plugin manager, with idempotent schema migrations serialized by a database lock
* feature: Cloudflare: View and Cloudflare: Configure realms; Configure is granted to admin at install
* feature: Cloudflare Accounts page under Configuration to add, edit, delete, enable, disable and verify accounts
* feature: Scoped API tokens stored in a token file or encrypted in the Cacti database with AES-256-GCM; Global API Keys are refused
* feature: Verify checks the token and probes tunnel and zone read permissions, recording a status on the account
* feature: Settings > Cloudflare tab for the vault key path and request timeouts
* feature: Outbound requests limited to https://api.cloudflare.com with TLS verification, no redirects and a response size cap
