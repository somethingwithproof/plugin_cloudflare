Feature: API rate limiting

  Scenario: Cloudflare returns HTTP 429
    Given the API request limit has been reached
    When the Cloudflare collector receives HTTP 429
    Then it must apply bounded retry handling
    And honor supported retry information
    And defer collection when the retry budget is exhausted
    And it must not block the Cacti user interface
