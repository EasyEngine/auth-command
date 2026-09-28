<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Site;
use function EE\Auth\Utils\generate_site_auth_files;
use function EE\Auth\Utils\generate_site_whitelist;
use function EE\Auth\Utils\get_wildcard_staging_dir;
use function EE\Auth\Utils\proxy_has_acl_template;

class RegenerateSiteAuthFiles extends Base {

	private $sites;

	/** @var string|null Backup of `htpasswd/` and `vhost.d/*_acl` taken by up(). */
	private $auth_backup;

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

		$this->auth_backup = $this->backup_auth_files();

		$new_template = proxy_has_acl_template();
		$stage        = get_wildcard_staging_dir();
		$this->fs->remove( $stage );

		// The old template applies `_wildcard.X` to every subdomain of X, even on a container event without a reload, so those files wait outside its mounts.
		if ( ! $new_template ) {
			$this->fs->mkdir( [ $stage, "$stage/htpasswd", "$stage/vhost.d" ], 0700 );
		}

		foreach ( $this->sites as $site ) {
			try {
				generate_site_auth_files( $site->site_url, $site, [], $new_template ? '' : $stage );
				generate_site_whitelist( $site->site_url, $site, [], $new_template ? '' : $stage );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not regenerate the auth files of %s: %s', $site->site_url, $e->getMessage() ) );
			}
		}

		if ( $new_template ) {
			\EE\Site\Utils\reload_global_nginx_proxy();
		} else {
			// Promoted by the after_docker_image_migration hook, or on a later run, once the new proxy runs.
			EE::debug( "Staged the wildcard auth files in $stage; nginx-proxy runs the old template." );
		}
	}

	/**
	 * Discards the staged wildcard files and restores the files saved by up() exactly, including removing files it added.
	 *
	 * @throws \Exception
	 */
	public function down() {

		$this->fs->remove( get_wildcard_staging_dir() );

		if ( empty( $this->auth_backup ) || ! is_dir( $this->auth_backup ) ) {
			EE::debug( 'No site auth files backup to restore.' );

			return;
		}

		foreach ( $this->get_auth_dirs() as $name => $dir ) {
			$this->restore_dir( $this->auth_backup . '/' . $name, $dir, 'vhost.d' === $name );
		}

		if ( 'running' === \EE_DOCKER::container_status( EE_PROXY_TYPE ) ) {
			\EE\Site\Utils\reload_global_nginx_proxy();
		}

		$this->fs->remove( $this->auth_backup );
		EE::debug( 'Restored the site auth files from ' . $this->auth_backup );
		$this->auth_backup = null;
	}

	/**
	 * @return array Backup subdirectory name => proxy directory.
	 */
	private function get_auth_dirs() {

		return [
			'htpasswd' => EE_ROOT_DIR . '/services/nginx-proxy/htpasswd',
			'vhost.d'  => EE_ROOT_DIR . '/services/nginx-proxy/vhost.d',
		];
	}

	/**
	 * Only `*_acl` files of vhost.d are written by auth-command.
	 *
	 * @param string $file     File name.
	 * @param bool   $acl_only Whether only ACL files are handled.
	 *
	 * @return bool
	 */
	private function is_handled_file( string $file, bool $acl_only ) {

		return ! $acl_only || '_acl' === substr( $file, -4 );
	}

	/**
	 * Copies `htpasswd/` and `vhost.d/*_acl` to a new directory under EE_BACKUP_DIR.
	 *
	 * Kept after a successful upgrade: downgrading needs these files, the old nginx-proxy leaks the new `_wildcard.X` files to sibling sites.
	 *
	 * @return string Backup directory.
	 * @throws \Exception
	 */
	private function backup_auth_files() {

		$backup = EE_BACKUP_DIR . '/auth-migration-' . date( 'Ymd-His' );
		if ( file_exists( $backup ) ) {
			$backup .= '-' . getmypid();
		}

		try {
			// Holds password hashes.
			$this->fs->mkdir( $backup, 0700 );
			foreach ( $this->get_auth_dirs() as $name => $dir ) {
				$this->fs->mkdir( $backup . '/' . $name, 0700 );
				if ( ! is_dir( $dir ) ) {
					continue;
				}
				foreach ( scandir( $dir ) as $file ) {
					if ( ! is_file( $dir . '/' . $file ) || ! $this->is_handled_file( $file, 'vhost.d' === $name ) ) {
						continue;
					}
					$this->copy_file( $dir . '/' . $file, $backup . '/' . $name . '/' . $file );
				}
			}
		} catch ( \Throwable $e ) {
			$this->fs->remove( $backup );
			throw new \Exception( 'Could not back up the site auth files: ' . $e->getMessage() );
		}

		EE::debug( "Backed up the site auth files to $backup" );

		return $backup;
	}

	/**
	 * Makes the handled files of $dir identical to $backup_dir.
	 *
	 * @param string $backup_dir Backup directory.
	 * @param string $dir        Proxy directory.
	 * @param bool   $acl_only   Whether only ACL files are handled.
	 */
	private function restore_dir( string $backup_dir, string $dir, bool $acl_only ) {

		if ( ! is_dir( $dir ) || ! is_dir( $backup_dir ) ) {
			return;
		}

		foreach ( scandir( $dir ) as $file ) {
			if ( is_file( $dir . '/' . $file ) && $this->is_handled_file( $file, $acl_only ) && ! file_exists( $backup_dir . '/' . $file ) ) {
				$this->fs->remove( $dir . '/' . $file );
			}
		}

		foreach ( scandir( $backup_dir ) as $file ) {
			if ( ! is_file( $backup_dir . '/' . $file ) ) {
				continue;
			}
			// Renamed into place so the proxy never reads a partial file.
			$tmp = $dir . '/.' . $file . '.restore';
			$this->copy_file( $backup_dir . '/' . $file, $tmp );
			$this->fs->rename( $tmp, $dir . '/' . $file, true );
		}
	}

	/**
	 * Copies a file with its mode and modification time.
	 *
	 * @param string $source Source path.
	 * @param string $target Target path.
	 */
	private function copy_file( string $source, string $target ) {

		$this->fs->copy( $source, $target, true );
		$this->fs->chmod( $target, fileperms( $source ) & 0777 );
		$this->fs->touch( $target, filemtime( $source ) );
	}
}
