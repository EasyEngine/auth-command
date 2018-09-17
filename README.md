easyengine/auth-command
=======================

Configure HTTP auth for EasyEngine site.



Quick links: [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Using

This package implements the following commands:

### ee auth

Configure HTTP Authentication and whitelisting for EasyEngine site

~~~
ee auth
~~~

**EXAMPLES**

       # Add auth to a site
       $ ee auth create example.com --user=test --pass=test

       # Delete auth from a site
       $ ee auth delete example.com --user=test



### ee auth create

Creates http authentication for a site.

~~~
ee auth create [<site-name>] [--user=<user>] [--pass=<pass>] [--site] [--admin-tools]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope.

	[--user=<user>]
		Username for http auth.

	[--pass=<pass>]
		Password for http auth.

	[--site]
		Create auth on site.

	[--admin-tools]
		Create auth on admin tools.

**EXAMPLES**

    # Add auth on site and its admin tools with default username(easyengine) and random password
    $ ee auth create example.com

    # Add auth on all sites and its admin tools with default username and random password
    $ ee auth create global

    # Add auth on site and its admin tools with predefined username and password
    $ ee auth create example.com --user=test --pass=password

    # Add auth only on admin tools
    $ ee auth create example.com --admin-tools

    # Add auth on site and its admin tools with default username and random password
    $ ee auth create example.com --pass=password



### ee auth delete

Deletes http authentication for a site. Default: removes http authentication from site. If `--user` is passed it removes that specific user.

~~~
ee auth delete [<site-name>] [--user=<user>] [--site] [--admin-tools]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope.

	[--user=<user>]
		Username that needs to be deleted.

	[--site]
		Delete auth on site.

	[--admin-tools]
		Delete auth for admin tools.

**EXAMPLES**

    # Remove auth on site and its admin tools with default username(easyengine)
    $ ee auth delete example.com

    # Remove auth on site and its admin tools with custom username
    $ ee auth delete example.com --user=example

    # Remove global auth on all site's admin tools with default username(easyengine)
    $ ee auth delete example.com --admin-tools

    # Remove global auth on all sites (but not admin tools) with default username(easyengine)
    $ ee auth delete example.com --site



### ee auth list

Lists http authentication users of a site.

~~~
ee auth list [<site-name>] [--site] [--admin-tools] [--format=<format>]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope.

	[--site]
		List auth on site.

	[--admin-tools]
		List auth for admin-tools.

	[--format=<format>]
		Render output in a particular format.
		---
		default: table
		options:
		  - table
		  - csv
		  - yaml
		  - json
		  - count
		---

**EXAMPLES**

    # List all auth on site
    $ ee auth list example.com

    # List all global auth
    $ ee auth list global



### ee auth update

Updates http authentication password for a site.

~~~
ee auth update [<site-name>] [--user=<user>] [--pass=<pass>] [--site] [--admin-tools]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope.

	[--user=<user>]
		Username for http auth.

	[--pass=<pass>]
		Password for http auth.

	[--site]
		Update auth on site.

	[--admin-tools]
		Update auth on admin tools.

**EXAMPLES**

    # Update auth password on site and its admin tools with default username(easyengine) and random password
    $ ee auth update example.com

    # Update auth password on all sites and its admin tools with default username and random password
    $ ee auth update global

    # Update auth password on site and its admin tools with predefined username and password
    $ ee auth update example.com --user=test --pass=password

    # Update auth password only on admin tools
    $ ee auth update example.com --admin-tools

    # Update auth password on site and its admin tools with default username and random password
    $ ee auth update example.com --pass=password



### ee auth whitelist

Create, append, remove, list ip whitelisting for a site or globally.

~~~
ee auth whitelist [<create>] [<append>] [<list>] [<remove>] [<site-name>] [--ip=<ip>]
~~~

**OPTIONS**

	[<create>]
		Create ip whitelisting for a site or globally.

	[<append>]
		Append ips in whitelisting of a site or globally.

	[<list>]
		List whitelisted ip's of a site or of global scope.

	[<remove>]
		Remove whitelisted ip's of a site or of global scope.

	[<site-name>]
		Name of website / `global` for global scope.

	[--ip=<ip>]
		Comma seperated ips.

**EXAMPLES**

    # Whitelisted IP on site
    $ ee auth whitelist create example.com --ip=127.0.0.1,192.168.0.1

    # Whitelist IP on site where previous whitelisting are present
    $ ee auth whitelist append example.com --ip=127.0.0.1

    # List all whitelisted ips on site
    $ ee auth whitelist list example.com

    # Remove a whitelisted IP on site
    $ ee auth whitelist remove example.com --ip=127.0.0.1

    # Remove all whitelisted IPs on site
    $ ee auth whitelist remove example.com --ip=all

    # Above all will work for global auth by replacing site name with global

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.


### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/easyengine/auth-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/easyengine/auth-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible.

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/easyengine/auth-command/issues/new) to discuss whether the feature is a good fit for the project.

## Support

Github issues aren't for general support questions, but there are other venues you can try: https://easyengine.io/support/


*This README.md is generated dynamically from the project's codebase using `ee scaffold package-readme` ([doc](https://github.com/EasyEngine/scaffold-command)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
