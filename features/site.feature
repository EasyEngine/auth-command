Feature: Auth Command

  Scenario: ee executable is command working correctly
    Given 'bin/ee' is installed
    When I run 'bin/ee'
    Then STDOUT should return something like
    """
    NAME

      ee
    """

  Scenario: Check auth command is present
    When I run 'bin/ee auth'
    Then STDOUT should return exactly
    """
    usage: ee auth create [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]
       or: ee auth delete [<site-name>] [--user=<user>] [--ip=<ip>]
       or: ee auth list [<site-name>] [--ip] [--format=<format>]
       or: ee auth update [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]

    See 'ee help auth <command>' for more information on a specific command.
    """

  Scenario: Create php site
    When I run 'bin/ee site create php.test --type=php'
    Then After delay of 2 seconds
      And The site 'php.test' should have index file
      And Request on 'php.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Check auth list sub command is present
    When I run 'bin/ee auth list'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run auth list command on.
    Either pass it as an argument: `ee auth list <site-name>`
    or run `ee auth list` from inside the site folder.
    """

  Scenario: Check auth list and should be return error
    When I run 'bin/ee auth list php.test'
    Then STDERR should return something like
    """
    Error: Auth does not exists on php.test
    """

  Scenario: Check auth create sub command is present
    When I run 'bin/ee auth create'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run auth create command on.
    Either pass it as an argument: `ee auth create <site-name>`
    or run `ee auth create` from inside the site folder.
    """

  Scenario: Create auth for PHP site
    When I run 'bin/ee auth create php.test --user=rtcamp --pass=easyengine'
    Then STDOUT should return exactly
    """
    Reloading global reverse proxy.
    Success: Auth successfully updated for `php.test` scope. New values added:
    User: rtcamp
    Pass: easyengine
    """
      And Auth request on 'php.test' with user 'rtcamp' and password 'easyengine' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Check created auth credentials
    When I run 'bin/ee auth list php.test --format=csv'
    Then STDOUT should return exactly
    """
    username,password
    rtcamp,easyengine
    """

  Scenario: Check auth update sub command is present
    When I run 'bin/ee auth update'
    Then STDERR should return something like
    """
    Error: Could not find the site you wish to run auth update command on.
    Either pass it as an argument: `ee auth update <site-name>`
    or run `ee auth update` from inside the site folder.
    """

  Scenario: Update auth for PHP site
    When I run 'bin/ee auth update php.test --user=rtcamp --pass=rtcamp'
    Then STDOUT should return exactly
    """
    Reloading global reverse proxy.
    Success: Auth successfully updated for `php.test` scope. New values added:
    User: rtcamp
    Pass: rtcamp
    """
      And Auth request on 'php.test' with user 'rtcamp' and password 'rtcamp' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |
      And Request on 'php.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Check updated auth credentials
    When I run 'bin/ee auth list php.test --format=csv'
    Then STDOUT should return exactly
    """
    username,password
    rtcamp,rtcamp
    """

  Scenario: White list ips
    When I run 'bin/ee auth create php.test --ip="$(docker inspect -f '{{range .IPAM.Config}}{{.Gateway}}{{end}}' ee-global-frontend-network)"'
      And Request on 'php.test' should contain following headers:
        | header           |
        | HTTP/1.1 200 OK  |

  Scenario: Check whitelisted ips list
    When I run 'bin/ee auth list php.test --format=csv --ip'
    Then STDOUT should return something like
    """
    ip
    """

  Scenario: Update auth with unregistered user for PHP site to get exception
    When I run 'bin/ee auth update php.test --user=rtcamp1 --pass=rtcamp'
    Then STDERR should return something like
    """
    Error: Auth with username: rtcamp1 does not exists on php.test
    """
