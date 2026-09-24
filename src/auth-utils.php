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

	EE::exec( htpasswd_command( 'bc', 'default_admin_tools', $auth_data['username'], $auth_data['password'] ) );

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
		foreach ( array_map( 'trim', explode( ',', $site_data->alias_domains ) ) as $alias ) {
			if ( '' === $alias ) {
				continue;
			}
			$domains[] = get_auth_domain( $alias );
		}
	}

	return array_values( array_unique( $domains ) );
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

	$fs = new Filesystem();

	$domains    = get_site_auth_domains( $site_url, $site_data );
	$site_auths = Auth::where( 'site_url', $site_url );

	foreach ( $domains as $domain ) {
		$fs->remove( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $domain );
	}

	// Without site entries the proxy falls back to the global `default` file.
	if ( empty( $site_auths ) ) {
		return;
	}

	$auths = array_merge( Auth::get_global_auths(), $site_auths );

	foreach ( $domains as $domain ) {
		write_htpasswd_file( $domain, $auths );
	}
}

/**
 * (Re)creates an htpasswd file in the proxy container with the given auth entries.
 *
 * @param string $name  File name inside the htpasswd directory.
 * @param array  $auths Auth models.
 */
function write_htpasswd_file( string $name, array $auths ) {

	$flags = 'bc';
	foreach ( $auths as $auth ) {
		EE::exec( htpasswd_command( $flags, $name, $auth->username, $auth->password ) );
		$flags = 'b';
	}
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
 * Generates whitelist files for a site.
 *
 * @param string              $site_url  URL of site, `default` for global.
 * @param \EE\Model\Site|null $site_data Site model.
 *
 * @throws \Exception
 */
function generate_site_whitelist( string $site_url, $site_data = null ) {

	$fs = new Filesystem();

	$domains  = get_site_auth_domains( $site_url, $site_data );
	$site_ips = Whitelist::where( 'site_url', $site_url );

	foreach ( $domains as $domain ) {
		$fs->remove( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $domain . '_acl' );
	}

	// Without site entries the proxy falls back to `default_acl`.
	if ( empty( $site_ips ) ) {
		return;
	}

	$whitelists = array_column(
		'default' === $site_url ? $site_ips : array_merge( Whitelist::get_global_ips(), $site_ips ),
		'ip'
	);

	foreach ( $domains as $domain ) {
		put_ips_to_file( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $domain . '_acl', $whitelists );
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
