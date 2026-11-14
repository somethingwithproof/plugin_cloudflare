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

Feature: Account configuration

  Scenario: Administrator adds a valid Cloudflare account
    Given I am authenticated as a Cacti administrator
    And I have a valid scoped Cloudflare API token
    When I configure a Cloudflare account
    And I verify the connection
    Then the account should be saved
    And the connection status should indicate success
    And the token must not appear in application logs
    And the token must be stored encrypted

  Scenario: Invalid token is reported as authentication failure
    Given I am authenticated as a Cacti administrator
    And I have an invalid Cloudflare API token
    When I configure a Cloudflare account
    And I verify the connection
    Then the account should be saved
    And the connection status should indicate authentication failure
    And the token must not appear in application logs

  Scenario: User lacks configuration permission
    Given I am authenticated without Cloudflare configuration privileges
    When I attempt to configure an account
    Then the request must be rejected
    And no configuration should change
