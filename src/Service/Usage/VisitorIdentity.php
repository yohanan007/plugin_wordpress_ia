<?php

namespace WPIA\Service\Usage;

/**
 * Calcule les identifiants pseudonymes des portées « visiteur » et « session ».
 * Aucune IP ni aucun jeton en clair n'est jamais retourné.
 */
class VisitorIdentity {
	private const HKDF_CONTEXT = 'wpia-ip-v1';

	/**
	 * Identifiant du visiteur courant : « u:<id> » si connecté, sinon « ip:<hmac> ».
	 * Retourne '' si aucune IP exploitable n'est disponible (CLI, cron).
	 */
	public function fromRequest(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			return $this->forUser( $user_id );
		}

		// Seul REMOTE_ADDR est fiable : X-Forwarded-For est falsifiable. Derrière
		// un proxy de confiance, le filtre wpia_client_ip permet de l'adapter.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		$ip     = function_exists( 'apply_filters' ) ? apply_filters( 'wpia_client_ip', $remote ) : $remote;

		return $this->forIp( is_string( $ip ) ? $ip : '' );
	}

	public function forUser( int $user_id ): string {
		return $user_id > 0 ? 'u:' . $user_id : '';
	}

	public function forIp( string $ip ): string {
		$ip = trim( $ip );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return 'ip:' . hash_hmac( 'sha256', $this->normalizeIp( $ip ), $this->key() );
	}

	/**
	 * Le jeton de session (cookie wpia_session) n'est stocké que haché.
	 */
	public function forSession( string $token ): string {
		$token = trim( $token );
		return '' === $token ? '' : 's:' . hash( 'sha256', $token );
	}

	/**
	 * Les adresses IPv6 « privacy » changent au sein d'un même /64 : on compte
	 * le préfixe /64 pour éviter un contournement trivial du quota.
	 */
	private function normalizeIp( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}

		$packed = inet_pton( $ip );
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}

		return (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
	}

	private function key(): string {
		return hash_hkdf( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), 32, self::HKDF_CONTEXT );
	}
}
