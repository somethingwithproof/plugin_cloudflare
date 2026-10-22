Feature: Permission enforcement

  Scenario: Unauthorized user attempts credential access
    Given I have permission to view Cloudflare graphs
    And I do not have permission to administer Cloudflare accounts
    When I request account credential details
    Then the request must be denied
    And no credential material must be returned
