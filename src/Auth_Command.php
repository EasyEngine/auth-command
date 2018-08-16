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

use \Symfony\Component\Filesystem\Filesystem;

class Auth_Command extends EE_Command {

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var array $site Associative array containing essential site related information.
	 */
	private $site;

	private $db;

	public function __construct() {

		$this->fs = new Filesystem();
		$this->db = EE::db();
	}


	/**
	 * Creates/Updates http auth for a site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to be secured.
	 *
	 * [--user=<user>]
	 * : Username for http auth.
	 *
	 * [--pass=<pass>]
	 * : Password for http auth
	 *
	 * @alias update
	 */
	public function create( $args, $assoc_args ) {

		$args = EE\SiteUtils\auto_site_name( $args, 'auth', __FUNCTION__ );
		$this->populate_site_info( $args );

		EE::debug( sprintf( 'ee auth start, Site: %s', $this->site['name'] ) );

		$user = EE\Utils\get_flag_value( $assoc_args, 'user', 'easyengine' );
		$pass = EE\Utils\get_flag_value( $assoc_args, 'pass', EE\Utils\random_password() );

		EE::debug( 'Verifying htpasswd is present.' );
		$check_htpasswd_present = EE::exec( sprintf( 'docker exec %s sh -c \'command -v htpasswd\'', EE_PROXY_TYPE ) );
		if ( ! $check_htpasswd_present ) {
			EE::error( sprintf( 'Could not find apache2-utils installed in %s.', EE_PROXY_TYPE ) );
		}
		$params = $this->fs->exists( EE_CONF_ROOT . '/nginx/htpasswd/' . $this->site['name'] ) ? 'b' : 'bc';
		EE::exec( sprintf( 'docker exec %s htpasswd -%s /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $params, $this->site['name'], $user, $pass ) );

		EE::log( 'Reloading global reverse proxy.' );
		$this->reload();

		EE::log( sprintf( 'Auth successfully updated for %s scope. New values added/updated:', $this->site['name'] ) );
		EE::log( 'User:' . $user );
		EE::log( 'Pass:' . $pass );
	}

	/**
	 * Deletes http auth for a site. Default: removes http auth from site. If `--user` is passed it removes that
	 * specific user.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website.
	 *
	 * [--user=<user>]
	 * : Username that needs to be deleted.
	 */
	public function delete( $args, $assoc_args ) {

		$args = EE\SiteUtils\auto_site_name( $args, 'auth', __FUNCTION__ );
		$this->populate_site_info( $args );
		$user = EE\Utils\get_flag_value( $assoc_args, 'user' );

		if ( $user ) {
			EE::exec( sprintf( 'docker exec %s htpasswd -D /etc/nginx/htpasswd/%s %s', EE_PROXY_TYPE, $this->site['name'], $user ), true, true );
		} else {
			$this->fs->remove( EE_CONF_ROOT . '/nginx/htpasswd/' . $this->site['name'] );
			EE::log( sprintf( 'http auth removed for %s', $this->site['name'] ) );
		}
		$this->reload();
	}

	/**
	 * Function to reload the global reverse proxy to update the effect of changes done.
	 */
	private function reload() {

		EE::exec( sprintf( 'docker exec %s sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf; /usr/sbin/nginx -s reload"', EE_PROXY_TYPE ) );
	}

	/**
	 * Populate basic site info from db.
	 */
	private function populate_site_info( $args ) {

		$this->site['name'] = EE\Utils\remove_trailing_slash( $args[0] );

		if ( EE::db()::site_in_db( $this->site['name'] ) ) {

			$db_select = EE::db()::select( [], [ 'sitename' => $this->site['name'] ], 'sites', 1 );

			$this->site['type']    = $db_select['site_type'];
			$this->site['root']    = $db_select['site_path'];
			$this->site['command'] = $db_select['site_command'];
		} else {
			EE::error( sprintf( 'Site %s does not exist.', $this->site['name'] ) );
		}
	}
}
