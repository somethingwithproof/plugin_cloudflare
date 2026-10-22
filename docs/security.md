# Security

This page describes the security controls in Milestone 1 as implemented,
with the code that enforces each one. To report a vulnerability, see
[SECURITY.md](../SECURITY.md).

## Outbound requests

`src/Http/CurlTransport.php` is the only code that opens a network
connection. Before it calls `curl_init()`, `OutboundPolicy::assertAllowed()`
checks the URL against an origin allowlist. The production list, built by
`OutboundPolicy::production()` from a constant, is exactly
`https://api.cloudflare.com` on port 443. No setting can change it.

The policy compares scheme, host and port, and refuses any URL on which PHP's
`parse_url()` and curl's parser could disagree: user info, `@`, backslashes,
fragments, whitespace, control bytes, non-ASCII bytes, and ports that are not
plain digits. A refused URL never resolves a name or opens a socket, and the
URL is not copied into the exception.

`CloudflareClient` validates what goes into the URL before the policy sees
it. An account ID must match `^[0-9a-f]{32}$`, and a request path must match
`^/[A-Za-z0-9/_.-]*$` and must not contain `..`.

Each request sets:

- `CURLOPT_PROTOCOLS` to HTTPS only
- `CURLOPT_FOLLOWLOCATION` off and `CURLOPT_MAXREDIRS` 0
- `CURLOPT_SSL_VERIFYPEER` on and `CURLOPT_SSL_VERIFYHOST` 2
- a connect timeout of 5 seconds and a total timeout from the "Interactive
  Request Timeout" setting (default 10, accepted range 5 to 60 seconds)
- `CURLOPT_NOPROXY` to `*`, so proxy environment variables are ignored
- a 5 MiB response cap; a larger body aborts the transfer

`tests/Unit/Http/CurlTransportOptionsTest.php` asserts these options.
`tests/Integration/Http/CurlTransportTest.php` runs the real transport against
local servers and checks that redirects are not followed, a self-signed
certificate is refused, an oversized body is rejected, a proxy set in the
environment is ignored, and a URL outside the allowlist never connects.

## API tokens

Only scoped API tokens are accepted. `TokenPolicy::validate()` rejects a
37-character lowercase hex string as a Global API Key, with its own message,
and otherwise requires `^[A-Za-z0-9_-]{40,256}$`. The policy runs when a
token is saved and again every time one is loaded.

Each account keeps its token in one of two places.

### Token file

The account stores an absolute path to a file that holds only the token.
`TokenFile::read()` refuses the file unless:

- the path is absolute and is not a symbolic link
- it is a regular file of at most 4096 bytes, checked with `fstat()` on the
  open handle so the checks describe the file actually read
- it is not readable or writable by others (`mode & 0006` is zero)

These checks run when the token is read, at Verify, not when the account is
saved. The web server and poller users can differ, so a save-time check by
the web server could pass or fail for the wrong user. A file that fails the
checks makes Verify record "Token file is missing or insecure".

### Encrypted in the Cacti database (vault)

`src/Secret/TokenVault.php` encrypts the token with AES-256-GCM through
`openssl_encrypt()`, using a random 12-byte nonce and a 16-byte tag. The
additional authenticated data is `plugin_cloudflare:account:<account ID>`,
so a ciphertext copied to another account row fails to decrypt. The stored
value is `v1:` followed by base64 of nonce, tag and ciphertext. Changing an
account's ID requires entering the token again.

The 32-byte key is read from the file named in Settings > Cloudflare > Token
Vault Key File (default `/etc/cacti/cloudflare.key`). The plugin never creates
or writes this file. `TokenVault::fromKeyFile()` accepts it only when:

- the path is absolute and is not a symbolic link
- it is a regular file that others cannot read or write
- it holds exactly 32 raw bytes, or 64 hex characters with at most one
  trailing newline; 32 hex characters (a 16-byte key) are refused

When the file is missing or refused, the vault is off and the edit form
offers only the token file source. A file that exists but is refused is
logged once per request with the reason code only. The key is held outside
the object's properties, so `var_export()`, `(array)` casts and
`get_object_vars()` cannot expose it. The object refuses serialization, and
`var_dump()` prints `[REDACTED]` through `__debugInfo()`.

A database dump on its own does not reveal vault tokens. An attacker who can
read both the database and the key file, for example as the web server user,
can decrypt them.

