<?php

/**
 * Configure HTTP Authentication and whitelisting for EasyEngine site
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
	 * Creates http authentication for a site.
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
	 * Updates http authentication password for a site.
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
	 * Deletes http authentication for a site. Default: removes http authentication from site. If `--user` is passed it removes that specific user.
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
	 * Lists http authentication users of a site.
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

	/**
	 * Create, append, remove, list ip whitelisting for a site or globally.
	 *
	 * ## OPTIONS
	 *
	 * [<create>]
	 * : Create ip whitelisting for a site or globally.
	 *
	 * [<append>]
	 * : Append ips in whitelisting of a site or globally.
	 *
	 * [<list>]
	 * : List whitelisted ip's of a site or of global scope.
	 *
	 * [<remove>]
	 * : Remove whitelisted ip's of a site or of global scope.
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--ip=<ip>]
	 * : Comma seperated ips.
	 *
	 * ## EXAMPLES
	 *
	 *     # Whitelisted IP on site
	 *     $ ee auth whitelist create example.com --ip=127.0.0.1,192.168.0.1
	 *
	 *     # Whitelist IP on site where previous whitelisting are present
	 *     $ ee auth whitelist append example.com --ip=127.0.0.1
	 *
	 *     # List all whitelisted ips on site
	 *     $ ee auth whitelist list example.com
	 *
	 *     # Remove a whitelisted IP on site
	 *     $ ee auth whitelist remove example.com --ip=127.0.0.1
	 *
	 *     # Remove all whitelisted IPs on site
	 *     $ ee auth whitelist remove example.com --ip=all
	 *
	 *     # Above all will work for global auth by replacing site name with global
	 *
	 */
	public function whitelist( $args, $assoc_args ) {

		// Note: If new sub-commands for whitelisting is added, function for it and this variable needs to be updated.
		$commands = [ 'create', 'append', 'list', 'remove' ];
		if ( ! ( isset( $args[0] ) && in_array( $args[0], $commands ) ) ) {
			$help = PHP_EOL;
			foreach ( $commands as $command ) {
				$help .= "ee auth whitelist $command [<site-name>/global] [--ip=<ip>]" . PHP_EOL;
			}
			EE::error( 'Please use valid command syntax. You can use:' . $help );

		}

		$command = array_shift( $args );
		$global  = $this->populate_info( $args, __FUNCTION__ . ' ' . $command );

		$ip = EE\Utils\get_flag_value( $assoc_args, 'ip' );

		$file         = EE_CONF_ROOT . '/nginx/vhost.d/';
		$file         .= $global ? 'default_acl' : $this->site_data->site_url . '_acl';
		$user_ips     = array_filter( explode( ',', $ip ), 'strlen' );
		$existing_ips = $this->get_ips_from_file( $global );

		call_user_func_array( [ $this, "whitelist_$command" ], [ $file, $user_ips, $existing_ips ] );

		reload_global_nginx_proxy();
	}

	/**
	 * Function to create whitelist file.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_create( $file, $user_ips, $existing_ips ) {

		$this->put_ips_to_file( $file, $user_ips );
		EE::success( sprintf( 'Created whitelist for `%s` scope with %s IP\'s.', $this->site_data->site_url, implode( ',', $user_ips ) ) );
	}

	/**
	 * Function to append to whitelist file.
	 *
	 * @param string $file        The whitelisting file.
	 * @param array $user_ips     ip's provided by the user.
	 * @param array $existing_ips Existing ip's in the given file.
	 */
	private function whitelist_append( $file, $user_ips, $existing_ips ) {

		$all_ips = array_unique( array_merge( $user_ips, $existing_ips ) );
		$this->put_ips_to_file( $file, $all_ips );
		EE::success( sprintf( 'Appended %s IP\'s to whitelist of `%s` scope', implode( ',', $user_ips ), $this->site_data->site_url ) );
	}

	/**
	 * Function to list whitelisted ips.
	 *
	 * @param string $file         The whitelisting file.
	 * @param array  $user_ips     ip's provided by the user.
	 * @param array  $existing_ips Existing ip's in the given file.
	 *
	 * @throws \EE\ExitException
	 */
	private function whitelist_list( $file, $user_ips, $existing_ips ) {

		if ( empty( $existing_ips ) ) {
			EE::error( sprintf( 'No Whitelisted IP\'s found for %s scope', $this->site_data->site_url ) );
		}

		EE::log( sprintf( 'Whitelisted IP\'s for %s scope', $this->site_data->site_url ) );
		foreach ( $existing_ips as $ips ) {
			EE::line( $ips );
		}
	}

	/**
	 * Function to remove whitelisted ips.
	 *
	 * @param string $file         The whitelisting file.
	 * @param array  $user_ips     ip's provided by the user.
	 * @param array  $existing_ips Existing ip's in the given file.
	 *
	 * @throws \EE\ExitException
	 */
	private function whitelist_remove( $file, $user_ips, $existing_ips ) {

		if ( empty( $user_ips ) || 'all' === $user_ips[0] ) {
			$this->fs->remove( $file );
		} else {
			$removed_ips  = array_intersect( $existing_ips, $user_ips );
			$leftover_ips = array_diff( $user_ips, $removed_ips );
			$updated_ips  = array_diff( $existing_ips, $user_ips );
			$file_content = '';
			foreach ( $updated_ips as $individual_ip ) {
				$file_content .= "allow $individual_ip;" . PHP_EOL;
			}
			$this->fs->dumpFile( $file, $file_content );
		}
		if ( empty( $removed_ips ) ) {
			EE::error( sprintf( '%s IP\'s not found in whitelist of `%s` scope', implode( ',', $user_ips ), $this->site_data->site_url ) );
		}
		EE::warning( sprintf( 'Could not find %s IP\'s from whitelist of `%s` scope', implode( ',', $leftover_ips ), $this->site_data->site_url ) );
		EE::success( sprintf( 'Removed %s IP\'s from whitelist of `%s` scope', implode( ',', $removed_ips ), $this->site_data->site_url ) );
	}

	/**
	 * Function to get the list of ip's from given file.
	 *
	 * @param boolean $global Is the scope global or site specific.
	 *
	 * @return array of existing ips.
	 */
	private function get_ips_from_file( $global ) {

		$file         = EE_CONF_ROOT . '/nginx/vhost.d/';
		$file         .= $global ? 'default_acl' : $this->site_data->site_url . '_acl';
		$existing_ips = [];
		if ( $this->fs->exists( $file ) ) {
			$existing_ips_in_file = array_slice( array_filter( explode( PHP_EOL, file_get_contents( $file ) ), 'trim' ), 1, - 1 );
			foreach ( $existing_ips_in_file as $ip_in_file ) {
				$existing_ips[] = str_replace( [ 'allow ', ';' ], '', trim( $ip_in_file ) );
			}
		}

		return $existing_ips;
	}

	/**
	 * Function to put list of ip's into a file.
	 *
	 * @param string $file Path of file to write ip's in.
	 * @param array $ips   List of ip's.
	 */
	private function put_ips_to_file( $file, $ips ) {

		$file_content = 'satisfy any;' . PHP_EOL;
		foreach ( $ips as $ip ) {
			$file_content .= "allow $ip;" . PHP_EOL;
		}
		$file_content .= 'deny all;';
		$this->fs->dumpFile( $file, $file_content );
	}

	/**
	 * Function to populate basic info from args
	 *
	 * @param array $args     args passed from function.
	 * @param string $command command name that is calling the function.
	 *
	 * @return bool $global Whether the command is global or site-specific.
	 */
	private function populate_info( $args, $command ) {

		$global = false;
		if ( isset( $args[0] ) && 'global' === $args[0] ) {
			$this->site_data = (object) [ 'site_url' => $args[0] ];
			$global          = true;
		} else {
			$args            = auto_site_name( $args, 'auth', $command );
			$this->site_data = get_site_info( $args, true, true, false );
		}

		return $global;
	}

	/**
	 * Get the appropriate scope from passed associative arguments.
	 *
	 * @param array $assoc_args Passed associative arguments.
	 *
	 * @return string Found scope.
	 */
	private function get_scope( $assoc_args ) {

		$scope_site        = $assoc_args['site'] ?? false;
		$scope_admin_tools = $assoc_args['admin-tools'] ?? false;

		if ( $scope_site && ! $scope_admin_tools ) {
			return 'site';
		}

		if ( $scope_admin_tools && ! $scope_site ) {
			return 'admin-tools';
		}

		return 'all';
	}

	/**
	 * Gets all the authentication objects from db.
	 *
	 * @param string $site_url Site URL.
	 * @param string $scope    The scope of auth.
	 * @param string $user     User for which the auth need to be fetched.
	 *
	 * @throws \EE\ExitException
	 * @return array Array of auth models.
	 */
	private function get_auths( $site_url, $scope, $user, $error_if_empty = true ) {

		$where_conditions = [ 'site_url' => $site_url ];

		$user_error_msg = '';
		if ( $user ) {
			$where_conditions['username'] = $user;
			$user_error_msg               = ' with username: ' . $user;
		}

		if ( 'all' !== $scope ) {
			$where_conditions['scope'] = $scope;
		}

		$auths = Auth::where( $where_conditions );

		if ( empty( $auths ) && $error_if_empty ) {
			$all_error_msg  = ( 'all' === $scope ) ? '' : 'for ' . $scope;
			$site_error_msg = ( 'default' === $site_url ) ? 'global' : $site_url;
			EE::error( sprintf( 'Auth%s does not exists on %s %s', $user_error_msg, $site_error_msg, $all_error_msg ) );
		}

		return $auths;
	}
}
