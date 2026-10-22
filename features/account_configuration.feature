Feature: Cloudflare account configuration
  An administrator stores a Cloudflare API token for an account and checks
  that it works, without the token ever leaving the credential vault.

  Scenario: Administrator adds a valid Cloudflare account
    Given I am authenticated as a Cacti administrator
    When I configure a Cloudflare account
    And I verify the connection
    Then the account status should be "ok"
    And the token must be stored encrypted
    And the token must not appear in application logs

  Scenario: Invalid token is reported as authentication failure
    Given I am authenticated as a Cacti administrator
    When I configure a Cloudflare account
    And Cloudflare rejects the token during verification
    Then the account status should be "auth_failed"
    And the token must not appear in application logs
