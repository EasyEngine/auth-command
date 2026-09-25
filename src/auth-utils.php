<?php


namespace EE\Auth\Utils;

use EE;
use EE\Model\Auth;
use EE\Model\Option;
use EE\Model\Site;
use EE\Model\Whitelist;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Service\Utils\ensure_global_network_initialized;
use function EE\Site\Utils\is_reserved_proxy_file_name;
use function EE\Site\Utils\is_valid_alias_domain;
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
 * Maps alias domains to their htpasswd/ACL file names, skipping the ones site-command would reject.
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
 * Copies a file inside a proxy directory to other names in the same directory, or in $target_dir.
 *
 * @param string $dir        Directory path.
 * @param string $source     Source file name.
 * @param array  $targets    Target file names.
 * @param string $target_dir Target directory, $dir if empty.
 */
function copy_proxy_file( string $dir, string $source, array $targets, string $target_dir = '' ) {

	$fs         = new Filesystem();
	$mode       = fileperms( $dir . '/' . $source ) & 0777;
	$target_dir = '' === $target_dir ? $dir : $target_dir;

	foreach ( $targets as $target ) {
		if ( ! is_proxy_file_name( $target ) || ( $target === $source && $target_dir === $dir ) ) {
			continue;
		}
		// Built under a name no host matches, then renamed, so the proxy never reads a partial copy.
		$tmp = '.' . $target . '.tmp';
		try {
			$fs->copy( $dir . '/' . $source, $target_dir . '/' . $tmp, true );
			// Don't depend on the umask: nginx workers read these files.
			$fs->chmod( $target_dir . '/' . $tmp, $mode );
			$fs->rename( $target_dir . '/' . $tmp, $target_dir . '/' . $target, true );
		} catch ( \Exception $e ) {
			remove_proxy_file( $target_dir, $tmp );
			EE::warning( sprintf( 'Could not copy %s to %s, so it was left unchanged.', $source, $target ) );
		}
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
		if ( '' === $domain || is_reserved_proxy_file_name( $domain ) ) {
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
 * @param string              $stage_dir     If set, `_wildcard.*` files are written to its `htpasswd/` instead of the proxy.
 *
 * @throws \Exception
 */
function generate_site_auth_files( string $site_url, $site_data = null, array $extra_aliases = [], string $stage_dir = '' ) {

	$dir        = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	$domains    = get_site_auth_domains( $site_url, $site_data, $extra_aliases );
	$site_auths = Auth::where( 'site_url', $site_url );
	$staged     = '' === $stage_dir ? [] : array_filter( $domains, __NAMESPACE__ . '\is_wildcard_auth_name' );

	// A staged name must not stay live either, its staged copy replaces it.
	foreach ( $staged as $domain ) {
		remove_proxy_file( $dir, $domain );
	}

	// Without site entries the proxy falls back to the global `default` file.
	if ( empty( $site_auths ) ) {
		foreach ( $domains as $domain ) {
			remove_proxy_file( $dir, $domain );
		}

		return;
	}

	$source = array_shift( $domains );

	// If it can't be rewritten (e.g. the proxy is stopped during an upgrade), still spread the existing file to the other domains.
	if ( write_htpasswd_file( $source, array_merge( Auth::get_global_auths(), $site_auths ) ) || is_file( $dir . '/' . $source ) ) {
		copy_proxy_file( $dir, $source, array_diff( $domains, $staged ) );
		copy_proxy_file( $dir, $source, $staged, $stage_dir . '/htpasswd' );
	}
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
 * @param string              $stage_dir     If set, `_wildcard.*` files are written to its `vhost.d/` instead of the proxy.
 *
 * @throws \Exception
 */
function generate_site_whitelist( string $site_url, $site_data = null, array $extra_aliases = [], string $stage_dir = '' ) {

	$dir     = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d';
	$domains = get_site_auth_domains( $site_url, $site_data, $extra_aliases );
	$ips     = get_site_whitelist_ips( $site_url );

	foreach ( $domains as $domain ) {
		$staged = '' !== $stage_dir && is_wildcard_auth_name( $domain );
		// Without site entries the proxy falls back to `default_acl`.
		if ( empty( $ips ) || $staged ) {
			remove_proxy_file( $dir, $domain . '_acl' );
		}
		if ( ! empty( $ips ) ) {
			put_ips_to_file( ( $staged ? $stage_dir . '/vhost.d' : $dir ) . '/' . $domain . '_acl', $ips );
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

/**
 * Checks whether a htpasswd/ACL file name is a `*.X` one.
 *
 * @param string $name File name as returned by get_site_auth_domains().
 *
 * @return bool
 */
function is_wildcard_auth_name( string $name ): bool {

	return 0 === strpos( $name, '_wildcard.' );
}

/**
 * Directory, outside the proxy's mounts, where the auth migration stages `_wildcard.*` files while the old nginx-proxy template runs.
 *
 * @return string
 */
function get_wildcard_staging_dir(): string {

	return EE_ROOT_DIR . '/.staging/auth-wildcard';
}

/**
 * Checks whether the running nginx-proxy has the template that applies `_wildcard.X` files only to `*.X` hosts.
 *
 * @return bool False when the proxy isn't running or runs an older template.
 */
function proxy_has_acl_template(): bool {

	if ( 'running' !== \EE_DOCKER::container_status( EE_PROXY_TYPE ) ) {
		EE::debug( 'nginx-proxy is not running: treating its template as the old one.' );

		return false;
	}

	$check = EE::launch( sprintf( 'docker exec %s grep -c %s /app/nginx.tmpl', EE_PROXY_TYPE, escapeshellarg( 'define "acl"' ) ) );
	$new   = 0 === $check->return_code && (int) trim( $check->stdout ) > 0;
	EE::debug( 'nginx-proxy template: ' . ( $new ? 'new (has the acl block)' : 'old (no acl block)' ) );

	return $new;
}

/**
 * Checks whether a staged file matches the site's own live file: both absent, or both with the same content.
 *
 * @param string $live   Live file path.
 * @param string $staged Staged file path.
 *
 * @return bool
 */
function staged_file_is_current( string $live, string $staged ): bool {

	if ( ! is_file( $live ) || ! is_file( $staged ) ) {
		return is_file( $live ) === is_file( $staged );
	}

	return file_get_contents( $live ) === file_get_contents( $staged );
}

/**
 * Moves the `_wildcard.*` files staged by the auth migration into the proxy once it runs the new template, then reloads it.
 *
 * @return bool Whether the staged files were promoted.
 * @throws \Exception
 */
function promote_staged_wildcard_files(): bool {

	$stage = get_wildcard_staging_dir();
	if ( ! is_dir( $stage ) || ! proxy_has_acl_template() ) {
		return false;
	}

	$ht = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
	$vd = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d';

	foreach ( Site::all() as $site ) {
		$url   = $site->site_url;
		$names = array_filter( get_site_auth_domains( $url, $site ), __NAMESPACE__ . '\is_wildcard_auth_name' );
		if ( empty( $names ) ) {
			continue;
		}

		$current = true;
		foreach ( $names as $name ) {
			$current = $current && staged_file_is_current( "$ht/$url", "$stage/htpasswd/$name" ) && staged_file_is_current( "{$vd}/{$url}_acl", "$stage/vhost.d/{$name}_acl" );
		}

		if ( ! $current ) {
			// Auth changed since staging, e.g. by the older ee after an interrupted upgrade.
			EE::debug( "Staged wildcard auth files of $url are outdated, regenerating them." );
			add_site_auth_files( $url, $site, [] );
			continue;
		}

		foreach ( $names as $name ) {
			if ( is_file( "$stage/htpasswd/$name" ) ) {
				copy_proxy_file( "$stage/htpasswd", $name, [ $name ], $ht );
			}
			if ( is_file( "$stage/vhost.d/{$name}_acl" ) ) {
				copy_proxy_file( "$stage/vhost.d", $name . '_acl', [ $name . '_acl' ], $vd );
			}
		}
	}

	// Files of sites deleted since staging are dropped with it.
	( new Filesystem() )->remove( $stage );
	\EE\Site\Utils\reload_global_nginx_proxy();
	EE::debug( 'Promoted the staged wildcard auth files.' );

	return true;
}
