# Cloudflare plugin for Cacti

[![CI](https://github.com/somethingwithproof/plugin_cloudflare/actions/workflows/ci.yml/badge.svg)](https://github.com/somethingwithproof/plugin_cloudflare/actions/workflows/ci.yml)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=somethingwithproof_plugin_cloudflare&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=somethingwithproof_plugin_cloudflare)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)
![PHP >= 7.4](https://img.shields.io/badge/php-%3E%3D7.4-777bb4.svg)
![Cacti >= 1.2.25](https://img.shields.io/badge/cacti-%3E%3D1.2.25-2e7d32.svg)

A Cacti 1.2 plugin that is being built to monitor Cloudflare Tunnels and
zone analytics. It reads from the Cloudflare API with scoped API tokens,
never changes anything in Cloudflare, and keeps its data in its own tables.

**Status: Milestone 1.** This release provides Cloudflare account
configuration and a connection test only. It does not discover tunnels,
create graphs or collect analytics yet.

| Available now | Planned |
|---|---|
| Install, upgrade, disable and uninstall through Cacti's plugin manager | Tunnel and connector discovery (Milestone 2) |
| Cloudflare: View and Cloudflare: Configure permissions | Tunnel health data sources and graph templates (Milestone 3) |
| Add, edit, delete, enable and disable Cloudflare accounts | GraphQL analytics and Thold alerts (Milestone 4) |
| Store a token in a file or encrypted in the Cacti database | |
| Verify a token and check its tunnel and zone read permissions | |

## Screenshots

![The Cloudflare Accounts list with one account, "Example account", whose status is unreachable.](docs/images/accounts-list.png)

*The Cloudflare Accounts list. The screenshots come from a test stack with no
internet access, so Verify reports `unreachable`. The account ID is a
placeholder.*

![The account edit form with name, account ID, encrypted token storage, collection options and the Connection Status table.](docs/images/account-edit.png)

*Editing an account. The token fields are always empty; the stored token is
never sent back to the browser. Connection Status shows the result of the
last Verify.*

![The Cloudflare tab under Cacti Settings with the key file path, two timeouts and the log level.](docs/images/settings-cloudflare.png)

*Settings > Cloudflare.*

## Requirements

- Cacti 1.2.25 through 1.2.31. Cacti 1.2.31 itself needs PHP 8.0 or later.
- PHP 7.4 through 8.4 with the `curl`, `openssl` and `pdo_mysql` extensions.
  The plugin refuses to enable without them.
- MariaDB 10.6 or later, or MySQL 8.0 or later.
- HTTPS access from the Cacti server to `api.cloudflare.com`. Proxies are not
  supported.

See [docs/compatibility.md](docs/compatibility.md) for what is tested.

## Install

1. Put the plugin in Cacti's `plugins/cloudflare` directory, either by
   extracting a release archive (`make package` builds one) or with a git
   clone. The directory must be named `cloudflare`.
2. Install and enable it from Console > Configuration > Plugins, or with the
   CLI as the web server user:

   ```sh
   php cli/plugin_manage.php --plugin=cloudflare --install --enable
   ```

3. The admin user gets the Cloudflare: Configure permission at install. Grant
   it to other users or groups under Console > Configuration > Users.
4. Create a token with the permissions in
   [docs/token-permissions.md](docs/token-permissions.md). In short:
   Account > Cloudflare Tunnel > Read, and optionally Zone > Zone > Read.
5. Store the token in one of the two ways below, then add the account under
   Console > Configuration > Cloudflare Accounts and run Verify from the
   actions menu.

### Option A: encrypted in the database

The plugin encrypts tokens with a 32-byte key that you create. It never
creates or writes the key itself. The file must be readable by the web
server and poller users, must not be readable by others, and its directory
must let those users reach it. For a web server running as `www-data`:

```sh
sudo mkdir -p /etc/cacti
sudo sh -c 'umask 077 && openssl rand -hex 32 > /etc/cacti/cloudflare.key'
sudo chgrp www-data /etc/cacti/cloudflare.key
sudo chmod 0440 /etc/cacti/cloudflare.key
```

The default path is `/etc/cacti/cloudflare.key`; change it under Settings >
Cloudflare > Token Vault Key File. When the key is missing or refused, the
edit form offers only the token file option. Keep a backup of the key:
without it, stored tokens cannot be decrypted and must be entered again.

### Option B: token file

Write the token, and nothing else, to a file the web server and poller users
can read and others cannot, then enter its absolute path on the edit form:

```sh
sudo sh -c 'umask 077 && cat > /etc/cacti/cloudflare-account.token'   # paste the token, then Ctrl-D
sudo chgrp www-data /etc/cacti/cloudflare-account.token
sudo chmod 0440 /etc/cacti/cloudflare-account.token
```

The file is checked when it is read, at Verify, not when you save. Symbolic
links, files over 4 KiB and files readable or writable by others are
refused.

## Account status

Verify sets the account's status:

| Status | Meaning |
|---|---|
| `unknown` | Not verified since the account was added or its credentials changed |
| `ok` | The token is active and can read tunnels |
| `token_inactive` | Cloudflare reports the token as disabled or expired |
| `auth_failed` | Cloudflare rejected the token |
| `permission_denied` | The token is active but cannot read tunnels |
| `unreachable` | The API could not be reached, timed out or returned a server error |
| `rate_limited` | Cloudflare returned HTTP 429 |
| `error` | Any other failure, including a token file that is missing or insecure, or a stored token that cannot be decrypted |

The edit page also shows whether tunnel read and zone read were granted.

## Settings

Settings > Cloudflare holds:

- **Token Vault Key File**: path to the vault key.
- **Interactive Request Timeout**: total seconds for a request made from the
  web interface, such as Verify. Default 10; values outside 5 to 60 fall
  back to 10.
- **Collector Request Timeout** and **Log Level**: stored for the collector,
  which arrives in Milestone 2. Nothing reads them yet.

The account form's Collect Tunnels, Collect Analytics and Discovery Interval
fields are stored for the same reason and have no effect in this release.

## Uninstall and data retention

Uninstall drops the plugin's own tables, which hold accounts, encrypted
tokens and job state, and deletes its `cloudflare_` settings. It does not
remove the key file, token files, or any Cacti devices, graphs, data sources,
RRD files or templates. Those hold historical monitoring data, and removing
them is an explicit administrator action in Cacti.

## Development

The tools need PHP 8.x and Composer; the plugin code itself stays PHP 7.4
compatible. Integration and BDD tests need a database, which
`make db-up` starts in Docker on port 33061.

```sh
composer install
make db-up
make ci            # unit, integration, BDD, PHPStan, php-cs-fixer, php -l on PHP 7.4
make smoke         # installs the plugin into Cacti 1.2.31 in Docker and walks its lifecycle
make package       # builds build/cloudflare-<version>.tar.gz and checks its contents
make db-down
```

`make smoke` and `make screenshots` need a Cacti checkout; set `CACTI_REPO`
to its path. The integration tests read `CF_TEST_DSN`, `CF_TEST_DB_USER`
and `CF_TEST_DB_PASS`, and skip when the database is unreachable unless `CI`
is set.

## Documentation

- [docs/security.md](docs/security.md): security controls and known limitations
- [docs/token-permissions.md](docs/token-permissions.md): token permissions
- [docs/compatibility.md](docs/compatibility.md): supported versions and the interfaces used
- [docs/schema.md](docs/schema.md): database tables
- [docs/design.md](docs/design.md): design decisions
- [CHANGELOG.md](CHANGELOG.md)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
