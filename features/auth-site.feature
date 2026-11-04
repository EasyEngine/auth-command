Feature: Site-scoped HTTP auth users
  `ee auth create|update|delete|list <site>` without --ip: DB rows in auth_users, the per-site
  htpasswd file in the global nginx-proxy, and the effect on HTTP requests. Requests go to the
  host's public IP with a Host header, because 127.0.0.1 arrives as the whitelisted frontend subnet.

  Background:
    Given global http auth is not enabled
    And the html site "bh-auth-command-1.test" exists
    And the site "bh-auth-command-1.test" has no http auth or whitelist

  Scenario: A site without auth is served to everyone
    Then a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" should return 200
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-1.test" should not exist

  Scenario: Create auth with an explicit user and password
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1-secret"
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: Auth successfully updated for `bh-auth-command-1.test` scope. New values added:
      User: bh-u1
      Pass: bh-p1-secret
      """
    And STDOUT should contain:
      """
      Reloading global reverse proxy.
      """
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the auth_users table should contain a row with:
      | site_url | bh-auth-command-1.test |
      | username | bh-u1                  |
      | password | bh-p1-secret           |
    And the htpasswd file "bh-auth-command-1.test" should contain user "bh-u1"
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-p1-secret"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u1" with password "bh-p1-secret" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u1" with password "wrong" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "nobody" with password "bh-p1-secret" should return 401

  Scenario: Requests from the whitelisted frontend subnet bypass site auth
    Given the frontend subnet is globally whitelisted
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1-secret"
    Then the command should succeed
    And a GET request to "http://127.0.0.1/" with Host "bh-auth-command-1.test" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" should return 401

  Scenario: Create auth without flags generates an ee-XXXXXX user and an 18 character password
    # The help text says the default user is `easyengine`; the code generates `ee-` plus 6 random characters.
    When I run "bin/ee auth create bh-auth-command-1.test"
    Then the command should succeed
    And STDOUT should match:
      """
      /^User: ee-[A-Za-z0-9]{6}$/m
      """
    And STDOUT should match:
      """
      /^Pass: [A-Za-z0-9]{18}$/m
      """
    When I save the "User" value from STDOUT as "GEN_USER"
    And I save the "Pass" value from STDOUT as "GEN_PASS"
    Then the auth_users table should contain a row with:
      | site_url | bh-auth-command-1.test |
      | username | {GEN_USER}             |
      | password | {GEN_PASS}             |
    And the htpasswd file "bh-auth-command-1.test" should accept user "{GEN_USER}" with password "{GEN_PASS}"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "{GEN_USER}" with password "{GEN_PASS}" should return 200

  Scenario: Create auth with only --pass generates the user name
    When I run "bin/ee auth create bh-auth-command-1.test --pass=bh-only-pass"
    Then the command should succeed
    And STDOUT should match:
      """
      /^User: ee-[A-Za-z0-9]{6}$/m
      """
    And STDOUT should contain:
      """
      Pass: bh-only-pass
      """
    When I save the "User" value from STDOUT as "GEN_USER"
    Then the htpasswd file "bh-auth-command-1.test" should accept user "{GEN_USER}" with password "bh-only-pass"

  Scenario: Create auth with only --user generates the password
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-only-user"
    Then the command should succeed
    And STDOUT should contain:
      """
      User: bh-only-user
      """
    And STDOUT should match:
      """
      /^Pass: [A-Za-z0-9]{18}$/m
      """
    When I save the "Pass" value from STDOUT as "GEN_PASS"
    Then the htpasswd file "bh-auth-command-1.test" should accept user "bh-only-user" with password "{GEN_PASS}"

  Scenario: Several users can be added to one site and all of them work
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u2 --pass=bh-p2"
    Then the command should succeed
    And the auth_users table should have 2 rows with:
      | site_url | bh-auth-command-1.test |
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-p1"
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u2" with password "bh-p2"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u1" with password "bh-p1" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u2" with password "bh-p2" should return 200

  Scenario: Creating the same user twice fails and leaves the first one untouched
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-other"
    Then the command should fail
    And STDERR should contain:
      """
      Auth for user bh-u1 already exists for this site. To update it, use `ee auth update`
      """
    And the auth_users table should have 1 rows with:
      | site_url | bh-auth-command-1.test |
      | username | bh-u1                  |
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-p1"
    And the htpasswd file "bh-auth-command-1.test" should reject user "bh-u1" with password "bh-other"

  Scenario: The same user name can exist on two different sites with different passwords
    Given the html site "bh-auth-command-2.test" exists
    And the site "bh-auth-command-2.test" has no http auth or whitelist
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-shared --pass=bh-pass-one"
    And I run "bin/ee auth create bh-auth-command-2.test --user=bh-shared --pass=bh-pass-two"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-shared" with password "bh-pass-one"
    And the htpasswd file "bh-auth-command-2.test" should accept user "bh-shared" with password "bh-pass-two"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-shared" with password "bh-pass-two" should return 401

  Scenario: Auth on one site does not protect another site
    Given the html site "bh-auth-command-2.test" exists
    And the site "bh-auth-command-2.test" has no http auth or whitelist
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-2.test" should return 200
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-2.test" should not exist

  Scenario: Update changes the password of an existing user
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-old"
    When I run "bin/ee auth update bh-auth-command-1.test --user=bh-u1 --pass=bh-new"
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: Auth successfully updated for `bh-auth-command-1.test` scope. New values added:
      User: bh-u1
      Pass: bh-new
      """
    And the auth_users table should contain a row with:
      | site_url | bh-auth-command-1.test |
      | username | bh-u1                  |
      | password | bh-new                 |
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-new"
    And the htpasswd file "bh-auth-command-1.test" should reject user "bh-u1" with password "bh-old"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u1" with password "bh-new" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u1" with password "bh-old" should return 401

  Scenario: Update keeps the other users of the site
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u2 --pass=bh-p2"
    When I run "bin/ee auth update bh-auth-command-1.test --user=bh-u1 --pass=bh-p1-new"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u2" with password "bh-p2"
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-p1-new"

  Scenario: Update without --pass generates a new random password
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-old"
    When I run "bin/ee auth update bh-auth-command-1.test --user=bh-u1"
    Then the command should succeed
    And STDOUT should match:
      """
      /^Pass: [A-Za-z0-9]{18}$/m
      """
    When I save the "Pass" value from STDOUT as "NEW_PASS"
    Then the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "{NEW_PASS}"
    And the htpasswd file "bh-auth-command-1.test" should reject user "bh-u1" with password "bh-old"

  Scenario: Updating to the same password twice is idempotent
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth update bh-auth-command-1.test --user=bh-u1 --pass=bh-same"
    And I run "bin/ee auth update bh-auth-command-1.test --user=bh-u1 --pass=bh-same"
    Then the command should succeed
    And the auth_users table should have 1 rows with:
      | site_url | bh-auth-command-1.test |
      | username | bh-u1                  |
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-same"

  Scenario: Update without --user fails
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth update bh-auth-command-1.test --pass=bh-x"
    Then the command should fail
    And STDERR should contain:
      """
      Please provide auth user with --user flag
      """
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-p1"

  Scenario: Update of an unknown user fails
    When I run "bin/ee auth update bh-auth-command-1.test --user=bh-ghost --pass=bh-x"
    Then the command should fail
    And STDERR should contain:
      """
      Auth with username: bh-ghost does not exists on bh-auth-command-1.test
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-1.test |

  Scenario: Delete one user keeps the others
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u2 --pass=bh-p2"
    When I run "bin/ee auth delete bh-auth-command-1.test --user=bh-u1"
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: http auth successfully removed on bh-auth-command-1.test
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-1.test |
      | username | bh-u1                  |
    And the htpasswd file "bh-auth-command-1.test" should not contain user "bh-u1"
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u2" with password "bh-p2"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u1" with password "bh-p1" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-u2" with password "bh-p2" should return 200

  Scenario: Delete without --user removes every user and the htpasswd file
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u2 --pass=bh-p2"
    When I run "bin/ee auth delete bh-auth-command-1.test"
    Then the command should succeed
    And STDOUT should contain:
      """
      Reloading global reverse proxy.
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-1.test |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-1.test" should not exist
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" should return 200

  Scenario: Deleting the last user by name removes the protection
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth delete bh-auth-command-1.test --user=bh-u1"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-1.test" should not exist
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" should return 200

  Scenario: Delete on a site without auth fails
    When I run "bin/ee auth delete bh-auth-command-1.test"
    Then the command should fail
    And STDERR should contain:
      """
      Auth does not exists on bh-auth-command-1.test
      """

  Scenario: Delete of an unknown user fails and keeps the existing users
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth delete bh-auth-command-1.test --user=bh-ghost"
    Then the command should fail
    And STDERR should contain:
      """
      Auth with username: bh-ghost does not exists on bh-auth-command-1.test
      """
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-u1" with password "bh-p1"

  Scenario: Deleting twice fails the second time
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth delete bh-auth-command-1.test --user=bh-u1"
    Then the command should succeed
    When I run "bin/ee auth delete bh-auth-command-1.test --user=bh-u1"
    Then the command should fail
    And STDERR should contain:
      """
      Auth with username: bh-u1 does not exists on bh-auth-command-1.test
      """

  Scenario: List shows the site users in every format
    Given I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u2 --pass=bh-p2"
    When I run "bin/ee auth list bh-auth-command-1.test --format=json"
    Then the command should succeed
    And STDOUT should be valid JSON
    And the JSON output should contain items:
      | site_url               | username | password |
      | bh-auth-command-1.test | bh-u1    | bh-p1    |
      | bh-auth-command-1.test | bh-u2    | bh-p2    |
    When I save the JSON item count as "ITEMS"
    And I run "bin/ee auth list bh-auth-command-1.test --format=count"
    Then STDOUT should be exactly:
      """
      {ITEMS}
      """
    # Piped output (as under Behat) uses the tab-separated table renderer, not the ASCII one.
    When I run "bin/ee auth list bh-auth-command-1.test"
    Then the command should succeed
    And STDOUT should match:
      """
      /^site_url\tusername\tpassword$/m
      """
    And STDOUT should match:
      """
      /^bh-auth-command-1\.test\tbh-u1\tbh-p1$/m
      """
    When I run "bin/ee auth list bh-auth-command-1.test --format=csv"
    Then STDOUT should contain:
      """
      site_url,username,password
      """
    And STDOUT should contain:
      """
      bh-auth-command-1.test,bh-u2,bh-p2
      """
    When I run "bin/ee auth list bh-auth-command-1.test --format=yaml"
    Then STDOUT should contain:
      """
      username: bh-u1
      """

  Scenario: List of a site without its own users shows only the global or admin-tools auth
    Given the html site "bh-auth-command-2.test" exists
    And the site "bh-auth-command-2.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth list bh-auth-command-2.test --format=json"
    Then the command should succeed
    And the JSON output should not contain items:
      | site_url               |
      | bh-auth-command-2.test |
      | bh-auth-command-1.test |

  Scenario: The site's list does not show other sites' users
    Given the html site "bh-auth-command-2.test" exists
    And the site "bh-auth-command-2.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-2.test --user=bh-other --pass=bh-other-pass"
    And I run "bin/ee auth create bh-auth-command-1.test --user=bh-u1 --pass=bh-p1"
    When I run "bin/ee auth list bh-auth-command-1.test --format=json"
    Then the JSON output should not contain items:
      | username |
      | bh-other |

  Scenario: --ip together with --user on create only creates a whitelist
    When I run "bin/ee auth create bh-auth-command-1.test --ip=192.0.2.10 --user=bh-ignored --pass=bh-ignored"
    Then the command should succeed
    And the auth_ips table should contain a row with:
      | site_url | bh-auth-command-1.test |
      | ip       | 192.0.2.10             |
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-1.test |

  Scenario Outline: Passwords with shell metacharacters work end to end
    # Regression check (fixed by EasyEngine/auth-command#57): user and password used to go unquoted into `docker exec … htpasswd`.
    When I run "bin/ee auth create bh-auth-command-1.test --user=bh-meta <pass_arg>"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-1.test" should accept user "bh-meta" with password "<pass>"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-1.test" as user "bh-meta" with password "<pass>" should return 200

    Examples:
      | pass_arg           | pass       |
      | '--pass=two words' | two words  |
      | '--pass=a$HOMEb'   | a$HOMEb    |
      | '--pass=semi;colon' | semi;colon |
