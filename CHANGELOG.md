## ChangeLog

--- 1.0.0 (unreleased) ---

Milestone 1: account configuration and a connection test. Tunnel discovery,
graphs and analytics are not included.

* feature: Install, upgrade, disable and uninstall through Cacti's plugin manager, with idempotent schema migrations serialized by a database lock
* feature: Cloudflare: View and Cloudflare: Configure realms; Configure is granted to admin at install
* feature: Cloudflare Accounts page under Configuration to add, edit, delete, enable, disable and verify accounts
* feature: Scoped API tokens stored in a token file or encrypted in the Cacti database with AES-256-GCM; Global API Keys are refused
* feature: Verify checks the token and probes tunnel and zone read permissions, recording a status on the account
* feature: Settings > Cloudflare tab for the vault key path and request timeouts
* feature: Outbound requests limited to https://api.cloudflare.com with TLS verification, no redirects and a response size cap
