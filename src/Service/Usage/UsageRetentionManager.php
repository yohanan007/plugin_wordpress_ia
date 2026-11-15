<?php

namespace WPIA\Service\Usage;

use WPIA\Admin\AdminSettings;
use WPIA\Repository\LimiteUtilisationRepository;

/**
 * Purge quotidienne des compteurs visiteur et session inactifs, afin de ne pas
 * conserver indéfiniment des identifiants pseudonymes de visiteurs. Les lignes
 * de portée site (une par jour, sans donnée personnelle) sont conservées.
 */
class UsageRetentionManager {
	public const HOOK = 'wpia_purge_usage';

	public function register(): void {
		add_action( 'init', array( $this, 'ensureSchedule' ) );
		add_action( self::HOOK, array( $this, 'purge' ) );
	}

	public function ensureSchedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public function purge(): void {
		global $wpdb;
		$days = (int) AdminSettings::get()['usage_retention_days'];

		try {
			( new UsageLimiter( new LimiteUtilisationRepository( $wpdb ) ) )->purger( $days );
		} catch ( \RuntimeException $exception ) {
			error_log( 'WPIA: unable to purge usage counters.' );
		}
	}
}
