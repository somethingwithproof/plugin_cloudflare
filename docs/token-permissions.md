# API Token Permissions

The plugin accepts only scoped API tokens. Global API Keys are refused,
and the plugin never asks for an account email. Every request it makes is a
read; no permission with write access is needed. Permission names below are
the labels from Cloudflare's documentation, as recorded in
[research/cloudflare-api-facts.md](research/cloudflare-api-facts.md#3-token-permissions).

## Milestone 1

| Permission | Scope | Required | Used for |
|---|---|---|---|
| `Cloudflare Tunnel Read` | Account | Yes, or one of the equivalents below | The tunnel probe in Verify, and tunnel discovery from Milestone 2 |
| `Zone Read` | Zone | No | The zone probe in Verify. Nothing else uses it yet |

Cloudflare accepts any one of these for the tunnel endpoints, and the
matching Write permissions also satisfy them:

- `Cloudflare Tunnel Read`
- `Cloudflare One Connectors Read`
- `Cloudflare One Connector: cloudflared Read`

The Cloudflare API reference lists the zone permission as `Zone Zone Read`;
the permissions page lists it as `Zone Read`. Both names refer to the same
permission.

Token verification (`/tokens/verify`) needs no permission. Both account-owned
and user-owned tokens work. A user-owned token gets HTTP 401 from the account
verify endpoint, and the plugin then retries against the user endpoint.

### What Verify reports

Verify records the outcome on the account and shows it on the edit page
under Connection Status:

- **ok**: the token is active and the tunnel probe succeeded.
- **permission_denied**: the token is active but the tunnel probe was
  refused, or both token verify endpoints answered HTTP 403. Add one of the
  tunnel permissions above.
- **Permission: tunnel_read** and **Permission: zone_read**: whether each
  probe was granted, from the most recent Verify that reached both probes.
  Changing the account ID, token, token storage or token file clears them.

A missing zone permission does not make Verify fail.

## Milestone 4: analytics (planned)

Analytics collection is not implemented. The "Collect Analytics" setting is
stored but nothing reads it yet. When analytics ships it is expected to need:

| Permission | Scope |
|---|---|
| `Account Analytics Read` | Account |
| `Analytics Read` | Zone |

Cloudflare's GraphQL guide configures the token with Account Analytics Read,
and the permissions page lists Analytics Read under Zone permissions. Which
of the two a zone-level `httpRequestsAdaptiveGroups` query strictly needs has
not been verified.

## Creating the token

Create a custom API token in the Cloudflare dashboard, either a user API
token or an account-owned token. Give it Account > Cloudflare Tunnel > Read,
and optionally Zone > Zone > Read. Limit the account resources to the account
you will monitor and, if you added Zone Read, the zone resources to that
account's zones. Store the token as described in the README.
