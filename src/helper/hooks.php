<?php

if ( ! class_exists( 'EE' ) ) {
	return;
}

use EE\Model\Auth;
use EE\Model\Site;
use EE\Model\Whitelist;
use function EE\Auth\Utils\add_site_auth_files;
use function EE\Auth\Utils\generate_site_auth_files;
use function EE\Auth\Utils\generate_site_whitelist;
use function EE\Auth\Utils\get_alias_auth_domains;
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

	$rows = array_merge( Auth::where( [ 'site_url' => $site_url ] ), Whitelist::where( [ 'site_url' => $site_url ] ) );

	foreach ( $rows as $row ) {
		$row->delete();
	}

	// Files may exist without site entries (e.g. left by older versions), so always remove them.
	$removed = remove_auth_files( get_site_auth_domains( $site_url, $site ) );

	if ( $removed || ! empty( $rows ) ) {
		\EE\Site\Utils\reload_global_nginx_proxy();
	}
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

	$reload = false;

	// Keep files the site still uses, e.g. _wildcard.<site> of a subdomain multisite.
	$removed = array_diff( get_alias_auth_domains( (array) $removed_domains ), get_site_auth_domains( $site_url, $site ) );

	if ( remove_auth_files( $removed ) ) {
		$reload = true;
	}

	// The added domains got their files before the update; regenerate them from the saved site to reconcile.
	if ( ! empty( $added_domains ) ) {
		if ( ! empty( Auth::where( 'site_url', $site_url ) ) ) {
			generate_site_auth_files( $site_url, $site );
			$reload = true;
		}

		if ( Whitelist::has_ips( $site_url ) ) {
			generate_site_whitelist( $site_url, $site );
			$reload = true;
		}
	}

	if ( $reload ) {
		\EE\Site\Utils\reload_global_nginx_proxy();
	}
}

/**
 * Hook to write auth and whitelist files for alias domains before the proxy serves them, so they are never reachable unprotected.
 *
 * @param string $site_url       The site whose alias domains change.
 * @param array  $domains_to_add Alias domains that are being added.
 */
function add_auth_before_alias_domains_update( $site_url, $domains_to_add = [] ) {

	// No reload needed: docker-gen renders the new hosts with these files once the site's containers are recreated.
	add_site_auth_files( $site_url, get_alias_auth_domains( (array) $domains_to_add ) );
}

/**
 * Hook to remove the files written for alias domains whose update failed.
 *
 * @param string $site_url       The site whose alias domains update failed.
 * @param array  $domains_to_add Alias domains that were not added after all.
 */
function remove_auth_on_alias_domains_update_failure( $site_url, $domains_to_add = [] ) {

	$site = Site::find( $site_url );

	if ( ! $site ) {
		return;
	}

	// The site still has its old alias domains, so this keeps every file it uses.
	$names = array_diff( get_alias_auth_domains( (array) $domains_to_add ), get_site_auth_domains( $site_url, $site ) );

	if ( remove_auth_files( $names ) ) {
		\EE\Site\Utils\reload_global_nginx_proxy();
	}
}

EE::add_hook( 'site_cleanup', 'cleanup_auth_and_whitelist' );
EE::add_hook( 'site_alias_domains_before_update', 'add_auth_before_alias_domains_update' );
EE::add_hook( 'site_alias_domains_updated', 'update_auth_on_alias_domains_change' );
EE::add_hook( 'site_alias_domains_update_failed', 'remove_auth_on_alias_domains_update_failure' );
