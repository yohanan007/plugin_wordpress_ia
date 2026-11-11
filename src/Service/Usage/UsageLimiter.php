<?php

namespace WPIA\Service\Usage;

use RuntimeException;
use WPIA\Admin\AdminSettings;

/**
 * Applique les limites de consommation : réservation avant l'appel au
 * fournisseur, puis confirmation (consommation réelle) ou annulation.
 *
 * Voir documentation/limites.md.
 */
class UsageLimiter {
	public const ALERT_OPTION = 'wpia_alert_last';

	private UsageStore $store;

	/** @var callable(): int */
	private $clock;

	/**
	 * @param callable(): int|null $clock Horloge injectable (timestamp Unix) pour les tests.
	 */
	public function __construct( UsageStore $store, ?callable $clock = null ) {
		$this->store = $store;
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Estimation prudente : ceil( octets / 4 ) pour chaque texte d'entrée, plus
	 * le plafond de sortie demandé. Même règle que la taille des documents.
	 *
	 * @param array<int, string> $inputs
	 */
	public function estimer( array $inputs, int $max_output_tokens ): int {
		$tokens = max( 0, $max_output_tokens );
		foreach ( $inputs as $input ) {
			$tokens += (int) ceil( strlen( $input ) / 4 );
		}

		return max( 1, $tokens );
	}

	/**
	 * @param array{visiteur?:string,session?:string} $identites Identifiants déjà pseudonymisés (voir VisitorIdentity).
	 * @return array{jour:string,estimation:int,cles:array<int, UsageKey>}|\WP_Error
	 */
	public function reserver( int $chatbot_id, array $identites, int $estimation ): array|\WP_Error {
		$settings   = AdminSettings::get();
		$jour       = $this->today();
		$now        = $this->now();
		$estimation = max( 1, $estimation );

		$portees = array(
			array( UsageKey::site( $jour ), (int) $settings['daily_token_limit'], null ),
		);
		if ( ! empty( $identites['visiteur'] ) ) {
			$portees[] = array( UsageKey::visiteur( $chatbot_id, $identites['visiteur'], $jour ), null, (int) $settings['max_messages_user'] );
		}
		if ( ! empty( $identites['session'] ) ) {
			$portees[] = array( UsageKey::session( $chatbot_id, $identites['session'] ), (int) $settings['max_tokens_conversation'], null );
		}

		$reservees = array();
		try {
			foreach ( $portees as $portee ) {
				list( $key, $max_tokens, $max_messages ) = $portee;
				if ( ! $this->store->reserve( $key, $estimation, 1, $max_tokens, $max_messages, $now ) ) {
					$this->rollback( $reservees, $estimation, $now );
					return $this->refus( $key->portee );
				}
				$reservees[] = $key;
			}
		} catch ( RuntimeException $exception ) {
			try {
				$this->rollback( $reservees, $estimation, $now );
			} catch ( RuntimeException $ignored ) {
				error_log( 'WPIA: unable to release an AI usage reservation.' );
			}
			return new \WP_Error(
				'wpia_usage_unavailable',
				__( 'Le service est momentanément indisponible. Réessayez plus tard.', 'plugin-wordpress-ia' ),
				array( 'status' => 503 )
			);
		}

		return array(
			'jour'       => $jour,
			'estimation' => $estimation,
			'cles'       => $reservees,
		);
	}

	/**
	 * Remplace l'estimation par la consommation réelle (ou la conserve si le
	 * fournisseur ne la renvoie pas), puis déclenche l'alerte si besoin.
	 *
	 * @param array{jour:string,estimation:int,cles:array<int, UsageKey>} $reservation
	 */
	public function confirmer( array $reservation, ?int $reel ): void {
		$delta = null === $reel ? 0 : max( 0, $reel ) - $reservation['estimation'];
		$now   = $this->now();

		try {
			foreach ( $reservation['cles'] as $key ) {
				$this->store->adjust( $key, $delta, 0, $now );
			}
			$this->verifierAlerte( $reservation['jour'] );
		} catch ( RuntimeException $exception ) {
			error_log( 'WPIA: unable to record AI usage.' );
		}
	}

	/**
	 * Libère la réservation : l'appel n'a pas été facturé.
	 *
	 * @param array{jour:string,estimation:int,cles:array<int, UsageKey>} $reservation
	 */
	public function annuler( array $reservation ): void {
		try {
			$this->rollback( $reservation['cles'], $reservation['estimation'], $this->now() );
		} catch ( RuntimeException $exception ) {
			error_log( 'WPIA: unable to release an AI usage reservation.' );
		}
	}

	/**
	 * @return array{jour:string,tokens:int,messages:int,limite:int,seuil:int,alerte_envoyee:bool}
	 */
	public function consommationSite( ?string $jour = null ): array {
		$settings = AdminSettings::get();
		$jour     = $jour ?? $this->today();
		$row      = $this->store->get( UsageKey::site( $jour ) );
		$limite   = (int) $settings['daily_token_limit'];

		return array(
			'jour'           => $jour,
			'tokens'         => $row['nb_tokens'],
			'messages'       => $row['nb_messages'],
			'limite'         => $limite,
			'seuil'          => (int) ceil( $limite * ( (int) $settings['token_alert_threshold'] / 100 ) ),
			'alerte_envoyee' => $row['alerte_envoyee'],
		);
	}

	/**
	 * Supprime les compteurs visiteur et session inactifs depuis $jours jours.
	 */
	public function purger( int $jours ): int {
		$cutoff = wp_date( 'Y-m-d H:i:s', ( $this->clock )() - max( 1, $jours ) * DAY_IN_SECONDS );

		return $this->store->purgeInactiveBefore( (string) $cutoff );
	}

	private function verifierAlerte( string $jour ): void {
		$conso = $this->consommationSite( $jour );
		if ( $conso['alerte_envoyee'] || $conso['tokens'] < $conso['seuil'] ) {
			return;
		}

		// Seul l'appel qui fait passer le drapeau de 0 à 1 envoie l'e-mail.
		if ( ! $this->store->markAlertSent( UsageKey::site( $jour ) ) ) {
			return;
		}

		update_option( self::ALERT_OPTION, $this->now(), false );
		wp_mail(
			(string) get_option( 'admin_email' ),
			__( '[IA Chatbot] Seuil de consommation atteint', 'plugin-wordpress-ia' ),
			sprintf(
				/* translators: 1: tokens used today, 2: daily site budget, 3: alert threshold percentage. */
				__( "La consommation du jour atteint %1\$d tokens sur un budget journalier de %2\$d (seuil d'alerte : %3\$d %%).\nAu-delà du budget, les nouvelles demandes seront refusées jusqu'à demain.", 'plugin-wordpress-ia' ),
				$conso['tokens'],
				$conso['limite'],
				(int) AdminSettings::get()['token_alert_threshold']
			)
		);
	}

	/**
	 * @param array<int, UsageKey> $keys
	 */
	private function rollback( array $keys, int $estimation, string $now ): void {
		foreach ( $keys as $key ) {
			$this->store->adjust( $key, -$estimation, -1, $now );
		}
	}

	private function refus( string $portee ): \WP_Error {
		$messages = array(
			UsageKey::SITE     => __( 'Le service a atteint sa limite d’utilisation pour aujourd’hui. Réessayez demain.', 'plugin-wordpress-ia' ),
			UsageKey::VISITEUR => __( 'Vous avez atteint le nombre maximal de messages pour aujourd’hui. Réessayez demain.', 'plugin-wordpress-ia' ),
			UsageKey::SESSION  => __( 'Cette conversation a atteint sa longueur maximale. Démarrez une nouvelle conversation.', 'plugin-wordpress-ia' ),
		);

		return new \WP_Error(
			'wpia_limit_reached',
			$messages[ $portee ],
			array(
				'status' => 429,
				'portee' => $portee,
			)
		);
	}

	private function today(): string {
		return (string) wp_date( 'Y-m-d', ( $this->clock )() );
	}

	private function now(): string {
		return (string) wp_date( 'Y-m-d H:i:s', ( $this->clock )() );
	}
}
