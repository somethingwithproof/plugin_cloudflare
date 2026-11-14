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

Feature: Metrics integrity

  Scenario: An API request fails
    Given a tunnel previously reported four connections
    When the next metrics request fails
    Then the observation must be marked unknown
    And the system must not record zero connections
    And the previous historical samples must remain unchanged