### Handling in the interface

The edit form never sends a stored token back to the browser. The token
fields are always empty; leaving them blank on save keeps the current token.
The page shows only whether a token is stored. `AccountService::credentialDetails()`
returns the source, the file path and whether a token exists, never the token
or ciphertext, and requires the configure right. A form that fails
validation is redrawn with the other fields kept, but the token is never
written to the session.

## Redaction and logging

Account status messages are fixed sentences such as "Cloudflare API is
unreachable; try again later". Text from Cloudflare error responses is never
copied into them. Status changes are logged to the Cacti log with the account
row ID, the Cloudflare account ID, the old and new status, and an error class
or reason code, never the token.

`Support\Redactor` replaces known secrets, longest first, and any
`Authorization: Bearer` value with `[REDACTED]`. The client runs it over
Cloudflare error messages and curl error text before they enter an
exception. Curl error text also has control bytes removed and is cut to 200
characters.

Tests that handle a token assert it does not appear in logs, exception
messages, or output. The install smoke searches the Cacti logs, the Apache
logs, every saved HTTP response and a full database dump for the token it
used.

## Database errors

Every query is a prepared statement with bound values, and identifiers are
never built from input. A failed statement throws `DatabaseException`, whose
message is exactly `SQLSTATE[<state>] driver error <code>`. Driver text is
dropped because MySQL echoes statement text in it, and with emulated
prepares that text includes bound values. The page logs that message and
shows Cacti's generic save error.

## Access control

| Realm | Page | Default |
|---|---|---|
| Cloudflare: Configure | `cloudflare_accounts.php` | Granted to admin at install |
| Cloudflare: View | `cloudflare.php` | Not granted; the page ships in Milestone 2 |

`include/auth.php` enforces the realm by page name. `AccountService` checks
again before every change, every Verify and every credential lookup, through
`api_user_realm_auth('cloudflare_accounts.php')`, and throws `AccessDenied`
otherwise. The install smoke checks that a user with only Cloudflare: View
is denied the accounts page. The BDD features check that a user without the
configure right can neither change an account nor read its credential
details.

Save and the bulk actions (Delete, Verify, Enable, Disable) accept only POST;
any other method is redirected back to the list. POST bodies are checked by
Cacti's csrf-magic. Bulk actions read the selected IDs with
`sanitize_unserialize_selected_items()`. Numeric request values are read
with `get_filter_request_var()`; the text fields are validated by
`AccountService` before anything is stored. Every value printed into the
page goes through `html_escape()` or `__esc()`. The page's script is a static file,
`js/cloudflare_accounts.js`, loaded with Cacti's CSP nonce when Cacti
provides one; the plugin adds no inline script.

## Disabled plugin

Cacti keeps a disabled plugin's realms loaded and its `config_arrays` hook
active. The plugin therefore checks `api_plugin_is_enabled()` itself. While it
is disabled, the menu entry and breadcrumbs are hidden and
`cloudflare_accounts.php` answers as Cacti's permission-denied page. The
Settings > Cloudflare tab stays visible so the key path can be set before
the plugin is enabled. The install smoke checks the denial and the recovery
after re-enabling.

## Uninstall

Uninstall drops the plugin's tables, including stored ciphertexts, and
deletes the `cloudflare_` settings. It does not touch the key file or token
files, and it does not remove Cacti devices, graphs, data sources or RRD
files.

## Known limitations

- `#[\SensitiveParameter]` hides token arguments from stack traces only on
  PHP 8.2 and later. On PHP 7.4 through 8.1 it has no effect, so a stack
  trace printed by PHP itself could include a token argument.
- Tokens and keys are wiped with `sodium_memzero()` only when the sodium
  extension is loaded, and PHP may already have copied the string. Treat this
  as best effort.
- Outbound requests ignore HTTP proxy settings. A Cacti server that can reach
  the internet only through a proxy cannot reach the Cloudflare API.
- There is no key rotation tool. A new key makes existing vault tokens fail
  with "Stored token cannot be decrypted" until each token is entered again.
- File permission problems surface at Verify, not at save.
- `TokenFile` refuses a symbolic link at the final path component only; it
  does not check parent directories.
- Verify runs inside the web request. Each request it makes is bounded by the
  timeouts above and retried at most once, but one Verify can make up to four
  requests (account verify, user verify, tunnel probe, zone probe).
