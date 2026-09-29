<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;
use EE\Model\Whitelist;
use function EE\Auth\Utils\generate_site_whitelist;
use function EE\Auth\Utils\is_valid_whitelist_ip;

class DropInvalidWhitelistEntriesFromAclFiles extends Base {

	private $site_urls = [];

	public function __construct() {

		parent::__construct();

		$rows    = $this->is_first_execution ? [] : Whitelist::all();
		$invalid = array_filter(
			$rows,
			function ( $row ) {
				return ! is_valid_whitelist_ip( (string) $row->ip );
			}
		);

		if ( empty( $invalid ) ) {
			$this->skip_this_migration = true;

			return;
		}

		$scopes = array_unique( array_column( $invalid, 'site_url' ) );
		// Global entries are merged into every site's file.
		$this->site_urls = in_array( 'default', $scopes, true ) ? array_unique( array_column( $rows, 'site_url' ) ) : $scopes;
	}

	/**
	 * Rewrites the `_acl` files that carry an invalid entry, which older versions stored unchecked: nginx rejects such a file, so proxy reloads and restarts fail.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping whitelist entries check as all entries are valid.' );

			return;
		}

		foreach ( $this->site_urls as $site_url ) {
			try {
				generate_site_whitelist( $site_url, 'default' === $site_url ? null : ( Site::find( $site_url ) ?: null ) );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not rewrite the whitelist files of %s: %s', $site_url, $e->getMessage() ) );
			}
		}

		\EE\Site\Utils\reload_global_nginx_proxy();
	}

	/**
	 * Not reverted: the rewritten files only lack entries nginx rejects.
	 */
	public function down() {
	}
}
