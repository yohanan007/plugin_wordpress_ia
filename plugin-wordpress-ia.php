<?php
/**
 * Plugin Name: Plugin WordPress IA
 * Description: Plugin WordPress permettant d'intégrer un chatbot IA.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: plugin-wordpress-ia
 *
 * @package WPIA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPIA_VERSION' ) ) {
	define( 'WPIA_VERSION', '1.0.0' );
}

if ( ! defined( 'WPIA_DB_VERSION' ) ) {
	define( 'WPIA_DB_VERSION', '1.1.0' );
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(
	__FILE__,
	array( WPIA\Infrastructure\PluginActivator::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( WPIA\Infrastructure\PluginActivator::class, 'deactivate' )
);

add_action(
	'plugins_loaded',
	static function (): void {
		$migration_manager = new WPIA\Infrastructure\MigrationManager(
			new WPIA\Database\DatabaseInstaller()
		);
		$migration_manager->run();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		( new WPIA\Admin\AdminController() )->register();
		( new WPIA\Service\LogRetentionManager() )->register();
	}
);
