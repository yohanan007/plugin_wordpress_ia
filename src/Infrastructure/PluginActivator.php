<?php

namespace WPIA\Infrastructure;

use WPIA\Database\DatabaseInstaller;

class PluginActivator {
	public static function activate(): void {
		( new DatabaseInstaller() )->install();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'wpia_purge_logs' );
	}
}
