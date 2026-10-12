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
use function EE\Auth\Utils\generate_site_auth_files;
use function EE\Auth\Utils\generate_site_whitelist;
use function EE\Auth\Utils\is_valid_whitelist_ip;
use function EE\Auth\Utils\verify_htpasswd_is_present;
use function EE\Auth\Utils\write_htpasswd_file;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\is_reserved_proxy_file_name;
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
	 * Converts input separated by comma, whitespace and new-lines in an array of unique entries
	 *
	 * @param string $ips      IPs to clean and validate
	 * @param string $site_url If set, entries already stored for it are accepted, so ones saved by older versions can be deleted.
	 *
	 * @return array $user_ips Cleaned IP addresses.
	 */
	private function clean_and_validate_ips( string $ips, string $site_url = '' ) {

		// Unique, as a repeated entry would hit the UNIQUE(site_url, ip) constraint.
		$user_ips = array_values( array_unique( preg_split( '/[\s,]+/', trim( $ips ) ) ) );

		foreach ( $user_ips as $ip ) {

			if ( is_valid_whitelist_ip( $ip ) ) {
				continue;
			}

			$stored = '' !== $site_url && '' !== $ip && Whitelist::where(
				[
					'site_url' => $site_url,
					'ip'       => $ip,
				]
			);

			if ( $stored ) {
				continue;
			}

			EE::error(
				sprintf(
					'Please check your list do not have any empty or wrong IP addresses. Invalid entry: %s. Use an IPv4 or IPv6 address, optionally with a CIDR prefix of 0-32 (IPv4) or 0-128 (IPv6), e.g. 192.0.2.0/24 or 2001:db8::/32.',
					'' === $ip ? 'an empty one' : "'$ip'"
				)
			);
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

		Auth::create( $auth_data );

		if ( 'default' === $site_url ) {
			$this->generate_global_auth_files();
		} else {
			generate_site_auth_files( $site_url, $this->site_data );
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
			generate_site_whitelist( $site_url, $this->site_data );
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
		if ( ! $this->admin_tools_in_use() ) {
			return;
		}
		EE::log( 'Creating new auth for admin-tools only.' );
		\EE\Auth\Utils\init_global_admin_tools_auth();
	}

	/**
	 * Whether any site has admin-tools or mailhog enabled, which are served behind the admin-tools auth.
	 *
	 * @return bool
	 */
	private function admin_tools_in_use() {
		return ! empty( \EE\Model\Site::where( 'admin_tools', '1' ) ) || ! empty( \EE\Model\Site::where( 'mailhog_enabled', '1' ) );
	}

	/**
	 * Keeps the admin-tools login when the last global auth user goes and admin-tools have no auth of their own.
	 *
	 * Admin tools then fall back to the global auth. Its `easyengine` user (older versions moved the admin-tools user there)
	 * becomes the admin-tools auth instead of being deleted, so saved admin-tools credentials keep working.
	 *
	 * @param Auth[] $auths Global auth rows about to be deleted.
	 *
	 * @return Auth[] The rows to delete.
	 * @throws Exception
	 */
	private function keep_admin_tools_login( array $auths ) {
		if ( ! empty( Auth::get_global_admin_tools_auth() ) || count( $auths ) < count( Auth::get_global_auths() ) || ! $this->admin_tools_in_use() ) {
			return $auths;
		}

		foreach ( $auths as $i => $auth ) {
			if ( 'easyengine' === $auth->username ) {
				$auth->site_url = 'default_admin_tools';
				$auth->save();
				EE::log( 'The easyengine user no longer protects the sites and stays as the admin-tools auth.' );
				unset( $auths[ $i ] );
				break;
			}
		}

		return $auths;
	}

	/**
	 * Generates auth files for global auth and all sites.
	 *
	 * @throws Exception
	 */
	private function generate_global_auth_files() {

		$dir                     = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd';
		$global_admin_tools_auth = Auth::get_global_admin_tools_auth();
		$auths                   = Auth::get_global_auths();

		// Admin tools use default_admin_tools when it exists and fall back to `default` otherwise; the global auth never changes their users.
		$admin_tools_file = ! empty( $global_admin_tools_auth ) && ( write_htpasswd_file( 'default_admin_tools', $global_admin_tools_auth ) || is_file( $dir . '/default_admin_tools' ) );

		if ( empty( $auths ) ) {
			if ( empty( $global_admin_tools_auth ) ) {
				$this->fs->remove( [ $dir . '/default', $dir . '/default_admin_tools' ] );
				$this->regen_admin_tools_auth();
			} elseif ( $admin_tools_file ) {
				$this->fs->remove( $dir . '/default' );
			} elseif ( is_file( $dir . '/default' ) ) {
				// Without either file the proxy would serve admin tools without auth.
				EE::warning( 'Kept htpasswd/default, as htpasswd/default_admin_tools could not be written; run the command again.' );
			}
		} elseif ( write_htpasswd_file( 'default', $auths ) && empty( $global_admin_tools_auth ) ) {
			// A default_admin_tools file without rows is stale; drop it only once `default` is written, so admin tools stay protected.
			$this->fs->remove( $dir . '/default_admin_tools' );
		}

		$sites = array_unique(
			array_column(
				Auth::all( [ 'site_url' ] ),
				'site_url'
			)
		);

		foreach ( $sites as $site ) {
			// The global files were handled above.
			if ( ! is_reserved_proxy_file_name( $site ) ) {
				generate_site_auth_files( $site, \EE\Model\Site::find( $site ) ?: null );
			}
		}
	}

	/**
	 * Generates global whitelist file and regeneates all site files
	 *
	 * @throws Exception
	 */
	private function generate_global_whitelist() {
		generate_site_whitelist( 'default' );

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
			generate_site_whitelist( $site, \EE\Model\Site::find( $site ) ?: null );
		}

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
	 *     # Set a new random password for the admin-tools user (a global user named easyengine is updated instead, if there is one)
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

		$auths = $this->get_auths( $site_url, $user, 'default' !== $site_url );
		if ( empty( $auths ) ) {
			// `global` also covers the admin-tools user, as in the help example.
			$auths = Auth::where( [ 'site_url' => 'default_admin_tools', 'username' => $user ] ) ?: $this->get_auths( $site_url, $user );
		}

		foreach ( $auths as $auth ) {
			$auth->password = $pass;
			$auth->save();
		}

		if ( 'default' === $site_url ) {
			$this->generate_global_auth_files();
		} else {
			generate_site_auth_files( $site_url, $this->site_data );
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
			generate_site_whitelist( $site_url, $this->site_data );
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
			if ( 'default' === $site_url ) {
				$auths = $this->keep_admin_tools_login( $auths );
			}

			foreach ( $auths as $auth ) {
				$auth->delete();
			}

			if ( 'default' === $site_url ) {
				$this->generate_global_auth_files();
			} else {
				generate_site_auth_files( $site_url, $this->site_data );
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
				$user_ips = $this->clean_and_validate_ips( $ip, $site_url );

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
				generate_site_whitelist( $site_url, $this->site_data );
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
	 */
	public function list( $args, $assoc_args ) {

		$global   = $this->populate_info( $args, __FUNCTION__ );
		$site_url = $global ? 'default' : $this->site_data->site_url;
		$ip       = \EE\Utils\get_flag_value( $assoc_args, 'ip' );

		if ( $ip ) {
			$whitelists = Whitelist::where( 'site_url', $site_url );

			$formatter = new EE\Formatter( $assoc_args, [ 'ip' ] );
			$formatter->display_items( $whitelists );
		} else {
			$auths_global = array_merge( Auth::get_global_admin_tools_auth(), Auth::get_global_auths() );

			if ( empty( $auths_global ) ) {
				EE::error( 'Auth does not exists on global.' );
			}
			foreach ( $auths_global as $auth ) {
				$auth->site_url === 'default_admin_tools' ? $auth->site_url = 'Admin Tools Auth' : $auth->site_url = 'Global Auth';
			}
			$auths = [];
			$auth  = array_merge( $auths, $auths_global );
			if ( 'default' !== $site_url ) {
				$auths = $this->get_auths( $site_url, false, false );
				if ( ! empty( $auths ) ) {
					$auth = array_merge( $auths, $auths_global );
				}
			}
			$formatter = new EE\Formatter( $assoc_args, [ 'site_url', 'username', 'password' ] );
			$formatter->display_items( $auth );
		}
	}

}

