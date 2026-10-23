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
