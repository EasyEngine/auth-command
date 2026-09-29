<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use function EE\Auth\Utils\is_valid_whitelist_ip;

class DropInvalidWhitelistEntriesFromAclFiles extends Base {

	/**
	 * @var array ACL files with an entry nginx rejects, mapped to those entries.
	 */
	private $files = [];

	public function __construct() {

		parent::__construct();

		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		foreach ( glob( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/*_acl' ) ?: [] as $file ) {
			$invalid = array_filter(
				self::allowed_entries( (string) file_get_contents( $file ) ),
				function ( $entry ) {
					return ! is_valid_whitelist_ip( $entry );
				}
			);
			if ( $invalid ) {
				$this->files[ $file ] = $invalid;
			}
		}

		$this->skip_this_migration = empty( $this->files );
	}

	/**
	 * Removes the entries nginx rejects from the `_acl` files: older versions wrote them unchecked, and such a file fails every proxy reload and restart.
	 *
	 * The files are edited in place instead of regenerated, so no new file is written while an older nginx-proxy runs.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping the whitelist entries check: every _acl file is valid.' );

			return;
		}

		foreach ( $this->files as $file => $invalid ) {
			$lines = array_filter(
				explode( "\n", (string) file_get_contents( $file ) ),
				function ( $line ) {
					return ! preg_match( '/^allow (.*);\r?$/', $line, $m ) || is_valid_whitelist_ip( $m[1] );
				}
			);

			try {
				$this->fs->dumpFile( $file, implode( "\n", $lines ) );
			} catch ( \Exception $e ) {
				EE::warning( sprintf( 'Could not rewrite %s: %s', $file, $e->getMessage() ) );
				continue;
			}

			EE::warning( sprintf( "Removed the invalid whitelist entries '%s' from %s, as nginx rejects them. They are still stored: delete them with `ee auth delete <site> --ip=<entry>`.", implode( "', '", $invalid ), $file ) );
		}

		\EE\Site\Utils\reload_global_nginx_proxy();
	}

	/**
	 * Not reverted: the removed entries made the file invalid.
	 */
	public function down() {
	}

	/**
	 * @param string $content ACL file content.
	 *
	 * @return array Entries of its `allow` lines.
	 */
	private static function allowed_entries( string $content ): array {

		preg_match_all( '/^allow (.*);\r?$/m', $content, $m );

		return $m[1];
	}
}
