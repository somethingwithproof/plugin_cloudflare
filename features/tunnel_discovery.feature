# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_cloudflare, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_cloudflare
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.

Feature: Tunnel discovery

  Scenario: Discover tunnels successfully
    Given a configured Cloudflare account
    And the Cloudflare API returns three tunnels
    When the discovery process executes
    Then Cacti should store exactly three discovered tunnels
    And each tunnel should retain its Cloudflare identifier

  Scenario: Repeated discovery does not create duplicates
    Given three tunnels have already been discovered
    When the discovery process executes again with unchanged results
    Then the tunnel count must remain three
    And existing resource associations must be preserved
