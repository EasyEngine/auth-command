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
use EE\Model\Whitelist;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Auth\Utils\verify_htpasswd_is_present;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\get_site_name;
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
	 * [--ip=<ip>]
	 * : IP to whitelist.
	 *
	 * ## EXAMPLES
	 *
	 *     # Add auth on site with default username(easyengine) and random password
	 *     $ ee auth create example.com
	 *
	 *     # Add auth on all sites with default username and random password
	 *     $ ee auth create global
	 *
	 *     # Add auth on site with predefined username and password
	 *     $ ee auth create example.com --user=test --pass=password
	 *
	 *     # Add auth on site with default username and random password
	 *     $ ee auth create example.com --pass=password
	 *
	 *     # Whitelist IP on site
	 *     $ ee auth create example.com --ip=8.8.8.8,1.1.1.1
	 *
	 *     # Whitelist IP on all sites
	 *     $ ee auth create global --ip=8.8.8.8,1.1.1.1
	 */
	public function create( $args, $assoc_args ) {

		verify_htpasswd_is_present();

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$ips      = \EE\Utils\get_flag_value( $assoc_args, 'ip' );
		$site_url = $global ? 'default' : $this->site_data->site_url;

		if ( $ips ) {
			$this->create_whitelist( $site_url, $ips );
		} else {
			$this->create_auth( $assoc_args, $global, $site_url );
		}
	}

	/**
	 * Cleans and Validate IP addresses
	 * Converts input separated by comma, spaces and new-lines in array
	 *
	 * @param string $ips IPs to clean and validate
	 *
	 * @return array $user_ips Cleaned IP addresses.
	 */
	private function clean_and_validate_ips( string $ips ) {

		$user_ips = preg_split( '/[\ \n\,]+/', $ips );

		foreach ( $user_ips as $ip ) {

			// Remove subnet from ip if present.
			if ( preg_match( '~^(.+?)/([^/]+)$~', $ip, $m ) ) {
				$ip = $m[1];
			}

			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				EE::error( 'Please check your list do not have any empty or wrong IP addresses.' );
			}
		}

		return $user_ips;
	}

	/**
	 * Creates http auth
	 *
	 * @param array $assoc_args Assoc args passed to command
	 * @param bool $global      Enable auth on global
	 * @param string $site_url  URL of site
	 *
	 * @throws Exception
	 */
	private function create_auth( array $assoc_args, bool $global, string $site_url ) {
		$user      = \EE\Utils\get_flag_value( $assoc_args, 'user', 'ee-' . EE\Utils\random_password( 6 ) );
		$pass      = \EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );
		$auth_data = [
			'site_url' => $site_url,
			'username' => $user,
			'password' => $pass,
		];

		$query_conditions = [
			'site_url' => $site_url,
			'username' => $user,
		];

		$query_conditions['username'] = $user;
		$error_message                = "Auth for user $user already exists for this site. To update it, use `ee auth update`'";

		$existing_auths = Auth::where( $query_conditions );

		if ( ! empty( $existing_auths ) ) {
			EE::error( $error_message );
		}

		$admin_tools_auth = Auth::get_global_admin_tools_auth();
		if ( 'default' === $site_url && ! empty( $admin_tools_auth ) ) {
			$admin_tools_auth[0]->site_url = 'default';
			$admin_tools_auth[0]->save();
		}

		Auth::create( $auth_data );

		if ( 'default' === $site_url ) {
			$this->generate_global_auth_files();
		} else {
			$this->generate_site_auth_files( $site_url );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();

		EE::success( sprintf( 'Auth successfully updated for `%s` scope. New values added:', $this->site_data->site_url ) );
		EE::line( 'User: ' . $user );
		EE::line( 'Pass: ' . $pass );

	}

	/**
	 * Creates http auth whitelist
	 *
	 * @param string $site_url URL of site
	 * @param string $ips      IPs to whitelist
	 *
	 * @throws Exception
	 */
	private function create_whitelist( string $site_url, string $ips ) {
		$user_ips = $this->clean_and_validate_ips( $ips );

		if ( Whitelist::has_ips( $site_url ) ) {
			EE::error( "Whitelist is already created on $site_url. To update IPs use `ee auth update` instead" );
		}

		foreach ( $user_ips as $ip ) {
			Whitelist::create(
				[
					'site_url' => $site_url,
					'ip'       => $ip,
				]
			);
		}

		if ( 'default' === $site_url ) {
			$this->generate_global_whitelist();
		} else {
			$this->generate_site_whitelist( $site_url );
		}

		reload_global_nginx_proxy();
	}

	/**
	 * Function to populate basic info from args
	 *
	 * @param array  $args    args passed from function.
	 * @param string $command command name that is calling the function.
	 *
	 * @return bool $global Whether the command is global or site-specific.
	 */
	private function populate_info( $args, $command ) {

		$global = false;
		if ( isset( $args[0] ) && 'global' === $args[0] ) {
			$this->site_data = (object) [
				'site_url' => $args[0],
			];
			$global          = true;
		} else {
			$args            = auto_site_name( $args, 'auth', $command );
			$this->site_data = get_site_info( $args, true, true, false );
		}

		return $global;
	}

	/**
	 * Regenerate admin-tools auth if needed when global auth is deleted.
	 * @throws Exception
	 * @throws \EE\ExitException
	 */
	private function regen_admin_tools_auth() {
		$admin_tools = \EE\Model\Site::where( 'admin_tools', '1' );
		$mailhog     = \EE\Model\Site::where( 'mailhog_enabled', '1' );
		if ( empty( $admin_tools ) && empty( $mailhog ) ) {
			return;
		}
		EE::log( 'Creating new auth for admin-tools only.' );
		\EE\Auth\Utils\init_global_admin_tools_auth();
	}

	/**
	 * Generates auth files for global auth and all sites.
	 *
	 * @throws Exception
	 */
	private function generate_global_auth_files() {

		$global_admin_tools_auth = Auth::get_global_admin_tools_auth();

		if ( ! empty( $global_admin_tools_auth ) ) {
			EE::exec( sprintf( 'docker exec %s htpasswd -bc /etc/nginx/htpasswd/default_admin_tools %s %s', EE_PROXY_TYPE, $global_admin_tools_auth->username, $global_admin_tools_auth->password ) );
		} else {
			$this->fs->remove( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/default_admin_tools' );
			$this->fs->remove( EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/default' );
			$auths = Auth::get_global_auths();

			if ( empty( $auths ) ) {
				$this->regen_admin_tools_auth();
			} else {
				foreach ( $auths as $key => $auth ) {
					$flags = 'b';

					if ( 0 === $key ) {
						$flags = 'bc';
					}

					EE::exec( sprintf( 'docker exec %s htpasswd -%s /etc/nginx/htpasswd/default %s %s', EE_PROXY_TYPE, $flags, $auth->username, $auth->password ) );
				}
			}

			$sites = array_unique(
				array_column(
					Auth::all( [ 'site_url' ] ),
					'site_url'
				)
			);

			foreach ( $sites as $site ) {
				$this->generate_site_auth_files( $site );
			}
		}
	}

	/**
	 * Generates auth files for a site
	 *
	 * @param string $site_url URL of site
	 *
	 * @throws Exception
	 */
	private function generate_site_auth_files( string $site_url ) {
		$site_auth_file = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $site_url;
		$this->fs->remove( $site_auth_file );

		$auths = array_merge(
			Auth::get_global_auths(),
			Auth::where( 'site_url', $site_url )
		);

		foreach ( $auths as $key => $auth ) {
			$flags = 'b';

			if ( $key === 0 ) {
				$flags = 'bc';
			}
			EE::exec( sprintf( 'docker exec %s htpasswd -%s /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $flags, $site_url, $auth->username, $auth->password ) );
		}
	}

	/**
	 * Generates global whitelist file and regeneates all site files
	 *
	 * @throws Exception
	 */
	private function generate_global_whitelist() {
		$this->generate_site_whitelist( 'default' );

		$sites = array_unique(
			array_column(
				Whitelist::all( [ 'site_url' ] ),
				'site_url'
			)
		);
		if ( ( $key = array_search( 'default', $sites ) ) !== false ) {
			unset( $sites[ $key ] );
		}

		foreach ( $sites as $site ) {
			$this->generate_site_whitelist( $site );
		}

	}

	/**
	 * Generates site whitelist files
	 *
	 * @param string $site_url
	 *
	 * @throws Exception
	 */
	private function generate_site_whitelist( string $site_url ) {
		$site_whitelist_file = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $site_url . '_acl';
		$this->fs->remove( $site_whitelist_file );

		$whitelists = array_column(
			'default' === $site_url ? Whitelist::get_global_ips() :
				array_merge(
					Whitelist::get_global_ips(),
					Whitelist::where( 'site_url', $site_url )
				),
			'ip'
		);

		$this->put_ips_to_file( $site_whitelist_file, $whitelists );
	}

	/**
	 * Function to put list of ip's into a file.
	 *
	 * @param string $file Path of file to write ip's in.
	 * @param array  $ips  List of ip's.
	 */
	private function put_ips_to_file( string $file, array $ips ) {

		if ( empty( $ips ) ) {
			return;
		}

		$file_content = 'satisfy any;' . PHP_EOL;
		foreach ( $ips as $ip ) {
			$file_content .= "allow $ip;" . PHP_EOL;
		}
		$file_content .= 'deny all;';
		$this->fs->dumpFile( $file, $file_content );
	}

	/**
	 * Updates http authentication password for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global auth.
	 *
	 * [--user=<user>]
	 * : Username for http auth.
	 *
	 * [--pass=<pass>]
	 * : Password for http auth.
	 *
	 * [--ip=<ip>]
	 * : IP to whitelist.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update auth password on global auth with default username and random password
	 *     $ ee auth update global --user=easyengine
	 *
	 *     # Update auth password on site with predefined username and password
	 *     $ ee auth update example.com --user=test --pass=password
	 *
	 *     # Update whitelisted IPs on site
	 *     $ ee auth update example.com --ip=8.8.8.8,1.1.1.1
	 *
	 *     # Update whitelisted IPs on all sites
	 *     $ ee auth update global --ip=8.8.8.8,1.1.1.1
	 *
	 */
	public function update( $args, $assoc_args ) {

		verify_htpasswd_is_present();

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$ips       = EE\Utils\get_flag_value( $assoc_args, 'ip' );

		if ( $ips ) {
			$this->update_whitelist( $site_url, $ips );
		} else {
			$this->update_auth( $assoc_args, $site_url );
		}
	}

	/**
	 * Update whitelist IPs
	 *
	 * @param array  $assoc_args
	 * @param string $site_url
	 */
	private function update_auth( array $assoc_args, string $site_url ) {
		$user = EE\Utils\get_flag_value( $assoc_args, 'user' );

		if ( ! $user ) {
			EE::error( 'Please provide auth user with --user flag' );
		}

		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		$auths = $this->get_auths( $site_url, $user );

		foreach ( $auths as $auth ) {
			$auth->password = $pass;
			$auth->save();
		}

		if ( 'default' === $site_url ) {
			$this->generate_global_auth_files();
		} else {
			$this->generate_site_auth_files( $site_url );
		}

		EE::log( 'Reloading global reverse proxy.' );
		reload_global_nginx_proxy();

		EE::success( sprintf( 'Auth successfully updated for `%s` scope. New values added:', $this->site_data->site_url ) );
		EE::line( 'User: ' . $user );
		EE::line( 'Pass: ' . $pass );
	}

	/**
	 * Update whitelist IPs
	 *
	 * @param string $site_url
	 * @param string $ips
	 *
	 * @throws Exception
	 */
	private function update_whitelist( string $site_url, string $ips ) {
		$user_ips = $this->clean_and_validate_ips( $ips );

		foreach ( $user_ips as $ip ) {
			$existing_ips = Whitelist::where(
				[
					'site_url' => $site_url,
					'ip'       => $ip,
				]
			);

			if ( ! empty( $existing_ips ) ) {
				EE::log( $existing_ips[0]->ip . " has already been whitelisted on $site_url. Skipping it." );
				continue;
			}

			Whitelist::create(
				[
					'site_url' => $site_url,
					'ip'       => $ip,
				]
			);
		}

		if ( 'default' === $site_url ) {
			$this->generate_global_whitelist();
		} else {
			$this->generate_site_whitelist( $site_url );
		}

		reload_global_nginx_proxy();

	}

	/**
	 * Gets all the authentication objects from db.
	 *
	 * @param string $site_url       Site URL.
	 * @param string $user           User for which the auth need to be fetched.
	 * @param bool   $error_if_empty Exit if auth is not present
	 *
	 * @return array Array of auth models.
	 * @throws Exception
	 */
	private function get_auths( $site_url, $user, $error_if_empty = true ) {

		$where_conditions = [
			'site_url' => $site_url,
		];

		$user_error_msg = '';
		if ( $user ) {
			$where_conditions['username'] = $user;
			$user_error_msg               = ' with username: ' . $user;
		}

		$auths = Auth::where( $where_conditions );

		if ( empty( $auths ) && $error_if_empty ) {
			$site = ( 'default' === $site_url ) ? 'global' : $site_url;
			EE::error( sprintf( 'Auth%s does not exists on %s', $user_error_msg, $site ) );
		}

		return $auths;
	}

	/**
	 * Deletes http authentication for a site.
	 *
	 * Default: removes http authentication from site. If `--user` is passed it removes that specific user.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--user=<user>]
	 * : Username that needs to be deleted.
	 *
	 * [--ip]
	 * : IP to remove. Default removes all.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove auth on site and its admin tools with default username(easyengine)
	 *     $ ee auth delete example.com
	 *
	 *     # Remove auth on site and its admin tools with custom username
	 *     $ ee auth delete example.com --user=example
	 *
	 *     # Remove global auth on all sites (but not admin tools) with default username(easyengine)
	 *     $ ee auth delete global
	 *
	 *     # Remove specific whitelisted IPs on site
	 *     $ ee auth delete example.com --ip=1.1.1.1,8.8.8.8
	 *
	 *     # Remove all whitelisted IPs on site
	 *     $ ee auth delete example.com --ip
	 *
	 *     # Remove whitelisted IPs on all sites
	 *     $ ee auth delete global --ip=1.1.1.1
	 *
	 */
	public function delete( $args, $assoc_args ) {

		verify_htpasswd_is_present();

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$ip       = EE\Utils\get_flag_value( $assoc_args, 'ip' );

		if ( ! $ip ) {
			$user = EE\Utils\get_flag_value( $assoc_args, 'user' );
			$auths = $this->get_auths( $site_url, $user );

			foreach ( $auths as $auth ) {
				$auth->delete();
			}

			if ( 'default' === $site_url ) {
				$this->generate_global_auth_files();
			} else {
				$this->generate_site_auth_files( $site_url );
			}

			if ( $user ) {
				$success_message = sprintf( 'http auth successfully removed on %s.', $site_url );
			} else {
				$success_message = sprintf( 'http auth successfully removed on %s on %s user', $site_url, $user );
			}

			EE::success( $success_message );
			EE::log( 'Reloading global reverse proxy.' );
			reload_global_nginx_proxy();
		} else {

			if ( true === $ip ) {
				$whitelists = Whitelist::where( [
					'site_url' => $site_url,
				] );

				foreach ( $whitelists as $whitelist ) {
					$whitelist->delete();
				}
			} else {
				$user_ips = $this->clean_and_validate_ips( $ip );

				foreach ( $user_ips as $ip ) {
					$existing_ips = Whitelist::where(
						[
							'site_url' => $site_url,
							'ip'       => $ip,
						]
					);

					if ( empty( $existing_ips ) ) {
						EE::log( $ip . " has not been whitelisted on $site_url. Skipping it." );
						continue;
					}

					$whitelist = Whitelist::where(
						[
							'site_url' => $site_url,
							'ip'       => $ip,
						]
					);

					$whitelist[0]->delete();
				}
			}

			if ( 'default' === $site_url ) {
				$this->generate_global_whitelist();
			} else {
				$this->generate_site_whitelist( $site_url );
			}

			reload_global_nginx_proxy();
		}
	}

	/**
	 * Lists http authentication users of a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website / `global` for global scope.
	 *
	 * [--ip]
	 * : Show whitelisted IPs of site.
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
	 *     # List all auths across all sites
	 *     $ ee auth list
	 *
	 */
	public function list( $args, $assoc_args ) {

		// Display all the auths if `ee auth list' is run from outside of site directory.
		if ( empty( $args ) && ! get_site_name() ) {
			$this->display_all_auths();
			return;
		}

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$ip       = \EE\Utils\get_flag_value( $assoc_args, 'ip' );

		if ( $ip ) {
			$whitelists = Whitelist::where( 'site_url', $site_url );

			$formatter = new EE\Formatter( $assoc_args, [ 'ip' ] );
			$formatter->display_items( $whitelists );
		} else {
			$log_msg          = '';
			$auths_global     = Auth::get_global_admin_tools_auth();
			$admin_tools_auth = true;
			if ( empty( $auths_global ) ) {
				$auths_global     = Auth::get_global_auths();
				$admin_tools_auth = false;
			}

			if ( empty( $auths_global ) ) {
				EE::error( 'Auth does not exists on global.' );
			}
			$format = \EE\Utils\get_flag_value( $assoc_args, 'format' );
			if ( 'table' === $format ) {
				$log_msg = $admin_tools_auth ? 'Following auth is applied only on admin-tools.' : 'Following global auth is enabled on server.';
			}
			if ( 'default' !== $site_url ) {
				$auths = $this->get_auths( $site_url, false, false );
				if ( empty( $auths ) ) {
					EE::warning( sprintf( 'Auth does not exists on %s', $site_url ) );
				} else {
					$formatter = new EE\Formatter( $assoc_args, [ 'username', 'password' ] );
					$formatter->display_items( $auths );
				}
			}
			if ( ! empty( $log_msg ) ) {
				EE::log( PHP_EOL . $log_msg );
			}
			if ( ! empty( $auths_global ) ) {
				$formatter = new EE\Formatter( $assoc_args, [ 'username', 'password' ] );
				$formatter->display_items( $auths_global );
			}
		}
	}

	/**
	 * This will display all the auths of all the sites
	 *
	 * @return void
	 */
	private function display_all_auths() {

		// Fetch all the auths across all the sites.
		$sites = Auth::all();
		if ( empty( $sites ) ) {
			EE::error( 'No auths exits on any sites' );
		}
		$formatter = new EE\Formatter( $assoc_args, array( 'sitename', 'username', 'password' ) );

		// Add a field named 'sitename' to the array , so that it can be displayed as heading in output.
		$result = array_map(
			function ( $site ) {
				$site->sitename = $site->site_url;
				return $site;
			},
			$sites
		);

		// Sorts the $result array to make sure that same sites appear together.
		usort(
			$result,
			function ( $item1, $item2 ) {
				return strcmp( $item1->sitename, $item2->sitename );
			}
		);

		$formatter->display_items( $result );
	}

}

