Feature: Cloudflare outage

  Scenario: Cloudflare becomes unavailable
    Given Cacti is monitoring existing devices
    And the Cloudflare API becomes unavailable
    When the polling cycle executes
    Then unrelated Cacti polling must continue
    And Cloudflare observations must be classified as unavailable
    And existing graph history must remain intact
