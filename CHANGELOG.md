## ChangeLog

--- 0.2.0 (unreleased) ---

Milestone 2: tunnel and connector discovery. Graphs and analytics are not
included.

* feature: Discovery lists each account's tunnels and their connectors through the Cloudflare Tunnel read permission, every Discovery Interval
* feature: Lifecycle states active, unavailable and deleted, kept apart from the status Cloudflare reports; three consecutive failures to read Cloudflare mark an account's active tunnels unavailable, and the next complete listing restores them
* feature: poller_bottom hook starts a background collector on the main poller, one run at a time, limited to 50 seconds and 200 connection requests per run
* feature: Management > Cloudflare Tunnels with Overview, Tunnels and Connectors pages for the Cloudflare: View realm
* feature: Collector Request Timeout setting now applies to the background collector
* feature: Upgrade from 0.1.0 adds the tunnel and connector tables and registers the poller_bottom hook with the plugin's current enabled state

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
