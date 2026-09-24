<?php


namespace EE\Auth\Utils;

use EE;
use EE\Model\Auth;
use EE\Model\Option;
use EE\Model\Whitelist;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Service\Utils\ensure_global_network_initialized;
use function EE\Utils\get_config_value;

/**
 * Initialize global admin tools auth if it's not present.
 *
 * @param string $display_log Wether to display log message or not.
 *
 * @throws \EE\ExitException
 * @throws \Exception
 */
function init_global_admin_tools_auth( $display_log = true ) {

	if ( ! empty( Auth::get_global_admin_tools_auth() ) || ! empty( Auth::get_global_auths() ) ) {
		if ( $display_log ) {
			EE::log( 'Global auth exists on admin-tools. Use `ee auth list global` to view credentials.' );
		}

		return;
	}

	verify_htpasswd_is_present();

	$pass      = \EE\Utils\random_password();
	$auth_data = [
		'site_url' => 'default_admin_tools',
		'username' => 'easyengine',
		'password' => $pass,
	];

	Auth::create( $auth_data );

	write_htpasswd_file( 'default_admin_tools', [ (object) $auth_data ] );

	if ( $display_log ) {
		EE::success( sprintf( 'Global admin-tools auth added. Use `ee auth list global` to view credentials.' ) );
	}

	ensure_global_network_initialized();

	$frontend_subnet_ip = Option::get( 'frontend_subnet_ip' );
	EE::runcommand( "auth update global --ip='$frontend_subnet_ip'" );
}

/**
 * Check if htpasswd is present in the global-container.
 */
function verify_htpasswd_is_present() {

	EE\Service\Utils\nginx_proxy_check();
	EE::debug( 'Verifying htpasswd is present.' );
	if ( EE::exec( sprintf( 'docker exec %s sh -c \'command -v htpasswd\'', EE_PROXY_TYPE ) ) ) {
		return;
	}
	EE::error( sprintf( 'Could not find apache2-utils installed in %s.', EE_PROXY_TYPE ) );
}

/**
 * Maps a domain to its htpasswd/ACL file name: `*.example.com` becomes `_wildcard.example.com`.
 *
 * @param string $domain Domain name.
 *
 * @return string
 */
function get_auth_domain( string $domain ): string {

	return 0 === strpos( $domain, '*.' ) ? '_wildcard.' . substr( $domain, 2 ) : $domain;
}

/**
 * Checks that an alias domain is a plain hostname or `*.hostname`, so it is safe to use as an htpasswd/ACL file name.
 *
 * @param string $domain Alias domain.
 *
 * @return bool
 */
function is_valid_alias_domain( string $domain ): bool {

	// These would map onto the global auth and ACL files.
	if ( in_array( $domain, [ 'default', 'default_admin_tools' ], true ) ) {
		return false;
	}

	return 1 === preg_match( '/^(\*\.)?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*$/D', $domain );
}

/**
 * Maps alias domains to their htpasswd/ACL file names, skipping unsafe ones.
 *
 * @param array $aliases Alias domains.
 *
 * @return array
 */
function get_alias_auth_domains( array $aliases ): array {

	$domains = [];
	foreach ( $aliases as $alias ) {
		$alias = trim( (string) $alias );
		if ( is_valid_alias_domain( $alias ) ) {
			$domains[] = get_auth_domain( $alias );
		}
	}

	return $domains;
}

/**
 * Collects the htpasswd/ACL file names of a site: the site itself, `_wildcard.<site>` for subdomain multisites, and its alias domains.
 *
 * @param string              $site_url  URL of site.
 * @param \EE\Model\Site|null $site_data Site model.
 *
 * @return array
 */
function get_site_auth_domains( string $site_url, $site_data ): array {

	$is_subdom = ! empty( $site_data->app_sub_type ) && 'subdom' === $site_data->app_sub_type;
	$domains   = [ $site_url ];

	if ( $is_subdom ) {
		$domains[] = '_wildcard.' . $site_url;
	}

	if ( ! empty( $site_data->alias_domains ) ) {
		$domains = array_merge( $domains, get_alias_auth_domains( explode( ',', $site_data->alias_domains ) ) );
	}

	return array_values( array_unique( $domains ) );
}

