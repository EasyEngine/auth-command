Feature: Site-scoped IP whitelists
  `ee auth create|update|delete|list <site> --ip`: rows in auth_ips, the per-site
  `vhost.d/<site>_acl` file (global IPs first, then the site's own), and how the whitelist
  interacts with HTTP auth. The whitelist is only an auth bypass (`satisfy any`), not an allow-list.
  Test IPs come from the documentation ranges (192.0.2.0/24, 198.51.100.0/24, 2001:db8::/32).

  Background:
    Given global http auth is not enabled
    And the html site "bh-auth-command-3.test" exists
    And the site "bh-auth-command-3.test" has no http auth or whitelist

  Scenario: Create a whitelist with one IP
    When I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    Then the command should succeed
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the auth_ips table should have 1 rows with:
      | site_url | bh-auth-command-3.test |
    And the auth_ips table should contain a row with:
      | site_url | bh-auth-command-3.test |
      | ip       | 192.0.2.1              |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain:
      """
      satisfy any;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain:
      """
      allow 192.0.2.1;
      deny all;
      """

  Scenario: The site _acl file also carries the global whitelist
    Given the frontend subnet is globally whitelisted
    When I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain the line "allow {FRONTEND_SUBNET_IP};" exactly 1 times
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain the line "allow 192.0.2.1;" exactly 1 times

  Scenario: Create accepts comma and space separated lists, CIDR ranges and IPv6
    When I run "bin/ee auth create bh-auth-command-3.test --ip='192.0.2.1,192.0.2.2 198.51.100.0/24, 2001:db8::1'"
    Then the command should succeed
    And the auth_ips table should have 4 rows with:
      | site_url | bh-auth-command-3.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain:
      """
      allow 192.0.2.1;
      allow 192.0.2.2;
      allow 198.51.100.0/24;
      allow 2001:db8::1;
      deny all;
      """
    When I run "bin/ee auth list bh-auth-command-3.test --ip --format=json"
    Then the command should succeed
    And the JSON output should have 4 items
    And the JSON output should contain items:
      | ip              |
      | 192.0.2.1       |
      | 192.0.2.2       |
      | 198.51.100.0/24 |
      | 2001:db8::1     |

  Scenario: Create fails when the site already has a whitelist
    # A known usability issue; the current behaviour is an error pointing to update.
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    When I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.2"
    Then the command should fail
    And STDERR should contain:
      """
      Whitelist is already created on bh-auth-command-3.test. To update IPs use `ee auth update` instead
      """
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-3.test |
      | ip       | 192.0.2.2              |

  Scenario Outline: Invalid IP lists are rejected and nothing is written
    When I run "bin/ee auth <subcommand> bh-auth-command-3.test --ip='<ips>'"
    Then the command should fail
    And STDERR should contain:
      """
      Please check your list do not have any empty or wrong IP addresses.
      """
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-3.test |

    Examples:
      | subcommand | ips                   |
      | create     | 999.1.1.1             |
      | create     | not-an-ip             |
      | create     | 192.0.2.1,            |
      | create     | 192.0.2.1,bogus       |
      | create     | 192.0.2.1/            |
      | create     | 192.0.2.1/24/8        |
      | update     | 192.0.2.300           |
      | update     | ,192.0.2.1            |
      | delete     | 999.1.1.1             |

  Scenario: Consecutive separators are collapsed
    When I run "bin/ee auth create bh-auth-command-3.test --ip='192.0.2.1,,  192.0.2.2'"
    Then the command should succeed
    And the auth_ips table should have 2 rows with:
      | site_url | bh-auth-command-3.test |

  Scenario Outline: An invalid CIDR prefix is rejected instead of breaking nginx
    # Fixed by EasyEngine/auth-command#59: only the address part before "/" used to be validated, so the prefix was written verbatim
    # into the _acl file, `nginx -t` failed and every later proxy reload (for all sites) was skipped until the
    # entry was removed. The site user makes the proxy include the _acl file; the context removes it (and
    # reloads) after the scenario.
    Given I run "bin/ee auth create bh-auth-command-3.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth create bh-auth-command-3.test --ip=<ip>"
    Then the command should fail
    And STDERR should contain:
      """
      Invalid entry: '<ip>'.
      """
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-3.test |

    Examples:
      | ip                   |
      | 192.0.2.1/abc        |
      | 192.0.2.1/99         |
      | 2001:db8::/200       |
      | /99                  |
      | 192.0.2.1/024        |
      | 2001:db8:1:2:3:4:5:: |

  Scenario: An invalid entry stored by an older version is skipped, and can still be deleted
    # Safety net for EasyEngine/auth-command#59: rows stored before the fix must not end up in the _acl file nginx loads.
    Given I run "bin/ee auth create bh-auth-command-3.test --user=bh-u1 --pass=bh-p1"
    And the whitelist entry "192.0.2.1/99" of "bh-auth-command-3.test" was stored unchecked by an older auth-command
    When I run "bin/ee auth update bh-auth-command-3.test --ip=192.0.2.5"
    Then the command should succeed
    And STDERR should contain:
      """
      Skipping the invalid whitelist entry '192.0.2.1/99' of bh-auth-command-3.test: nginx would reject it.
      """
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain the line "allow 192.0.2.5;" exactly 1 times
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should not contain:
      """
      192.0.2.1/99
      """
    When I run "bin/ee auth delete bh-auth-command-3.test --ip=192.0.2.1/99"
    Then the command should succeed
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-3.test |
      | ip       | 192.0.2.1/99           |
    And the auth_ips table should contain a row with:
      | site_url | bh-auth-command-3.test |
      | ip       | 192.0.2.5              |

  Scenario: A duplicated IP in one create list is stored once
    # Fixed by EasyEngine/auth-command#59: create_whitelist() used to insert every entry, so the UNIQUE(site_url, ip) constraint threw an
    # uncaught PDOException after the first row was written, and the _acl file was never generated.
    When I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1,192.0.2.1"
    Then the command should succeed
    And STDERR should not contain:
      """
      PDOException
      """
    And the auth_ips table should have 1 rows with:
      | site_url | bh-auth-command-3.test |
      | ip       | 192.0.2.1              |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain the line "allow 192.0.2.1;" exactly 1 times

  Scenario: Update adds new IPs and skips the ones already whitelisted
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    When I run "bin/ee auth update bh-auth-command-3.test --ip=192.0.2.1,192.0.2.2"
    Then the command should succeed
    And STDOUT should contain:
      """
      192.0.2.1 has already been whitelisted on bh-auth-command-3.test. Skipping it.
      """
    And the auth_ips table should have 2 rows with:
      | site_url | bh-auth-command-3.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain the line "allow 192.0.2.1;" exactly 1 times
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain the line "allow 192.0.2.2;" exactly 1 times

  Scenario: Update is idempotent
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    When I run "bin/ee auth update bh-auth-command-3.test --ip=192.0.2.1"
    And I run "bin/ee auth update bh-auth-command-3.test --ip=192.0.2.1"
    Then the command should succeed
    And the auth_ips table should have 1 rows with:
      | site_url | bh-auth-command-3.test |

  Scenario: Update on a site without a whitelist creates it
    When I run "bin/ee auth update bh-auth-command-3.test --ip=192.0.2.5"
    Then the command should succeed
    And the auth_ips table should contain a row with:
      | site_url | bh-auth-command-3.test |
      | ip       | 192.0.2.5              |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain:
      """
      allow 192.0.2.5;
      """

  Scenario: Delete removes only the given IPs
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1,192.0.2.2,192.0.2.3"
    When I run "bin/ee auth delete bh-auth-command-3.test --ip=192.0.2.1,192.0.2.3"
    Then the command should succeed
    And the auth_ips table should have 1 rows with:
      | site_url | bh-auth-command-3.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should contain:
      """
      allow 192.0.2.2;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should not contain:
      """
      allow 192.0.2.1;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should not contain:
      """
      allow 192.0.2.3;
      """

  Scenario: Deleting an IP that is not whitelisted is skipped
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    When I run "bin/ee auth delete bh-auth-command-3.test --ip=192.0.2.99"
    Then the command should succeed
    And STDOUT should contain:
      """
      192.0.2.99 has not been whitelisted on bh-auth-command-3.test. Skipping it.
      """
    And the auth_ips table should have 1 rows with:
      | site_url | bh-auth-command-3.test |

  Scenario: Delete with a bare --ip removes the whole site whitelist
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1,192.0.2.2"
    When I run "bin/ee auth delete bh-auth-command-3.test --ip"
    Then the command should succeed
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-3.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should not contain:
      """
      allow 192.0.2.1;
      """

  Scenario: Removing the whole site whitelist removes the _acl file
    # Since #57 (5f12c49) a site without own IPs has no _acl file, so the global default ACL applies.
    Given the frontend subnet is globally whitelisted
    And I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    When I run "bin/ee auth delete bh-auth-command-3.test --ip"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-3.test_acl" should not exist

  Scenario: Deleting the whitelist of a site with no whitelist is a no-op
    When I run "bin/ee auth delete bh-auth-command-3.test --ip"
    Then the command should succeed
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-3.test |

  Scenario: Delete --ip leaves the auth users alone
    Given I run "bin/ee auth create bh-auth-command-3.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    When I run "bin/ee auth delete bh-auth-command-3.test --ip"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-3.test" should accept user "bh-u1" with password "bh-p1"

  Scenario: List --ip shows only the site's own IPs in every format
    Given I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1,192.0.2.2"
    When I run "bin/ee auth list bh-auth-command-3.test --ip --format=json"
    Then the command should succeed
    And the JSON output should have 2 items
    And the JSON output should not contain items:
      | ip                   |
      | {FRONTEND_SUBNET_IP} |
    When I run "bin/ee auth list bh-auth-command-3.test --ip --format=count"
    Then STDOUT should be exactly:
      """
      2
      """
    When I run "bin/ee auth list bh-auth-command-3.test --ip --format=csv"
    Then STDOUT should contain:
      """
      ip
      192.0.2.1
      192.0.2.2
      """
    When I run "bin/ee auth list bh-auth-command-3.test --ip"
    Then STDOUT should match:
      """
      /^ip\n192\.0\.2\.1\n192\.0\.2\.2$/m
      """

  Scenario: List --ip on a site without a whitelist is empty
    When I run "bin/ee auth list bh-auth-command-3.test --ip --format=json"
    Then the command should succeed
    And the JSON output should have 0 items

  Scenario: A whitelisted IP bypasses site auth and removing it restores the prompt
    Given the html site "bh-auth-command-4.test" exists
    And the site "bh-auth-command-4.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-3.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-4.test --user=bh-u1 --pass=bh-p1"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-3.test" should return 401
    When I run "bin/ee auth update bh-auth-command-3.test --ip={PUBLIC_IP}"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-3.test" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-4.test" should return 401
    When I run "bin/ee auth delete bh-auth-command-3.test --ip={PUBLIC_IP}"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-3.test" should return 401

  Scenario: A whitelisted CIDR range containing the client bypasses auth
    Given I run "bin/ee auth create bh-auth-command-3.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth create bh-auth-command-3.test --ip={PUBLIC_IP}/32"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-3.test" should return 200

  Scenario: A site with its own whitelist still lets the frontend subnet through
    Given the frontend subnet is globally whitelisted
    And I run "bin/ee auth create bh-auth-command-3.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    Then the command should succeed
    And a GET request to "http://127.0.0.1/" with Host "bh-auth-command-3.test" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-3.test" should return 401

  Scenario: A whitelist without auth does not restrict access
    # The _acl file is only included inside an auth block, so on its own it is no allow-list.
    When I run "bin/ee auth create bh-auth-command-3.test --ip=192.0.2.1"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-3.test" should return 200
