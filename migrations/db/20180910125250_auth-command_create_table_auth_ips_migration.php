<?php
namespace EE\Migration;

use EE;
use EE\Migration\Base;

class CreateTableAuthIpsMigration extends Base {

	private static $pdo;

	public function __construct() {

		try {
			self::$pdo = new \PDO( 'sqlite:' . DB );
			self::$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		} catch ( \PDOException $exception ) {
			EE::error( $exception->getMessage() );
		}

	}

	/**
	 * Execute create table query for auth_users table.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		$query = 'CREATE TABLE auth_ips (
			id INTEGER,
			site_url VARCHAR NOT NULL,
			ip       VARCHAR NOT NULL,
			UNIQUE (site_url, ip),
			PRIMARY KEY (id)
		);';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while creating table: ' . $exception->getMessage(), false );
		}
	}

	/**
	 * Execute drop table query for auth_users table.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		$query = 'DROP TABLE IF EXISTS auth_ips;';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while dropping table: ' . $exception->getMessage(), false );
		}
	}
}
