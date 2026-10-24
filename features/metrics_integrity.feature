Feature: Metrics integrity

  Scenario: An API request fails
    Given a tunnel previously reported four connections
    When the next metrics request fails
    Then the observation must be marked unknown
    And the system must not record zero connections
    And the previous historical samples must remain unchanged
