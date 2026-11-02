<?php

namespace WPIA\Service\Fournisseur;

use JsonException;

abstract class AbstractProvider {
	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $payload
	 * @return array|\WP_Error
	 */
	protected function post( string $url, array $headers, array $payload, int $timeout, bool $allow_local = false ): array|\WP_Error {
		try {
			$body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			return new \WP_Error( 'wpia_invalid_request', __( 'La requête de génération est invalide.', 'plugin-wordpress-ia' ) );
		}
		if ( ! is_string( $body ) ) {
			return new \WP_Error( 'wpia_invalid_request', __( 'La requête de génération est invalide.', 'plugin-wordpress-ia' ) );
		}

		$args = array(
			'timeout'             => max( 1, min( 120, $timeout ) ),
			'redirection'         => 0,
			'headers'             => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$headers
			),
			'body'                => $body,
			'limit_response_size' => 1048576,
		);

		return $allow_local
			? wp_remote_post( $url, $args )
			: wp_safe_remote_post( $url, $args );
	}

	/**
	 * @param array<string, mixed> $parameters
	 * @return array|\WP_Error
	 */
	abstract public function generate( string $base_url, string $api_key, array $parameters ): array|\WP_Error;
}
