<?php


namespace EE\Auth\Utils;

use EE;
use EE\Model\Auth;

/**
 * Initialize global admin tools auth if it's not present.
 *
 * @throws \EE\ExitException
 * @throws \Exception
 */
function init_global_admin_tools_auth() {

	$auth_data = [
		'site_url' => 'default',
		'username' => 'easyengine',
		'scope'    => 'admin-tools',
	];

	if ( ! empty( Auth::where( $auth_data ) ) ) {
		EE::log( 'Global auth exists on admin-tools. Use `ee auth list global` to view credentials.' );

		return;
	}

	verify_htpasswd_is_present();
	$site_auth_file_name = $auth_data['site_url'] . '_admin_tools';
	$pass                = \EE\Utils\random_password();

	$auth_data['password'] = $pass;

	Auth::create( $auth_data );
	EE::exec( sprintf( 'docker exec %s htpasswd -bc /etc/nginx/htpasswd/%s %s %s', EE_PROXY_TYPE, $site_auth_file_name, $auth_data['username'], $auth_data['password'] ) );
	EE::success( sprintf( 'Global admin-tools auth added. Use `ee auth list global` to view credentials.' ) );
}

/**
 * Check if htpasswd is present in the global-container.
 */
function verify_htpasswd_is_present() {

	EE::debug( 'Verifying htpasswd is present.' );
	if ( EE::exec( sprintf( 'docker exec %s sh -c \'command -v htpasswd\'', EE_PROXY_TYPE ) ) ) {
		return;
	}
	EE::error( sprintf( 'Could not find apache2-utils installed in %s.', EE_PROXY_TYPE ) );
}
