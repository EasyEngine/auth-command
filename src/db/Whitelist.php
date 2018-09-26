<?php

namespace EE\Model;

use EE;

class Whitelist extends Base {

	/**
	 * @var string Table of the model from where it will be stored/retrived
	 */
	protected static $table = 'auth_ips';

	/**
	 * Returns all globally whitelisted IPs
	 *
	 * @throws \Exception
	 * @return array
	 */
	public static function get_global_ips() {
		return static::where([
			'site_url' => 'default',
		]);
	}

	/**
	 * Checks if site has IPs
	 *
	 * @param $site_url
	 *
	 * @throws \Exception
	 * @return bool
	 */
	public static function has_ips( $site_url ) {
		return ! empty( static::where( [ 'site_url' => $site_url ] ) );
	}
}
