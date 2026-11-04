@destructive
Feature: Global HTTP auth and global IP whitelist
  `ee auth … global` writes `default` rows, htpasswd/default (used by every site without its own
  htpasswd file), vhost.d/default_acl, and regenerates the files of sites with their own rows.
  Every scenario changes global state; the context snapshots the global and admin-tools users
  and the global whitelist before each scenario and restores them (same passwords) afterwards.

  Background:
    Given the html site "bh-auth-command-8.test" exists
    And the html site "bh-auth-command-9.test" exists
    And the site "bh-auth-command-8.test" has no http auth or whitelist
    And the site "bh-auth-command-9.test" has no http auth or whitelist
    And there is no global http auth

  Scenario: Global auth protects every site without its own auth
    When I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    Then the command should succeed
    And STDOUT should contain:
      """
      Success: Auth successfully updated for `global` scope. New values added:
      User: bh-g1
      Pass: bh-g1-pass
      """
    And the auth_users table should contain a row with:
      | site_url | default    |
      | username | bh-g1      |
      | password | bh-g1-pass |
    And the htpasswd file "default" should accept user "bh-g1" with password "bh-g1-pass"
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-8.test" should not exist
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" as user "bh-g1" with password "bh-g1-pass" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" as user "bh-g1" with password "bh-g1-pass" should return 200

  Scenario: The frontend subnet bypasses global auth
    Given the frontend subnet is globally whitelisted
    When I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    Then the command should succeed
    And a GET request to "http://127.0.0.1/" with Host "bh-auth-command-8.test" should return 200

  Scenario: Global create without flags generates the credentials
    When I run "bin/ee auth create global"
    Then the command should succeed
    And STDOUT should match:
      """
      /^User: ee-[A-Za-z0-9]{6}$/m
      """
    When I save the "User" value from STDOUT as "GEN_USER"
    And I save the "Pass" value from STDOUT as "GEN_PASS"
    Then the htpasswd file "default" should accept user "{GEN_USER}" with password "{GEN_PASS}"

  Scenario: Global users are merged into the htpasswd file of sites with their own users
    Given I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-g1" with password "bh-g1-pass"
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-s1" with password "bh-s1-pass"
    And the htpasswd file "default" should not contain user "bh-s1"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" as user "bh-s1" with password "bh-s1-pass" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" as user "bh-g1" with password "bh-g1-pass" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" as user "bh-s1" with password "bh-s1-pass" should return 401

  Scenario: A site user created after global auth also gets the global users
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    Then the command should succeed
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-g1" with password "bh-g1-pass"
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-s1" with password "bh-s1-pass"

  Scenario: Deleting all site users removes the site file and falls back to the global users
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth delete bh-auth-command-9.test"
    Then the command should succeed
    # Since #57 (5f12c49) a site without own users has no htpasswd file, so the global default file applies.
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-9.test" should not exist
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" as user "bh-g1" with password "bh-g1-pass" should return 200

  Scenario: Creating an existing global user fails
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth create global --user=bh-g1 --pass=bh-other"
    Then the command should fail
    And STDERR should contain:
      """
      Auth for user bh-g1 already exists for this site. To update it, use `ee auth update`
      """
    And the htpasswd file "default" should accept user "bh-g1" with password "bh-g1-pass"

  Scenario: A global user name cannot reuse a site user name
    Given I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth create global --user=bh-s1 --pass=bh-x"
    Then the command should fail
    And STDERR should contain:
      """
      There already exists an username bh-s1 on some site or globally. You cannot use this username
      """
    And the auth_users table should not contain a row with:
      | site_url | default |
      | username | bh-s1   |

  Scenario: A site user name cannot reuse a global user name
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth create bh-auth-command-9.test --user=bh-g1 --pass=bh-x"
    Then the command should fail
    And STDERR should contain:
      """
      There already exists an username bh-g1 on this site or globally. You cannot use this username
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-9.test |

  Scenario: Updating a global password regenerates the global and site files
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-old"
    And I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth update global --user=bh-g1 --pass=bh-new"
    Then the command should succeed
    And STDOUT should contain:
      """
      Auth successfully updated for `global` scope. New values added:
      User: bh-g1
      Pass: bh-new
      """
    And the htpasswd file "default" should accept user "bh-g1" with password "bh-new"
    And the htpasswd file "default" should reject user "bh-g1" with password "bh-old"
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-g1" with password "bh-new"
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-s1" with password "bh-s1-pass"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" as user "bh-g1" with password "bh-old" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" as user "bh-g1" with password "bh-new" should return 200

  Scenario: Updating a global password without --pass generates one
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-old"
    When I run "bin/ee auth update global --user=bh-g1"
    Then the command should succeed
    When I save the "Pass" value from STDOUT as "NEW_PASS"
    Then the htpasswd file "default" should accept user "bh-g1" with password "{NEW_PASS}"

  Scenario: Updating an unknown global user fails
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And the command should succeed
    When I run "bin/ee auth update global --user=bh-ghost --pass=bh-x"
    Then the command should fail
    And STDERR should contain:
      """
      Auth with username: bh-ghost does not exists on global
      """

  Scenario: Updating a site user through the global scope fails
    Given I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth update global --user=bh-s1 --pass=bh-x"
    Then the command should fail
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-s1" with password "bh-s1-pass"

  Scenario: Deleting one global user keeps the others
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And I run "bin/ee auth create global --user=bh-g2 --pass=bh-g2-pass"
    And I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth delete global --user=bh-g1"
    Then the command should succeed
    And the auth_users table should not contain a row with:
      | site_url | default |
      | username | bh-g1   |
    And the htpasswd file "default" should not contain user "bh-g1"
    And the htpasswd file "default" should accept user "bh-g2" with password "bh-g2-pass"
    And the htpasswd file "bh-auth-command-9.test" should not contain user "bh-g1"
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-g2" with password "bh-g2-pass"

  Scenario: Deleting all global users unprotects sites without their own auth
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And I run "bin/ee auth create global --user=bh-g2 --pass=bh-g2-pass"
    And I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth delete global"
    Then the command should succeed
    And the auth_users table should not contain a row with:
      | site_url | default |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/default" should not exist
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" should return 200
    And the htpasswd file "bh-auth-command-9.test" should not contain user "bh-g1"
    And the htpasswd file "bh-auth-command-9.test" should accept user "bh-s1" with password "bh-s1-pass"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" should return 401

  Scenario: Deleting global auth when there is none fails
    When I run "bin/ee auth delete global"
    Then the command should fail
    And STDERR should contain:
      """
      Auth does not exists on global
      """

  Scenario: Deleting an unknown global user fails
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And the command should succeed
    When I run "bin/ee auth delete global --user=bh-ghost"
    Then the command should fail
    And STDERR should contain:
      """
      Auth with username: bh-ghost does not exists on global
      """

  Scenario: Global list shows the global users
    Given there is no admin-tools http auth
    And I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And I run "bin/ee auth create global --user=bh-g2 --pass=bh-g2-pass"
    When I run "bin/ee auth list global --format=json"
    Then the command should succeed
    And the JSON output should have 2 items
    And the JSON output should contain items:
      | site_url    | username | password   |
      | Global Auth | bh-g1    | bh-g1-pass |
      | Global Auth | bh-g2    | bh-g2-pass |
    When I run "bin/ee auth list global --format=count"
    Then STDOUT should be exactly:
      """
      2
      """
    When I run "bin/ee auth list bh-auth-command-9.test --format=json"
    Then the JSON output should contain items:
      | site_url    | username |
      | Global Auth | bh-g1    |

  Scenario: A site list shows its own users followed by the global users
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    When I run "bin/ee auth list bh-auth-command-9.test --format=json"
    Then the command should succeed
    And the JSON output should contain items:
      | site_url               | username |
      | bh-auth-command-9.test | bh-s1    |
      | Global Auth            | bh-g1    |

  Scenario: Global list fails when there is neither global nor admin-tools auth
    Given there is no admin-tools http auth
    When I run "bin/ee auth list global"
    Then the command should fail
    And STDERR should contain:
      """
      Auth does not exists on global.
      """

  Scenario: Global whitelist update adds IPs to default_acl and to site _acl files
    Given I run "bin/ee auth create bh-auth-command-9.test --ip=192.0.2.1"
    When I run "bin/ee auth update global --ip=192.0.2.200"
    Then the command should succeed
    And the auth_ips table should contain a row with:
      | site_url | default     |
      | ip       | 192.0.2.200 |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/default_acl" should contain:
      """
      allow 192.0.2.200;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-9.test_acl" should contain:
      """
      allow 192.0.2.200;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-9.test_acl" should contain:
      """
      allow 192.0.2.1;
      """
    When I run "bin/ee auth list global --ip --format=json"
    Then the JSON output should contain items:
      | ip          |
      | 192.0.2.200 |

  Scenario: Global whitelist update keeps the existing global IPs
    Given the frontend subnet is globally whitelisted
    When I run "bin/ee auth update global --ip=192.0.2.200"
    Then the command should succeed
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/default_acl" should contain:
      """
      allow {FRONTEND_SUBNET_IP};
      """

  Scenario: Global whitelist update skips IPs that are already whitelisted
    Given the frontend subnet is globally whitelisted
    When I run "bin/ee auth update global --ip={FRONTEND_SUBNET_IP}"
    Then the command should succeed
    And STDOUT should contain:
      """
      {FRONTEND_SUBNET_IP} has already been whitelisted on default. Skipping it.
      """
    And the auth_ips table should have 1 rows with:
      | site_url | default              |
      | ip       | {FRONTEND_SUBNET_IP} |

  Scenario: Global whitelist create fails when a global whitelist exists
    Given the frontend subnet is globally whitelisted
    When I run "bin/ee auth create global --ip=192.0.2.201"
    Then the command should fail
    And STDERR should contain:
      """
      Whitelist is already created on default. To update IPs use `ee auth update` instead
      """
    And the auth_ips table should not contain a row with:
      | ip | 192.0.2.201 |

  Scenario: Deleting one global IP removes it from default_acl and site _acl files
    Given I run "bin/ee auth create bh-auth-command-9.test --ip=192.0.2.1"
    And I run "bin/ee auth update global --ip=192.0.2.200,192.0.2.201"
    When I run "bin/ee auth delete global --ip=192.0.2.200"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/default_acl" should not contain:
      """
      allow 192.0.2.200;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/default_acl" should contain:
      """
      allow 192.0.2.201;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-9.test_acl" should not contain:
      """
      allow 192.0.2.200;
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-9.test_acl" should contain the line "allow 192.0.2.201;" exactly 1 times
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-9.test_acl" should contain the line "allow 192.0.2.1;" exactly 1 times

  Scenario: Deleting the whole global whitelist removes default_acl and the frontend bypass
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth delete global --ip"
    Then the command should succeed
    And the auth_ips table should not contain a row with:
      | site_url | default |
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/default_acl" should not exist
    And a GET request to "http://127.0.0.1/" with Host "bh-auth-command-8.test" should return 401
    When I run "bin/ee auth list global --ip --format=json"
    Then the JSON output should have 0 items

  Scenario: Global whitelist create works when the global whitelist is empty
    Given I run "bin/ee auth delete global --ip"
    When I run "bin/ee auth create global --ip=192.0.2.200,192.0.2.201"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/default_acl" should contain:
      """
      satisfy any;
      allow 192.0.2.200;
      allow 192.0.2.201;
      deny all;
      """

  Scenario: A globally whitelisted client bypasses global and site auth
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    And I run "bin/ee auth create bh-auth-command-9.test --user=bh-s1 --pass=bh-s1-pass"
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" should return 401
    When I run "bin/ee auth update global --ip={PUBLIC_IP}"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" should return 200
    When I run "bin/ee auth delete global --ip={PUBLIC_IP}"
    Then a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" should return 401

  Scenario: A site whitelist only bypasses global auth on that site
    Given I run "bin/ee auth create global --user=bh-g1 --pass=bh-g1-pass"
    When I run "bin/ee auth create bh-auth-command-9.test --ip={PUBLIC_IP}"
    Then the command should succeed
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-9.test" should return 200
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-8.test" should return 401
