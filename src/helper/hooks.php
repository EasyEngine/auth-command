<?php

if ( ! class_exists( 'EE' ) ) {
	return;
}

use EE\Model\Auth;
use EE\Model\Site;
use EE\Model\Whitelist;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Hook to cleanup auth entries and whitelisted ips if any.
 *
 * @param string $site_url The site to be cleaned up.
 */
function cleanup_auth_and_whitelist( $site_url ) {

	if ( ! Site::find( $site_url ) ) {
		return;
	}

	$fs = new Filesystem();

	$auths = Auth::where( [ 'site_url' => $site_url ] );

	if ( ! empty( $auths ) ) {
		foreach ( $auths as $auth ) {
			$auth->delete();
		}

		$site_auth_file = EE_ROOT_DIR . '/services/nginx-proxy/htpasswd/' . $site_url;
		$fs->remove( $site_auth_file );
	}

	$whitelists = Whitelist::where( [ 'site_url' => $site_url ] );

	if ( ! empty( $whitelists ) ) {
		foreach ( $whitelists as $whitelist ) {
			$whitelist->delete();
		}

		$site_whitelist_file = EE_ROOT_DIR . '/services/nginx-proxy/vhost.d/' . $site_url . '_acl';
		$fs->remove( $site_whitelist_file );
	}

	\EE\Site\Utils\reload_global_nginx_proxy();
}

EE::add_hook( 'site_cleanup', 'cleanup_auth_and_whitelist' );
