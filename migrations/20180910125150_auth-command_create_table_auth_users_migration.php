<?php
namespace EE\Migration;

use EE;
use EE\Migration\Base;

class CreateTableAuthUsersMigration extends Base {

	private static $pdo;

	public function __construct() {

		try {
			self::$pdo = new \PDO( 'sqlite:' . DB );
			self::$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		} catch ( \PDOException $exception ) {
			EE::error( $exception->getMessage() );
		}

	}

	public function up() {

		$query = 'CREATE TABLE auth_users (
			id INTEGER,
			site_url VARCHAR NOT NULL,
			username VARCHAR NOT NULL,
			password VARCHAR NOT NULL,
			scope    VARCHAR NOT NULL,
			PRIMARY KEY (id)
		);';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while creating table: ' . $exception->getMessage() );
		}
	}

	public function down() {

		$query = 'DROP TABLE IF EXISTS auth_users;';

		try {
			self::$pdo->exec( $query );
		} catch ( PDOException $exception ) {
			EE::error( 'Encountered Error while dropping table: ' . $exception->getMessage() );
		}
	}
}
