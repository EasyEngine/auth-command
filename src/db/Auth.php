<?php

namespace EE\Model;

use EE;

class Auth extends Base {

	/**
	 * @var string Table of the model from where it will be stored/retrived
	 */
	protected static $table = 'auth_users';

	/**
	 * Creates or updates authentication
	 *
	 * @param string $site_url URL of site
	 * @param string $username username of auth
	 * @param string $password password of auth
	 * @param string $scope    scope of authentication(site/admin-tools)
	 *
	 * @throws \Exception
	 */
	public static function create_or_update( string $site_url, string $username, string $password, string $scope ) {

		$auth_where = [
			[ 'site_url', $site_url ],
			[ 'username', $username ],
		];

		if ( 'all' !== $scope ) {
			$auth_where[] = [ 'scope', $scope ];
		}

		$auths = static::many_array_to_model(
			EE::db()->table( static::$table )->where( $auth_where )->get()
		);

		if ( empty( $auths ) ) {
			static::create([
				'site_url' => $site_url,
				'username' => $username,
				'password' => $password,
				'scope' => $scope,
			]);
		} else {
			foreach ( $auths as $auth ) {
				$auth->password = $password;
				$auth->save();
			}
		}
	}
}
