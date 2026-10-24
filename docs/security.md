# Security

This page describes the security controls as implemented through Milestone 3,
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
The list shows only whether a token is stored. The edit page reads the
source and file path from `AccountService::credentialDetails()`, which
returns those and whether a token exists, never the token or ciphertext, and
requires the configure right. A form that fails
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
shows a fixed error: Cacti's generic save error on save and the bulk actions,
and "The Cloudflare accounts could not be read" on the list, edit and
confirmation pages. No read path lets the exception escape, because Cacti's
shutdown handler disables a plugin whose page dies with one.

## Access control

| Realm | Page | Default |
|---|---|---|
| Cloudflare: Configure | `cloudflare_accounts.php` | Granted to admin at install |
| Cloudflare: View | `cloudflare.php` | Not granted at install |
| Sites/Devices/Data (Cacti's realm for `host.php`) | Create Cacti Device, and Create Graphs on | Cacti's own grant |

`include/auth.php` enforces the realm by page name. `AccountService` checks
again before every change, every Verify and every credential lookup, through
`api_user_realm_auth('cloudflare_accounts.php')`, and throws `AccessDenied`
otherwise. The install smoke checks that a user with only Cloudflare: View
is denied the accounts page. The BDD features check that a user without the
configure right can neither change an account nor call `credentialDetails()`.
The viewer pages (`cloudflare.php`) only read. They need the Cloudflare: View
realm, which Cacti enforces from the page name, and they accept no POST
actions. Tunnel and connector text comes from Cloudflare, so every value is
printed through `html_escape()`. The Tunnels filter and sort values are
checked against fixed lists before they reach a query.

Device and graph actions need a second realm. A Cacti device is a `host.php`
object, so the Configure realm alone must not create one. Create Cacti Device
and turning Create Graphs on both require `cloudflare_accounts.php` and
`api_user_realm_auth('host.php')` (`includes/cacti_objects.php:34`). The
dropdown hides the action from a user without both
(`includes/accounts_page.php:55-62`), the handler refuses the request again
before any Cacti call (`:234-243`), and `cloudflare_create_device()` checks a
third time (`includes/cacti_objects.php:47`). When a user without the
`host.php` realm saves the account form with Create Graphs on, the field is
dropped and the stored value stays; a warning is shown when the setting was
off (`includes/accounts_page.php:193-209`). Turning it off needs only Configure.
The collector creates graphs without a logged-in user, so the realm check
happens when the setting is saved.

`list()` stays unguarded because the `Account` read model it returns holds no
token, ciphertext, token source or file path; an integration test checks
that a dump of the list contains none of them.

Save and the bulk actions (Delete, Verify, Enable, Disable) accept only POST;
any other method is redirected back to the list. POST bodies are checked by
Cacti's csrf-magic. Bulk actions read the selected IDs with
`sanitize_unserialize_selected_items()`. Numeric request values are read
with `get_filter_request_var()`; the text fields are validated by
`AccountService` before anything is stored. Every value printed into the
page goes through `html_escape()` or `__esc()`. The page's script is a static file,
`js/cloudflare_accounts.js`, loaded with Cacti's CSP nonce when Cacti
provides one; the plugin adds no inline script.

## Collector

Tunnel discovery runs in a separate PHP process, `poller_cloudflare.php`.

- **CLI only.** The script answers 404 and exits when `php_sapi_name()` is not
  `cli`, so it cannot be requested over HTTP.
- **Launch.** `cloudflare_poller_bottom()` starts it through
  `exec_background()` from the `poller_bottom` hook, on the main poller only.
  The PHP binary is Cacti's `path_php_binary` setting and the script path is
  escaped; no request data reaches the command line.
- **One process at a time.** The collector takes
  `GET_LOCK('plugin_cloudflare_collector', 0)`. With a timeout of 0, a second
  collector exits at once, and MySQL releases the lock when the connection
  ends. Each job row also holds a lease, and every write for an account
  shares a transaction with a check of that lease.
- **Bounded run.** The collector starts no new account after 50 seconds and
  makes at most 200 connection requests per run. Either limit can also stop
  an account part way through its connection reads, recorded as `partial`. Each request has a 5 second
  connect timeout and the Collector Request Timeout (default 20 seconds), so
  a slow Cloudflare API ends the run at its next check and never holds up the
  poller cycle.
- **Disable check.** Before each account it reads `plugin_config.status`
  directly. `api_plugin_is_enabled()` caches its answer for the process, so it
  would miss a plugin disabled during the run. A failed read counts as
  disabled.
- **Credentials.** Tokens are resolved per account from the same file or vault
  as Verify, with the same checks, and wiped after use when sodium is loaded.
  A token that cannot be read is logged as a reason code only.
- **Logging.** A fault outside the Cloudflare client is logged as the
  exception class name and nothing else, because a message or trace can quote
  Cloudflare text or a token. Run lines carry the numeric account row ID, an
  error class and counts, at Cacti's medium verbosity.

## Cacti devices, graphs and templates

- **Hostname.** The account's device gets the hostname
  `<cf_account_id>.cloudflare.invalid` (`includes/cacti_objects.php:107`).
  `.invalid` is reserved and never resolves, SNMP version is 0 and the
  availability method is none, so Cacti never connects anywhere for this
  device. The device carries no token or credential.
- **One fixed write outside the plugin directory.** The plugin copies its
  shipped `templates/resource/cloudflare_tunnels.xml` to
  `<base_path>/resource/script_server/cloudflare_tunnels.xml`, because Cacti
  1.2.31 reads a data query XML only from `resource/`
  (`includes/templates.php:46-52`). Neither the source nor the target comes
  from input. The function writes a temporary file beside the target with
  `fopen()` mode `xb`, which fails on an existing name, so a link planted at
  the temporary name is never followed. It sets mode 0644 and renames the file
  over the target (`includes/templates.php:140-178`). A symbolic link at the
  target is replaced, because `rename()` swaps the link itself and does not
  write through it. The copy is skipped only when the target is a regular
  file with identical contents (`:151`). A failure is logged and returns
  false; it never throws, because Cacti disables a plugin whose install
  throws. Nothing else is written outside the plugin directory, and uninstall
  keeps this file.
- **Half-created device.** If `api_device_save()` succeeds but the plugin
  cannot record the device, it removes that host with `api_device_remove()`
  and reads the host table back. The host id is the one that same request just
  received, never a value from the request or the database. If the host is
  still there, the log says so by id and the administrator removes it in
  Devices (`includes/cacti_objects.php:146-158`, `:172-188`).
- **Locks.** `GET_LOCK('plugin_cloudflare_device_<account id>')` makes a
  second Create Cacti Device for the same account wait and then find the
  device, so one account never gets two. The lock is released in a `finally`
  block, and a failed release is logged without replacing the result
  (`includes/cacti_objects.php:65-82`). `GET_LOCK('plugin_cloudflare_templates')`
  keeps two upgrading processes from importing the templates at once
  (`includes/templates.php:79-94`). MySQL drops both when the connection ends.
- **Template import.** `import_xml_data()` reports failure through return
  values and `$import_messages`, not through exceptions, so the plugin checks
  both and then that every object resolves by hash
  (`includes/templates.php:188-251`). An import that fails is logged by stage
  name only.
- **Script server.** `ss_cloudflare.php` reads the plugin's tables and makes
  no network call. It accepts a device id only as `^[1-9][0-9]{0,9}\z` and a
  tunnel index only as a lowercase UUID, binds both in prepared statements,
  and logs a failure as the exception class name only
  (`includes/script_server.php:38-84`).

## Disabled plugin

Cacti keeps a disabled plugin's realms loaded and its `config_arrays` hook
active. The plugin therefore checks `api_plugin_is_enabled()` itself. While it
is disabled, the menu entries and breadcrumbs are hidden, and
`cloudflare_accounts.php` and `cloudflare.php` answer as Cacti's
permission-denied page. The collector checks `plugin_config.status` itself
and exits without work. The
Settings > Cloudflare tab stays visible so the key path can be set before
the plugin is enabled. The install smoke checks the denial and the recovery
after re-enabling.

## Test stack

The install smoke runs Cacti against a fake Cloudflare API. The fake answers
as `api.cloudflare.com` inside the stack's Docker network, with a certificate
signed by a CA that it mints when its container starts. That CA is valid for
two days and limited by a name constraint to `api.cloudflare.com`. Only the
Cacti web container installs it, in its system certificate store. The smoke
checks that the gateway container does not trust it and that any other name is
refused.

The plugin has no CA setting and no origin override. `CurlTransport` sets no
CA option, so production uses the operating system's store, and
`OutboundPolicy::production()` allows `https://api.cloudflare.com` and
nothing else. The test CA exists only in the smoke stack.

## Uninstall

Uninstall drops the plugin's tables, including stored ciphertexts, and
deletes the `cloudflare_` settings. It does not touch the key file or token
files, and it does not remove Cacti devices, graphs, data sources, RRD files,
templates or `resource/script_server/cloudflare_tunnels.xml`.

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
- Cacti disables a plugin whose code ends in a true fatal error, such as
  running out of memory, exceeding `max_execution_time` or a compile error.
  No `catch` can intercept these, and the plugin cannot prevent them; an
  administrator has to enable it again. The pages catch `Throwable` and log the
  class name, so an ordinary exception such as a missing table shows a fixed
  message and does not reach Cacti's shutdown handler.
- Three consecutive outage-class failures mark an account's active tunnels
  `unavailable`, including when the cause is a long rate limit. The next
  complete listing restores them.
- The plugin overwrites administrator edits to the imported templates when an
  upgrade changes the shipped template XML, and the device must stay on the
  main poller. See the README's known limitations.
- Verify runs inside the web request. One Verify can make up to four
  requests (account verify, user verify, tunnel probe, zone probe), each
  bounded by the timeouts above and retried at most once.
