<?php

namespace WPIA\Infrastructure;

use WPIA\Database\DatabaseInstaller;

class MigrationManager {
	private DatabaseInstaller $database_installer;

	public function __construct( DatabaseInstaller $database_installer ) {
		$this->database_installer = $database_installer;
	}

	public function run(): void {
		$installed_version = get_option( 'wpia_db_version', '0.0.0' );

		if ( version_compare( (string) $installed_version, WPIA_DB_VERSION, '<' ) ) {
			$this->database_installer->install();
		}
	}
}
