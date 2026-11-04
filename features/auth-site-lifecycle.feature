Feature: HTTP auth across the site lifecycle
  What happens to a site's auth users, whitelist and files when the site is deleted, recreated,
  disabled and enabled, and whether auth also protects HTTPS.

  Background:
    Given global http auth is not enabled

  Scenario: Deleting a site removes its auth rows and htpasswd file
    Given the html site "bh-auth-command-5.test" exists
    And the site "bh-auth-command-5.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-5.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-5.test --ip=192.0.2.1"
    When I run "bin/ee site delete bh-auth-command-5.test --yes"
    Then the command should succeed
    And the site "bh-auth-command-5.test" should not exist
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-5.test |
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-5.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-5.test" should not exist

  Scenario: Deleting a site removes its whitelist file
    # Regression check (fixed by EasyEngine/auth-command#57): the site-delete cleanup hook wasn't autoloaded, so vhost.d/<site>_acl was left behind.
    Given the html site "bh-auth-command-5.test" exists
    And the site "bh-auth-command-5.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-5.test --ip=192.0.2.1"
    When I run "bin/ee site delete bh-auth-command-5.test --yes"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-5.test_acl" should not exist

  Scenario: A site recreated with the same name starts without auth
    Given the site "bh-auth-command-5.test" does not exist
    And the html site "bh-auth-command-5.test" exists
    And I run "bin/ee auth create bh-auth-command-5.test --user=bh-u1 --pass=bh-p1"
    And the command should succeed
    And I run "bin/ee site delete bh-auth-command-5.test --yes"
    And the command should succeed
    When I run "bin/ee site create bh-auth-command-5.test --type=html"
    Then the command should succeed
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-5.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-5.test" should not exist
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-5.test" should return 200
    When I run "bin/ee auth list bh-auth-command-5.test --format=json"
    Then the JSON output should not contain items:
      | site_url               |
      | bh-auth-command-5.test |

  Scenario: Auth survives disabling and enabling the site
    Given the html site "bh-auth-command-6.test" exists
    And the site "bh-auth-command-6.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-6.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee site disable bh-auth-command-6.test"
    Then the command should succeed
    And the auth_users table should contain a row with:
      | site_url | bh-auth-command-6.test |
      | username | bh-u1                  |
    And the htpasswd file "bh-auth-command-6.test" should accept user "bh-u1" with password "bh-p1"
    When I run "bin/ee site enable bh-auth-command-6.test"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-6.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-6.test" as user "bh-u1" with password "bh-p1" should return 200

  Scenario: The whitelist survives disabling and enabling the site
    Given the html site "bh-auth-command-6.test" exists
    And the site "bh-auth-command-6.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-6.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-6.test --ip={PUBLIC_IP}"
    When I run "bin/ee site disable bh-auth-command-6.test"
    And I run "bin/ee site enable bh-auth-command-6.test"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-6.test_acl" should contain:
      """
      allow {PUBLIC_IP};
      """
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-6.test" should return 200

  Scenario: Auth also protects the HTTPS vhost
    Given the html site "bh-auth-command-7.test" exists with flags "--ssl=self"
    And the site "bh-auth-command-7.test" has no http auth or whitelist
    And a GET request to "https://bh-auth-command-7.test/" via "{PUBLIC_IP}" should return 200
    When I run "bin/ee auth create bh-auth-command-7.test --user=bh-u1 --pass=bh-p1"
    Then the command should succeed
    And a GET request to "https://bh-auth-command-7.test/" via "{PUBLIC_IP}" should return 401
    And a GET request to "https://bh-auth-command-7.test/" via "{PUBLIC_IP}" as user "bh-u1" with password "bh-p1" should return 200
    When I run "bin/ee auth update bh-auth-command-7.test --ip={PUBLIC_IP}"
    Then a GET request to "https://bh-auth-command-7.test/" via "{PUBLIC_IP}" should return 200
    When I run "bin/ee auth delete bh-auth-command-7.test"
    And I run "bin/ee auth delete bh-auth-command-7.test --ip"
    Then a GET request to "https://bh-auth-command-7.test/" via "{PUBLIC_IP}" should return 200
