<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Whitelist;
use function EE\Auth\Utils\is_valid_whitelist_ip;
use function EE\Auth\Utils\normalize_stored_whitelist_ip;
use function EE\Auth\Utils\warn_invalid_stored_whitelist_ip;

class DropInvalidWhitelistEntriesFromAclFiles extends Base {

	/**
	 * @var array ACL files to fix, mapped to their new content and the entries removed from them.
	 */
	private $files = [];

	public function __construct() {

		parent::__construct();

		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		foreach ( glob( EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/*_acl' ) ?: [] as $file ) {
			$content = file_get_contents( $file );
			if ( false === $content ) {
				continue;
			}
			$removed = [];
			$lines   = [];
			foreach ( explode( "\n", $content ) as $line ) {
				if ( ! preg_match( '/^allow (.*);(\r?)$/', $line, $m ) || is_valid_whitelist_ip( $m[1] ) ) {
					$lines[] = $line;
					continue;
				}
				// Entries nginx accepts, e.g. with a prefix leading zero, are kept in a valid form.
				$entry = normalize_stored_whitelist_ip( $m[1] );
				if ( is_valid_whitelist_ip( $entry ) ) {
					$lines[] = "allow $entry;$m[2]";
				} else {
					$removed[] = $m[1];
				}
			}
			$new_content = implode( "\n", $lines );
			if ( $new_content !== $content ) {
				$this->files[ $file ] = [ $new_content, $removed ];
			}
		}

		$this->skip_this_migration = empty( $this->files );
	}

	/**
	 * Removes the invalid entries from the `_acl` files: older versions wrote them unchecked, and nginx fails every reload and restart on most of them.
	 *
	 * The files are edited in place instead of regenerated, so no new file is written while an older nginx-proxy runs.
	 *
	 * @throws \Exception When a file can't be rewritten, so the upgrade stops before the image migration recreates the proxy on it.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping the whitelist entries check: every _acl file is valid.' );

			return;
		}

		$removed = [];
		try {
			foreach ( $this->files as $file => list( $content, $entries ) ) {
				$this->fs->dumpFile( $file, $content );
				if ( $entries ) {
					$removed[ implode( "', '", $entries ) ][] = basename( $file );
				} else {
					EE::debug( "Normalized the whitelist entries of $file" );
				}
			}
		} finally {
			// Also after a failed write: a retry no longer sees the files already fixed.
			foreach ( $removed as $entries => $files ) {
				EE::warning( sprintf( "Removed the invalid whitelist entries '%s' from %s in %s.", $entries, implode( ', ', $files ), EE_ROOT_DIR . '/services/nginx-proxy/vhost.d' ) );
			}
		}

		// The rows stay stored; name the command that removes each one.
		foreach ( Whitelist::all() as $row ) {
			if ( ! is_valid_whitelist_ip( normalize_stored_whitelist_ip( (string) $row->ip ) ) ) {
				warn_invalid_stored_whitelist_ip( $row->site_url, $row->ip );
			}
		}

		\EE\Site\Utils\reload_global_nginx_proxy();
	}

	/**
	 * Not reverted: the removed entries made the file invalid.
	 */
	public function down() {
	}
}
