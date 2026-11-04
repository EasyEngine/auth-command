@destructive @migration
Feature: Auth files migration on upgrade
  The container migration regenerates every site's htpasswd and ACL files. When a later upgrade step
  fails (here: the registry is unreachable, so the image migration can't pull), the old files must be
  back exactly and the migration must run again on the next attempt.

  Scenario: A failed upgrade restores the auth files and the next attempt writes them
    Given global http auth is not enabled
    And the html site "bh-auth-command-mig.test" exists with flags "--alias-domains='bh-auth-mig-alias.test,*.bh-auth-mig-w.test'"
    And the site "bh-auth-command-mig.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-mig.test --user=bh-mu --pass=bh-mp"
    And the command should succeed
    And the auth files of "bh-auth-command-mig.test" are as an older auth-command wrote them
    And the container migration "20260924120000_auth-command_regenerate_site_auth_files.php" is not recorded
    And the EasyEngine option "version" is set to "4.12.0"
    And Docker Hub is unreachable
    When I run "bin/ee cli info"
    Then the command should fail
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/_wildcard.bh-auth-mig-w.test" should not exist
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-mig-alias.test" should not exist
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-command-mig.test" should exist
    And the migrations table should not contain a row with:
      | migration | 20260924120000_auth-command_regenerate_site_auth_files.php |
    And the EasyEngine option "version" should be "4.12.0"
    And a GET request to "http://{PUBLIC_IP}/" with Host "foo.bh-auth-mig-w.test" should return 200
    When Docker Hub is reachable again
    And I run "bin/ee cli info"
    Then the command should succeed
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/_wildcard.bh-auth-mig-w.test" should exist
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/htpasswd/bh-auth-mig-alias.test" should exist
    And the migrations table should contain a row with:
      | migration | 20260924120000_auth-command_regenerate_site_auth_files.php |
    And a GET request to "http://{PUBLIC_IP}/" with Host "foo.bh-auth-mig-w.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-mig-alias.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "foo.bh-auth-mig-w.test" as user "bh-mu" with password "bh-mp" should return 200

  Scenario: The upgrade removes invalid whitelist entries from existing _acl files
    # Fixed by EasyEngine/auth-command#59: an _acl file written by an older version with an entry nginx rejects fails the proxy reload, and the
    # proxy restart when the upgrade recreates it. The migration drops only those lines; the rows stay stored.
    # An entry nginx accepts, like a prefix with a leading zero, is kept in its normalized form.
    Given global http auth is not enabled
    And the html site "bh-auth-command-mig.test" exists
    And the site "bh-auth-command-mig.test" has no http auth or whitelist
    And I run "bin/ee auth create bh-auth-command-mig.test --user=bh-mu --pass=bh-mp"
    And the command should succeed
    And the whitelist entry "192.0.2.1/99" of "bh-auth-command-mig.test" was stored unchecked by an older auth-command
    And the whitelist entry "192.0.2.0/024" of "bh-auth-command-mig.test" was stored unchecked by an older auth-command
    And the container migration "20260927120000_auth-command_drop_invalid_whitelist_entries_from_acl_files.php" is not recorded
    And the EasyEngine option "version" is set to "4.12.0"
    When I run "bin/ee cli info"
    Then the command should succeed
    And STDERR should contain:
      """
      Removed the invalid whitelist entries '192.0.2.1/99'
      """
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-mig.test_acl" should not contain:
      """
      192.0.2.1/99
      """
    And the file "{EE_ROOT_DIR}/services/nginx-proxy/vhost.d/bh-auth-command-mig.test_acl" should contain the line "allow 192.0.2.0/24;" exactly 1 times
    And the migrations table should contain a row with:
      | migration | 20260927120000_auth-command_drop_invalid_whitelist_entries_from_acl_files.php |
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-mig.test" should return 401
    And a GET request to "http://{PUBLIC_IP}/" with Host "bh-auth-command-mig.test" as user "bh-mu" with password "bh-mp" should return 200
