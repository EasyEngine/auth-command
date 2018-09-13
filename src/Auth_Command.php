<?php

/**
 * Adds HTTP auth to a site.
 *
 * ## EXAMPLES
 *
 *        # Add auth to a site
 *        $ ee auth create example.com --user=test --pass=test
 *
 *        # Delete auth from a site
 *        $ ee auth delete example.com --user=test
 *
 * @package ee-cli
 */

use EE\Model\Auth;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Auth\Utils\verify_htpasswd_is_present;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\reload_global_nginx_proxy;

class Auth_Command extends EE_Command {

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var array $site_data Object containing essential site related information.
	 */
	private $site_data;

	public function __construct() {

		$this->fs = new Filesystem();
	}

	/**
	 * Creates http auth for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--user=<user>]
	 * : Username for http auth.
	 *
	 * [--pass=<pass>]
	 * : Password for http auth.
	 *
	 * [--site]
	 * : Create auth on site.
	 *
	 * [--admin-tools]
	 * : Create auth on admin tools.
	 *
	 * ## EXAMPLES
	 *
	 *     # Add auth on site and its admin tools with default username(easyengine) and random password
	 *     $ ee auth create example.com
	 *
	 *     # Add auth on all sites and its admin tools with default username and random password
	 *     $ ee auth create global
	 *
	 *     # Add auth on site and its admin tools with predefined username and password
	 *     $ ee auth create example.com --user=test --pass=password
	 *
	 *     # Add auth only on admin tools
	 *     $ ee auth create example.com --admin-tools
	 *
	 *     # Add auth on site and its admin tools with default username and random password
	 *     $ ee auth create example.com --pass=password
	 *
	 */
	public function create( $args, $assoc_args ) {

		verify_htpasswd_is_present();

		$global = $this->populate_info( $args, __FUNCTION__ );
		$scope  = $this->get_scope( $assoc_args );

		$user = EE\Utils\get_flag_value( $assoc_args, 'user', 'easyengine' );
		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		$site_url = $global ? 'default' : $this->site_data->site_url;

		if ( ! empty( $this->get_auths( $site_url, $scope, $user, false ) ) ) {
			$site_url = ( 'default' === $site_url ) ? 'global' : $site_url;
			EE::error( "Auth with username $user already exists on $site_url for --$scope." );
		}

		$auth_data = [
			'site_url' => $site_url,
			'username' => $user,
			'password' => $pass,
			'scope'    => 'site',
		];

		if ( 'site' === $scope || 'all' === $scope ) {
			$site_auth_file_name = $site_url;
			Auth::create( $auth_data );
			$params = $this->fs->exists( EE_CONF_ROOT . '/nginx/htpasswd/' . $site_auth_file_name ) ? 'b' : 'bc';
			EE::exec( sprintf( 'docker exec %s htpasswd -%s /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $params, $site_auth_file_name, $user, $pass ) );
		}

		if ( 'admin-tools' === $scope || 'all' === $scope ) {
			$site_auth_file_name = $site_url . '_admin_tools';
			$auth_data['scope']  = 'admin-tools';
			Auth::create( $auth_data );
			$params = $this->fs->exists( EE_CONF_ROOT . '/nginx/htpasswd/' . $site_auth_file_name ) ? 'b' : 'bc';
			EE::exec( sprintf( 'docker exec %s htpasswd -%s /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $params, $site_auth_file_name, $user, $pass ) );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();

		EE::success( sprintf( 'Auth successfully updated for `%s` scope. New values added/updated:', $this->site_data->site_url ) );
		EE::line( 'User: ' . $user );
		EE::line( 'Pass: ' . $pass );
	}

	/**
	 * Updates http auth password for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--user=<user>]
	 * : Username for http auth.
	 *
	 * [--pass=<pass>]
	 * : Password for http auth.
	 *
	 * [--site]
	 * : Update auth on site.
	 *
	 * [--admin-tools]
	 * : Update auth on admin tools.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update auth password on site and its admin tools with default username(easyengine) and random password
	 *     $ ee auth update example.com
	 *
	 *     # Update auth password on all sites and its admin tools with default username and random password
	 *     $ ee auth update global
	 *
	 *     # Update auth password on site and its admin tools with predefined username and password
	 *     $ ee auth update example.com --user=test --pass=password
	 *
	 *     # Update auth password only on admin tools
	 *     $ ee auth update example.com --admin-tools
	 *
	 *     # Update auth password on site and its admin tools with default username and random password
	 *     $ ee auth update example.com --pass=password
	 *
	 */
	public function update( $args, $assoc_args ) {

		verify_htpasswd_is_present();

		$scope  = $this->get_scope( $assoc_args );
		$global = $this->populate_info( $args, __FUNCTION__ );

		$user = EE\Utils\get_flag_value( $assoc_args, 'user', 'easyengine' );
		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		$site_url = $global ? 'default' : $this->site_data->site_url;
		$auths    = $this->get_auths( $site_url, $scope, $user );

		foreach ( $auths as $auth ) {
			$auth->update( [
				'password' => $pass,
			] );
			$site_auth_file_name = ( 'admin-tools' === $auth->scope ) ? $site_url . '_admin_tools' : $site_url;
			EE::exec( sprintf( 'docker exec %s htpasswd -b /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $site_auth_file_name, $user, $pass ) );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();

		EE::success( sprintf( 'Auth successfully updated for `%s` scope. New values added/updated:', $this->site_data->site_url ) );
		EE::line( 'User: ' . $user );
		EE::line( 'Pass: ' . $pass );
	}

	/**
	 * Deletes http auth for a site. Default: removes http auth from site. If `--user` is passed it removes that specific user.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--user=<user>]
	 * : Username that needs to be deleted.
	 *
	 * [--site]
	 * : Delete auth on site.
	 *
	 * [--admin-tools]
	 * : Delete auth for admin tools.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove auth on site and its admin tools with default username(easyengine)
	 *     $ ee auth delete example.com
	 *
	 *     # Remove auth on site and its admin tools with custom username
	 *     $ ee auth delete example.com --user=example
	 *
	 *     # Remove global auth on all site's admin tools with default username(easyengine)
	 *     $ ee auth delete example.com --admin-tools
	 *
	 *     # Remove global auth on all sites (but not admin tools) with default username(easyengine)
	 *     $ ee auth delete example.com --site
	 *
	 */
	public function delete( $args, $assoc_args ) {

		verify_htpasswd_is_present();

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$user     = EE\Utils\get_flag_value( $assoc_args, 'user' );
		$scope    = $this->get_scope( $assoc_args );
		$auths    = $this->get_auths( $site_url, $scope, $user );

		foreach ( $auths as $auth ) {
			$username   = $auth->username;
			$User_scope = $auth->scope;
			$auth->delete();
			$site_auth_file_name = ( 'admin-tools' === $auth->scope ) ? $site_url . '_admin_tools' : $site_url;
			EE::exec( sprintf( 'docker exec %s htpasswd -D /etc/nginx/htpasswd/%s %s', EE_PROXY_TYPE, $site_auth_file_name, $auth->username ) );
			$file = EE_CONF_ROOT . '/nginx/htpasswd/' . $site_auth_file_name;
			if ( empty( trim( file_get_contents( $file ) ) ) ) {
				$this->fs->remove( $file );
			}
			EE::success( sprintf( 'http auth successfully removed of user: %s for %s.', $username, $User_scope ) );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();
	}

	/**
	 * Lists http auth users of a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--site]
	 * : List auth on site.
	 *
	 * [--admin-tools]
	 * : List auth for admin-tools.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - yaml
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all auth on site
	 *     $ ee auth list example.com
	 *
	 *     # List all global auth
	 *     $ ee auth list global
	 *
	 */
	public function list( $args, $assoc_args ) {

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$scope    = $this->get_scope( $assoc_args );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$auths    = $this->get_auths( $site_url, $scope, false );

		$users = [];

		foreach ( $auths as $auth ) {
			if ( 'all' === $scope || $scope === $auth->scope ) {
				$users[] = [
					'username' => $auth->username,
					'password' => $auth->password,
					'scope'    => $auth->scope,
				];
			}
		}

		$formatter = new EE\Formatter( $assoc_args, [ 'username', 'password', 'scope' ] );
		$formatter->display_items( $users );
	}
}
