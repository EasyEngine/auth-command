<?php

if ( ! class_exists( 'EE' ) ) {
	return;
}

use EE\Model\Auth;
use EE\Model\Site;
use EE\Model\Whitelist;
use function EE\Auth\Utils\get_site_auth_domains;
use function EE\Auth\Utils\remove_auth_files;

/**
 * Hook to cleanup auth entries, whitelisted ips and their files if any.
 *
 * @param string $site_url The site to be cleaned up.
 */
function cleanup_auth_and_whitelist( $site_url ) {

	$site = Site::find( $site_url );

	if ( ! $site ) {
		return;
	}

	foreach ( Auth::where( [ 'site_url' => $site_url ] ) as $auth ) {
		$auth->delete();
	}

	foreach ( Whitelist::where( [ 'site_url' => $site_url ] ) as $whitelist ) {
		$whitelist->delete();
	}

	// Files may exist without site entries (e.g. left by older versions), so always remove them.
	remove_auth_files( get_site_auth_domains( $site_url, $site ) );

	\EE\Site\Utils\reload_global_nginx_proxy();
}

EE::add_hook( 'site_cleanup', 'cleanup_auth_and_whitelist' );
