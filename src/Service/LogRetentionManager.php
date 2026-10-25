<?php

namespace WPIA\Service;

use WPIA\Admin\AdminSettings;
use WPIA\Repository\LogRepository;

class LogRetentionManager {
	private const HOOK = 'wpia_purge_logs';

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'addSchedules' ) );
		add_action( 'init', array( $this, 'ensureSchedule' ) );
		add_action( self::HOOK, array( $this, 'purge' ) );
		add_action( 'update_option_' . AdminSettings::OPTION_NAME, array( $this, 'settingsUpdated' ), 10, 2 );
	}

	/**
	 * @param array<string, array<string, int|string>> $schedules
	 * @return array<string, array<string, int|string>>
	 */
	public function addSchedules( array $schedules ): array {
		$schedules['wpia_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Hebdomadaire (WPIA)', 'plugin-wordpress-ia' ),
		);
		$schedules['wpia_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Mensuel (WPIA)', 'plugin-wordpress-ia' ),
		);

		return $schedules;
	}

	public function ensureSchedule(): void {
		$this->reschedule( AdminSettings::get()['log_retention'] );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $new_value
	 */
	public function settingsUpdated( $old_value, $new_value ): void {
		$retention = is_array( $new_value ) && isset( $new_value['log_retention'] )
			? $new_value['log_retention']
			: 'weekly';
		$this->reschedule( $retention );
	}

	public function purge(): void {
		$retention = AdminSettings::get()['log_retention'];
		if ( 'never' === $retention ) {
			return;
		}

		global $wpdb;
		$repository = new LogRepository( $wpdb );
		$days       = 'monthly' === $retention ? 30 : 7;
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$repository->deleteBefore( $cutoff );
	}

	private function reschedule( string $retention ): void {
		$current = wp_get_schedule( self::HOOK );
		$wanted  = 'monthly' === $retention ? 'wpia_monthly' : 'wpia_weekly';

		if ( 'never' === $retention ) {
			if ( false !== $current ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		if ( $current !== $wanted ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $wanted, self::HOOK );
		}
	}
}
