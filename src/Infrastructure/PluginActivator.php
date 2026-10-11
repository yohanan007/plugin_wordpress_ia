<?php

namespace WPIA\Infrastructure;

use WPIA\Database\DatabaseInstaller;

class PluginActivator {
	public static function activate(): void {
		( new DatabaseInstaller() )->install();
	}

	public static function deactivate(): void {
		// Data is intentionally retained when the plugin is deactivated.
	}
}
