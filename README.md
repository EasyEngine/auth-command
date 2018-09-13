easyengine/auth-command
=======================

Configure HTTP auth for EasyEngine site.



Quick links: [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Using

This package implements the following commands:

### ee auth

Adds HTTP auth to a site.

~~~
ee auth
~~~

**EXAMPLES**

       # Add auth to a site
       $ ee auth create example.com --user=test --pass=test

       # Delete auth from a site
       $ ee auth delete example.com --user=test



### ee auth create

Creates http auth for a site.

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
		Create auth on admin-tools.



### ee auth delete

Deletes http auth for a site. Default: removes http auth from site. If `--user` is passed it removes that specific user.

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
		Delete auth for admin-tools.



### ee auth list

Lists http auth users of a site.

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



### ee auth update

Updates http auth for a site.

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
		Update auth on admin-tools.



### ee auth whitelist

create, append, remove, list ip whitelisting for a site or globally.

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