/**
 * Checks that a name refers to an entry directly inside a directory, not the directory itself or anything outside it.
 *
 * @param string $name File name.
 *
 * @return bool
 */
function is_proxy_file_name( string $name ): bool {

	return '' !== $name && '.' !== $name && '..' !== $name && false === strpbrk( $name, "/\\\0" );
}

/**
 * Removes a regular file directly inside a proxy directory.
 *
 * @param string $dir  Directory path.
 * @param string $name File name.
 *
 * @return bool Whether the file was removed.
 */
function remove_proxy_file( string $dir, string $name ): bool {

	$file = $dir . '/' . $name;

	// An empty name would make Filesystem::remove() wipe the whole directory.
	if ( ! is_proxy_file_name( $name ) || ! is_file( $file ) ) {
		return false;
	}

	( new Filesystem() )->remove( $file );

	return true;
}

/**
 * Copies a file inside a proxy directory to other names in the same directory.
 *
 * @param string $dir     Directory path.
 * @param string $source  Source file name.
 * @param array  $targets Target file names.
 */
function copy_proxy_file( string $dir, string $source, array $targets ) {

	$fs   = new Filesystem();
	$mode = fileperms( $dir . '/' . $source ) & 0777;

	foreach ( $targets as $target ) {
		if ( ! is_proxy_file_name( $target ) || $target === $source ) {
			continue;
		}
		$fs->copy( $dir . '/' . $source, $dir . '/' . $target, true );
		// Don't depend on the umask: nginx workers read these files.
		$fs->chmod( $dir . '/' . $target, $mode );
	}
}

/**
 * Removes the htpasswd and ACL files of the given domains.
 *
 * @param array $domains File names as returned by get_site_auth_domains().
 *
 * @return bool Whether any file was removed.
 */
