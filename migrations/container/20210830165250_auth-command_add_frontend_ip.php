<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
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

		$frontend_subnet_ip = get_config_value( 'frontend_subnet_ip', '10.0.0.0/16' );
		EE::runcommand( "auth update global --ip='$frontend_subnet_ip'" );
	}

	/**
	 * Down migration.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

	}

}
