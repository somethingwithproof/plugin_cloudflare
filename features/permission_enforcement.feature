Feature: Permission enforcement on account configuration
  Only users with Cloudflare configuration privileges can change accounts,
  and no user can read credential material back through the service.

  Scenario: User lacks configuration permission
    Given a Cloudflare account is already configured
    And I am authenticated as a user without Cloudflare configuration privileges
    When I attempt to configure a Cloudflare account
    Then the request must be rejected
    And no configuration should change

  Scenario: Unauthorized user attempts credential access
    Given a Cloudflare account is already configured
    And I am authenticated as a user without Cloudflare configuration privileges
    When I request account credential details
    Then no credential material must be exposed
    And the token must not appear in application logs
    And token material must not be reachable through the public service