function remove_auth_files( array $domains ): bool {

	$removed = false;
	foreach ( $domains as $domain ) {
		$domain = (string) $domain;
		// The global files never belong to a site.
		if ( in_array( $domain, [ '', 'default', 'default_admin_tools' ], true ) ) {
			continue;
		}
		$removed = remove_proxy_file( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd', $domain ) || $removed;
		$removed = remove_proxy_file( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d', $domain . '_acl' ) || $removed;
	}

	return $removed;
}

/**
 * Generates auth files for a site.
 *
 * @param string              $site_url  URL of site.
 * @param \EE\Model\Site|null $site_data Site model.
 *
 * @throws \Exception
 */
function generate_site_auth_files( string $site_url, $site_data = null ) {

	$dir        = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	$domains    = get_site_auth_domains( $site_url, $site_data );
	$site_auths = Auth::where( 'site_url', $site_url );

	// Without site entries the proxy falls back to the global `default` file.
	if ( empty( $site_auths ) ) {
		foreach ( $domains as $domain ) {
			remove_proxy_file( $dir, $domain );
		}

		return;
	}

	$source = array_shift( $domains );

	if ( ! write_htpasswd_file( $source, array_merge( Auth::get_global_auths(), $site_auths ) ) ) {
		EE::warning( sprintf( 'Could not write the htpasswd file of %s.', $site_url ) );

		return;
	}

	copy_proxy_file( $dir, $source, $domains );
}

/**
 * Writes a site's current htpasswd and ACL files for extra file names, e.g. of alias domains that are about to be served.
 *
 * Only writes the files the site has own entries for, so other names keep falling back to the global files.
 *
 * @param string $site_url URL of site.
 * @param array  $names    File names as returned by get_auth_domain().
 *
 * @return bool Whether any file was written.
 */
function add_site_auth_files( string $site_url, array $names ): bool {

	$names = array_diff( array_unique( $names ), [ $site_url, 'default', 'default_admin_tools' ] );

	if ( empty( $names ) ) {
		return false;
	}

	$written    = false;
	$dir        = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	$site_auths = Auth::where( 'site_url', $site_url );

	if ( ! empty( $site_auths ) ) {
		if ( is_file( $dir . '/' . $site_url ) || write_htpasswd_file( $site_url, array_merge( Auth::get_global_auths(), $site_auths ) ) ) {
			copy_proxy_file( $dir, $site_url, $names );
			$written = true;
		} else {
			EE::warning( sprintf( 'Could not write the htpasswd file of %s.', $site_url ) );
		}
	}

	$ips = get_site_whitelist_ips( $site_url );

	if ( ! empty( $ips ) ) {
		foreach ( $names as $name ) {
			if ( is_proxy_file_name( $name ) ) {
				put_ips_to_file( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $name . '_acl', $ips );
				$written = true;
			}
		}
	}

	return $written;
}

/**
 * (Re)creates an htpasswd file in the proxy container with the given auth entries.
 *
 * @param string $name  File name inside the htpasswd directory.
 * @param array  $auths Auth models, or objects with `username` and `password`.
 *
 * @return bool Whether all entries were written.
 */
function write_htpasswd_file( string $name, array $auths ): bool {

	$flags = 'bc';
	foreach ( $auths as $auth ) {
		// Keep the credentials out of ee.log.
		$obfuscate = [ escapeshellarg( $auth->password ), escapeshellarg( $auth->username ) ];

		if ( ! EE::exec( htpasswd_command( $flags, $name, $auth->username, $auth->password ), false, false, $obfuscate ) ) {
			return false;
		}
		$flags = 'b';
	}

	return true;
}

/**
 * Builds the `htpasswd` command run in the proxy container, with shell-escaped arguments.
 *
 * @param string $flags    htpasswd flags without the leading dash.
 * @param string $name     File name inside the htpasswd directory.
 * @param string $username Username.
 * @param string $password Password.
 *
 * @return string
 */
function htpasswd_command( string $flags, string $name, string $username, string $password ): string {

	return sprintf(
		'docker exec %s htpasswd -%s %s %s %s',
		EE_PROXY_TYPE,
		$flags,
		escapeshellarg( '/etc/nginx/htpasswd/' . $name ),
		escapeshellarg( $username ),
		escapeshellarg( $password )
	);
}

/**
 * Gets the IPs to whitelist on a site: global and site entries, or none when the site has no own entries.
 *
 * @param string $site_url URL of site, `default` for global.
 *
 * @return array
 */
function get_site_whitelist_ips( string $site_url ): array {

	$site_ips = Whitelist::where( 'site_url', $site_url );

	if ( empty( $site_ips ) ) {
		return [];
	}

	return array_column(
		'default' === $site_url ? $site_ips : array_merge( Whitelist::get_global_ips(), $site_ips ),
		'ip'
	);
}

/**
 * Generates whitelist files for a site.
 *
 * @param string              $site_url  URL of site, `default` for global.
 * @param \EE\Model\Site|null $site_data Site model.
 *
 * @throws \Exception
 */
function generate_site_whitelist( string $site_url, $site_data = null ) {

	$dir     = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d';
	$domains = get_site_auth_domains( $site_url, $site_data );
	$ips     = get_site_whitelist_ips( $site_url );

	foreach ( $domains as $domain ) {
		// Without site entries the proxy falls back to `default_acl`.
		if ( empty( $ips ) ) {
			remove_proxy_file( $dir, $domain . '_acl' );
		} else {
			put_ips_to_file( $dir . '/' . $domain . '_acl', $ips );
		}
	}
}

/**
 * Function to put list of ip's into a file.
 *
 * @param string $file Path of file to write ip's in.
 * @param array  $ips  List of ip's.
 */
function put_ips_to_file( string $file, array $ips ) {

	if ( empty( $ips ) ) {
		return;
	}

	$file_content = 'satisfy any;' . PHP_EOL;
	foreach ( $ips as $ip ) {
		$file_content .= "allow $ip;" . PHP_EOL;
	}
	$file_content .= 'deny all;';
	( new Filesystem() )->dumpFile( $file, $file_content );
}
