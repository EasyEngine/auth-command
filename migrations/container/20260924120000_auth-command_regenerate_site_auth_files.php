<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;
use function EE\Auth\Utils\generate_site_auth_files;
use function EE\Auth\Utils\generate_site_whitelist;

class RegenerateSiteAuthFiles extends Base {

	private $sites;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Regenerate the htpasswd and ACL files of all sites.
	 *
	 * Older versions only wrote `htpasswd/<site>` and `<site>_acl`, leaving subdomains and alias domains unprotected, and kept files of sites without own entries.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping site auth files regeneration migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			try {
				generate_site_auth_files( $site->site_url, $site );
				generate_site_whitelist( $site->site_url, $site );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not regenerate the auth files of %s: %s', $site->site_url, $e->getMessage() ) );
			}
		}

		\EE\Site\Utils\reload_global_nginx_proxy();
	}

	/**
	 * Nothing to revert: the regenerated files are also valid for the previous version.
	 */
	public function down() {
	}
}
