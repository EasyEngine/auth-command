easyengine/auth-command
=======================

Configure HTTP Authentication and whitelisting for EasyEngine site



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

	   # Add auth to admin-tools
	   $ ee auth create admin-tools --user=test --pass=test

	   # Delete auth from admin-tools
	   $ ee auth delete admin-tools --user=test



### ee auth create

Creates http authentication for a site.

~~~
ee auth create [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope / `admin-tools` for admin-tools.

	[--user=<user>]
		Username for http auth.

	[--pass=<pass>]
		Password for http auth.

	[--ip=<ip>]
		IP to whitelist.

**EXAMPLES**

    # Add auth on site with default username(easyengine) and random password
    $ ee auth create example.com

    # Add auth on all sites with default username and random password
    $ ee auth create global

    # Add auth on site with predefined username and password
    $ ee auth create example.com --user=test --pass=password

    # Add auth on site with default username and random password
    $ ee auth create example.com --pass=password

	# Add auth on admin-tools with predefined username and random password
	$ ee auth create admin-tools --user=test

	# Add auth on admin-tools with predefined username and password
	$ ee auth create admin-tools --user=test -pass=password

    # Whitelist IP on site
    $ ee auth create example.com --ip=8.8.8.8,1.1.1.1

    # Whitelist IP on all sites
    $ ee auth create global --ip=8.8.8.8,1.1.1.1



### ee auth delete

Deletes http authentication for a site. Default: removes http authentication from site. If `--user` is passed it removes that specific user.

~~~
ee auth delete [<site-name>] [--user=<user>] [--ip]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope / `admin-tools` for admin-tools.

	[--user=<user>]
		Username that needs to be deleted.

	[--ip]
		IP to remove. Default removes all.

**EXAMPLES**

    # Remove auth on site and its admin tools with default username(easyengine)
    $ ee auth delete example.com

    # Remove auth on site and its admin tools with custom username
    $ ee auth delete example.com --user=example

    # Remove global auth on all sites (but not admin tools) with default username(easyengine)
    $ ee auth delete global

	# Remove auth on admin-tools with specific username
	$ ee auth delete admin-tools --user=test

    # Remove specific whitelisted IPs on site
    $ ee auth delete example.com --ip=1.1.1.1,8.8.8.8

    # Remove all whitelisted IPs on site
    $ ee auth delete example.com --ip

    # Remove whitelisted IPs on all sites
    $ ee auth delete global --ip=1.1.1.1



### ee auth list

Lists http authentication users of a site.

~~~
ee auth list [<site-name>] [--ip] [--format=<format>]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global scope / `admin-tools` for admin-tools.

	[--ip]
		Show whitelisted IPs of site.

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

	# List all admin-tools auth
	$ ee auth list admin-tools



### ee auth update

Updates http authentication password for a site.

~~~
ee auth update [<site-name>] [--user=<user>] [--pass=<pass>] [--ip=<ip>]
~~~

**OPTIONS**

	[<site-name>]
		Name of website / `global` for global auth / `admin-tools` for admin-tools.

	[--user=<user>]
		Username for http auth.

	[--pass=<pass>]
		Password for http auth.

	[--ip=<ip>]
		IP to whitelist.

**EXAMPLES**

    # Update auth password on global auth with default username and random password
    $ ee auth update global --user=easyengine

    # Update auth password on site with predefined username and password
    $ ee auth update example.com --user=test --pass=password

	# Update auth password on admin-tools with predefined username and password
	$ ee auth update admin-tools --user=test --password=password

    # Update whitelisted IPs on site
    $ ee auth update example.com --ip=8.8.8.8,1.1.1.1

    # Update whitelisted IPs on all sites
    $ ee auth update global --ip=8.8.8.8,1.1.1.1

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
