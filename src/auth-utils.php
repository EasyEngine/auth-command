<?php


namespace EE\Auth\Utils;

use EE;
use EE\Model\Auth;
use EE\Model\Option;
use EE\Model\Whitelist;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Service\Utils\ensure_global_network_initialized;
use function EE\Utils\get_config_value;

// Global htpasswd/ACL file names that never belong to a site.
const RESERVED_AUTH_FILE_NAMES = [ 'default', 'default_admin_tools' ];

/**
 * Checks whether a name is one of the global htpasswd/ACL file names, in any case.
 *
 * @param string $name File name.
 *
 * @return bool
 */
function is_reserved_auth_file_name( string $name ): bool {

	return in_array( strtolower( $name ), RESERVED_AUTH_FILE_NAMES, true );
}

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
	if ( is_reserved_auth_file_name( $domain ) ) {
		return false;
	}

	return 1 === preg_match( '/^(\*\.)?[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/D', $domain );
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
 * @param string              $site_url      URL of site.
 * @param \EE\Model\Site|null $site_data     Site model.
 * @param array               $extra_aliases Alias domains not saved on the site yet, e.g. ones about to be added.
 *
 * @return array
 */
function get_site_auth_domains( string $site_url, $site_data, array $extra_aliases = [] ): array {

	$is_subdom = ! empty( $site_data->app_sub_type ) && 'subdom' === $site_data->app_sub_type;
	$domains   = [ $site_url ];

	if ( $is_subdom ) {
		$domains[] = '_wildcard.' . $site_url;
	}

	$aliases = empty( $site_data->alias_domains ) ? [] : explode( ',', $site_data->alias_domains );
	$domains = array_merge( $domains, get_alias_auth_domains( array_merge( $aliases, $extra_aliases ) ) );

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
		if ( '' === $domain || is_reserved_auth_file_name( $domain ) ) {
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
 * @param string              $site_url      URL of site.
 * @param \EE\Model\Site|null $site_data     Site model.
 * @param array               $extra_aliases Alias domains not saved on the site yet, e.g. ones about to be added.
 *
 * @throws \Exception
 */
function generate_site_auth_files( string $site_url, $site_data = null, array $extra_aliases = [] ) {

	$dir        = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	$domains    = get_site_auth_domains( $site_url, $site_data, $extra_aliases );
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
		return;
	}

	copy_proxy_file( $dir, $source, $domains );
}

/**
 * Writes a site's htpasswd and ACL files, including for alias domains that are about to be served.
 *
 * @param string              $site_url  URL of site.
 * @param \EE\Model\Site|null $site_data Site model.
 * @param array               $aliases   Alias domains not saved on the site yet.
 *
 * @throws \Exception
 */
function add_site_auth_files( string $site_url, $site_data, array $aliases ) {

	generate_site_auth_files( $site_url, $site_data, $aliases );
	generate_site_whitelist( $site_url, $site_data, $aliases );
}

/**
 * Checks whether any of the given file names lacks the htpasswd or ACL file the site's own entries call for.
 *
 * @param string $site_url URL of site.
 * @param array  $names    File names as returned by get_alias_auth_domains().
 *
 * @return bool
 */
function site_auth_files_missing( string $site_url, array $names ): bool {

	$has_auths = ! empty( Auth::where( 'site_url', $site_url ) );
	$has_ips   = Whitelist::has_ips( $site_url );

	foreach ( $names as $name ) {
		$auth_missing = $has_auths && ! is_file( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $name );
		$acl_missing  = $has_ips && ! is_file( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $name . '_acl' );

		if ( $auth_missing || $acl_missing ) {
			return true;
		}
	}

	return false;
}

/**
 * (Re)creates an htpasswd file in the proxy container with the given auth entries.
 *
 * The file is replaced only once all entries are written; on failure the existing file is left unchanged.
 *
 * @param string $name  File name inside the htpasswd directory.
 * @param array  $auths Auth models, or objects with `username` and `password`.
 *
 * @return bool Whether all entries were written.
 */
function write_htpasswd_file( string $name, array $auths ): bool {

	if ( empty( $auths ) ) {
		return true;
	}

	$dir     = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	// No host name starts with a dot, so the proxy never uses the file while it is being built.
	$tmp     = '.' . $name . '.tmp';
	$flags   = 'bc';
	$written = true;

	foreach ( $auths as $auth ) {
		// Keep the credentials out of ee.log.
		$obfuscate = [ escapeshellarg( $auth->password ), escapeshellarg( $auth->username ) ];

		if ( ! EE::exec( htpasswd_command( $flags, $tmp, $auth->username, $auth->password ), false, false, $obfuscate ) ) {
			$written = false;
			break;
		}
		$flags = 'b';
	}

	if ( $written ) {
		try {
			( new Filesystem() )->rename( $dir . '/' . $tmp, $dir . '/' . $name, true );
		} catch ( \Exception $e ) {
			$written = false;
		}
	}

	if ( ! $written ) {
		remove_proxy_file( $dir, $tmp );
		EE::warning( sprintf( 'Could not write the htpasswd file %s, so it was left unchanged.', $name ) );
	}

	return $written;
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

	// htpasswd reports `Adding password for user <name>` on STDERR, which EE::exec() would log; failures still show in the exit code.
	return sprintf(
		'docker exec %s htpasswd -%s %s %s %s 2>/dev/null',
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
 * @param string              $site_url      URL of site, `default` for global.
 * @param \EE\Model\Site|null $site_data     Site model.
 * @param array               $extra_aliases Alias domains not saved on the site yet, e.g. ones about to be added.
 *
 * @throws \Exception
 */
function generate_site_whitelist( string $site_url, $site_data = null, array $extra_aliases = [] ) {

	$dir     = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d';
	$domains = get_site_auth_domains( $site_url, $site_data, $extra_aliases );
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
