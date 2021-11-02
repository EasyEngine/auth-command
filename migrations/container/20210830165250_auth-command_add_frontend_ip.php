<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Option;
use function EE\Service\Utils\ensure_global_network_initialized;
use function EE\Utils\get_config_value;

class AddFrontendIp extends Base {

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;
		}

	}

	/**
	 * Execute auth update.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping frontend ip migration as it is not needed.' );

			return;
		}

		ensure_global_network_initialized();

		$frontend_subnet_ip = Option::get( 'frontend_subnet_ip' );
		$auth = new \Auth_Command();
		$auth->update( ['global'], [ 'ip' => $frontend_subnet_ip ] );
	}

	/**
	 * Down migration.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

	}

}
