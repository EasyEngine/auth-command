<?php

namespace EE\Model;

use EE;

class Auth extends Base {

	/**
	 * @var string Table of the model from where it will be stored/retrived
	 */
	protected static $table = 'auth_users';

	/**
	 * Returns global admin tools auth object
	 *
	 * @throws \Exception
	 * @return array
	 */
	public static function get_global_admin_tools_auth() {
		$admin_tools = static::where(
			[
				'site_url' => 'default_admin_tools',
			]
		);

		if ( empty( $admin_tools ) ) {
			return [];
		}

		return $admin_tools;
	}

	/**
	 * Overrides parent method to perform validation before creating
	 *
	 * @param array $columns Values to insert
	 *
	 * @throws \Exception
	 * @return bool
	 */
	public static function create( $columns = [] ) {
		self::validate( $columns );

		return parent::create( $columns );
	}

	/**
	 * Performs common validation before create/update
	 *
	 * @param $columns
	 *
	 * @throws EE\ExitException
	 */
	public static function validate( $columns ) {
		if ( empty( $columns['site_url'] ) || empty( $columns['username'] ) || empty( $columns['password'] ) ) {
			throw new \Exception( 'site_url, username and password should be provided' );
		}

		if ( 'default' === $columns['site_url'] ) {
			$existing_auths = static::where(
				[
					[ 'username', $columns['username'] ],
					[ 'site_url', '!=', 'default_admin_tools' ],
				]
			);
			$error_message = sprintf( 'There already exists an username %s on some site or globally. You cannot use this username', $columns['username'] );
		} else {
			$existing_auths = array_merge(
				static::where(
					[
						'username' => $columns['username'],
						'site_url' => 'default',
					]
				),
				static::where(
					[
						'username' => $columns['username'],
						'site_url' => $columns['site_url'],
					]
				)
			);
			$error_message = sprintf( 'There already exists an username %s on this site or globally. You cannot use this username', $columns['username'] );
		}

		if ( ! empty( $existing_auths ) ) {
			EE::error( $error_message );
		}
	}

	/**
	 * Method to return all global auth
	 *
	 * @throws \Exception
	 * @return array
	 */
	public static function get_global_auths() {
		return static::where(
			[
				'site_url' => 'default',
			]
		);
	}

	/**
	 * Overrides parent method to perform validation before updating
	 *
	 * @param array $where          Where conditions to check
	 * @param array $updated_values Values to update
	 *
	 * @throws \Exception
	 * @return bool
	 */
	public static function update( array $where, array $updated_values ) {
		self::validate( $updated_values );

		return parent::update( $where, $updated_values );
	}

	/**
	 * Overrides parent method to perform validation before save
	 *
	 * @throws \Exception
	 * @return bool
	 */
	public function save() {
		$columns = [
			'site_url' => $this->site_url,
			'username' => $this->username,
			'password' => $this->password,
		];

		self::validate( $columns );

		return parent::save();
	}
}
