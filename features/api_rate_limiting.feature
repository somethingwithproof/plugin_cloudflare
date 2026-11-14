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

Feature: API rate limiting

  Scenario: Cloudflare returns HTTP 429
    Given the API request limit has been reached
    When the Cloudflare collector receives HTTP 429
    Then it must apply bounded retry handling
    And honor supported retry information
    And defer collection when the retry budget is exhausted
    And it must not block the Cacti user interface
