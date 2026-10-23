Feature: Tunnel discovery

  Scenario: Discover tunnels successfully
    Given a configured Cloudflare account
    And the Cloudflare API returns three tunnels
    When the discovery process executes
    Then Cacti should store exactly three discovered tunnels
    And each tunnel should retain its Cloudflare identifier

  Scenario: Repeated discovery does not create duplicates
    Given three tunnels have already been discovered
    When the discovery process executes again with unchanged results
    Then the tunnel count must remain three
    And existing resource associations must be preserved
