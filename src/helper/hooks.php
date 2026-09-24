<?php

if ( ! class_exists( 'EE' ) ) {
	return;
}

use EE\Model\Auth;
use EE\Model\Site;
use EE\Model\Whitelist;
use function EE\Auth\Utils\generate_site_auth_files;
use function EE\Auth\Utils\generate_site_whitelist;
use function EE\Auth\Utils\get_auth_domain;
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

/**
 * Hook to sync auth and whitelist files with the alias domains of a site after they change.
 *
 * @param string $site_url        The site whose alias domains changed.
 * @param array  $added_domains   Alias domains that were added.
 * @param array  $removed_domains Alias domains that were removed.
 */
function update_auth_on_alias_domains_change( $site_url, $added_domains = [], $removed_domains = [] ) {

	$site = Site::find( $site_url );

	if ( ! $site ) {
		return;
	}

	$reload  = false;
	$removed = [];

	foreach ( (array) $removed_domains as $domain ) {
		$removed[] = get_auth_domain( trim( $domain ) );
	}

	// Keep files the site still uses, e.g. _wildcard.<site> of a subdomain multisite.
	$removed = array_diff( $removed, get_site_auth_domains( $site_url, $site ) );

	if ( ! empty( $removed ) ) {
		remove_auth_files( $removed );
		$reload = true;
	}

	if ( ! empty( Auth::where( 'site_url', $site_url ) ) ) {
		generate_site_auth_files( $site_url, $site );
		$reload = true;
	}

	if ( Whitelist::has_ips( $site_url ) ) {
		generate_site_whitelist( $site_url, $site );
		$reload = true;
	}

	if ( $reload ) {
		\EE\Site\Utils\reload_global_nginx_proxy();
	}
}

EE::add_hook( 'site_cleanup', 'cleanup_auth_and_whitelist' );
EE::add_hook( 'site_alias_domains_updated', 'update_auth_on_alias_domains_change' );
