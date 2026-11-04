Feature: ee auth command surface, argument validation and site resolution
  Synopsis of `ee auth` and its subcommands, errors for unknown, missing or disabled sites,
  parameter validation done by the EE dispatcher, and site-name detection from the working directory.

  Scenario: ee auth without a subcommand prints the usage of every subcommand
    When I run "bin/ee auth"
    Then the command should succeed
    And STDOUT should contain:
      """
      usage: ee auth create [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]
      """
    And STDOUT should contain:
      """
      or: ee auth delete [<site-name>] [--user=<user>] [--ip]
      """
    And STDOUT should contain:
      """
      or: ee auth list [<site-name>] [--ip] [--format=<format>]
      """
    And STDOUT should contain:
      """
      or: ee auth update [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]
      """

  Scenario Outline: ee help documents every auth subcommand
    # Help wraps at the terminal width and pipes through less; pin both.
    When I run "COLUMNS=200 PAGER=cat bin/ee help auth <subcommand>"
    Then the command should succeed
    And STDOUT should contain:
      """
      ee auth <subcommand> <synopsis>
      """

    Examples:
      | subcommand | synopsis                                                    |
      | create     | [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]   |
      | update     | [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]   |
      | delete     | [<site-name>] [--user=<user>] [--ip]                        |
      | list       | [<site-name>] [--ip] [--format=<format>]                    |

  Scenario: An unknown auth subcommand is rejected
    When I run "bin/ee auth rotate"
    Then the command should fail
    And STDERR should contain:
      """
      'rotate' is not a registered subcommand of 'auth'. See 'ee help auth' for available subcommands.
      """

  Scenario Outline: Every subcommand rejects a site that does not exist
    When I run "bin/ee auth <subcommand> bh-auth-command-missing.test <args>"
    Then the command should fail
    And STDERR should contain:
      """
      Could not find the site you wish to run auth <subcommand> command on.
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-missing.test |
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-missing.test |

    Examples:
      | subcommand | args                           |
      | create     | --user=bh-u1 --pass=bh-p1      |
      | create     | --ip=192.0.2.1                 |
      | update     | --user=bh-u1 --pass=bh-p1      |
      | update     | --ip=192.0.2.1                 |
      | delete     | --user=bh-u1                   |
      | delete     | --ip                           |
      | list       | --format=json                  |

  Scenario Outline: Without a site name and outside a site directory every subcommand explains how to pass one
    When I run "bin/ee auth <subcommand> <args>"
    Then the command should fail
    And STDERR should contain:
      """
      Either pass it as an argument: `ee auth <subcommand> <site-name>`
      """

    Examples:
      | subcommand | args                      |
      | create     | --user=bh-u1 --pass=bh-p1 |
      | update     | --user=bh-u1              |
      | delete     | --user=bh-u1              |
      | list       | --format=json             |

  Scenario: The site name is detected from the current directory
    Given the html site "bh-auth-command-13.test" exists
    And the site "bh-auth-command-13.test" has no http auth or whitelist
    When I run "{EE_BIN} auth create --user=bh-cwd --pass=bh-cwd-pass" in the directory "{EE_ROOT_DIR}/sites/bh-auth-command-13.test/app"
    Then the command should succeed
    And STDOUT should contain:
      """
      Auth successfully updated for `bh-auth-command-13.test` scope.
      """
    And the auth_users table should contain a row with:
      | site_url | bh-auth-command-13.test |
      | username | bh-cwd                  |
    When I run "{EE_BIN} auth list --format=json" in the directory "{EE_ROOT_DIR}/sites/bh-auth-command-13.test"
    Then the command should succeed
    And the JSON output should contain items:
      | site_url                | username |
      | bh-auth-command-13.test | bh-cwd   |
    When I run "{EE_BIN} auth delete --user=bh-cwd" in the directory "{EE_ROOT_DIR}/sites/bh-auth-command-13.test/app"
    Then the command should succeed
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-13.test |

  Scenario: A wrong site name typed inside a site directory suggests the detected site
    Given the html site "bh-auth-command-13.test" exists
    When I run "{EE_BIN} auth list bh-auth-command-typo.test" in the directory "{EE_ROOT_DIR}/sites/bh-auth-command-13.test/app"
    Then the command should fail
    And STDERR should contain:
      """
      bh-auth-command-typo.test is not a valid site-name. Did you mean `ee auth list bh-auth-command-13.test`?
      """

  Scenario Outline: Every subcommand refuses a disabled site
    Given the html site "bh-auth-command-13.test" exists
    And I run "bin/ee site disable bh-auth-command-13.test"
    When I run "bin/ee auth <subcommand> bh-auth-command-13.test <args>"
    Then the command should fail
    And STDERR should contain:
      """
      Site bh-auth-command-13.test is not enabled. Use `ee site enable bh-auth-command-13.test` to enable it.
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-13.test |
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-13.test |

    Examples:
      | subcommand | args                      |
      | create     | --user=bh-u1 --pass=bh-p1 |
      | create     | --ip=192.0.2.1            |
      | update     | --user=bh-u1 --pass=bh-p1 |
      | update     | --ip=192.0.2.1            |
      | delete     | --user=bh-u1              |
      | delete     | --ip                      |
      | list       | --format=json             |

  Scenario Outline: The dispatcher rejects unknown parameters, extra positionals and bad formats
    Given the html site "bh-auth-command-13.test" exists
    When I run "bin/ee auth <subcommand> bh-auth-command-13.test <args>"
    Then the command should fail
    And STDERR should contain:
      """
      <error>
      """
    And the auth_users table should not contain a row with:
      | site_url | bh-auth-command-13.test |

    Examples:
      | subcommand | args                       | error                                    |
      | create     | --user=bh-u1 --password=x  | unknown --password parameter             |
      | create     | extra-positional           | Too many positional arguments: extra-positional |
      | update     | --user=bh-u1 --foo         | unknown --foo parameter                  |
      | delete     | --ips=192.0.2.1            | unknown --ips parameter                  |
      | list       | --format=xml               | Invalid value specified for 'format'     |

  Scenario: A value-less --ip on create is dropped with a warning and a random auth user is created instead
    # Dispatcher behaviour: `--ip` needs a value, so it is unset and create falls through to the auth branch.
    Given the html site "bh-auth-command-13.test" exists
    And the site "bh-auth-command-13.test" has no http auth or whitelist
    When I run "bin/ee auth create bh-auth-command-13.test --ip"
    Then the command should succeed
    And STDERR should contain:
      """
      --ip parameter needs a value
      """
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-13.test |
    And the auth_users table should have 1 rows with:
      | site_url | bh-auth-command-13.test |

  Scenario: An empty --ip value on create silently creates a random auth user
    Given the html site "bh-auth-command-13.test" exists
    And the site "bh-auth-command-13.test" has no http auth or whitelist
    When I run "bin/ee auth create bh-auth-command-13.test --ip="
    Then the command should succeed
    And STDOUT should contain:
      """
      Auth successfully updated for `bh-auth-command-13.test` scope.
      """
    And the auth_ips table should not contain a row with:
      | site_url | bh-auth-command-13.test |
    And the auth_users table should have 1 rows with:
      | site_url | bh-auth-command-13.test |

  Scenario: The global nginx-proxy stays up after auth changes
    Given the html site "bh-auth-command-13.test" exists
    And the site "bh-auth-command-13.test" has no http auth or whitelist
    When I run "bin/ee auth create bh-auth-command-13.test --user=bh-up --pass=bh-up-pass"
    Then the command should succeed
    And STDERR should not contain:
      """
      nginx config test failed
      """
    And the docker container "services_global-nginx-proxy_1" should be running
