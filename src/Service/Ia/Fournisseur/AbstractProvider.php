<?php

namespace WPIA\Service\Ia\Fournisseur;

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
	 * Fusionne les messages consécutifs de même rôle (séparés par une ligne vide) :
	 * la plupart des fournisseurs exigent une alternance stricte user / assistant.
	 *
	 * @param array<int, array{role:string,content:string}> $messages
	 * @return array<int, array{role:string,content:string}>
	 */
	protected function buildMessages( array $messages ): array {
		$result = array();
		foreach ( $messages as $message ) {
			$last = count( $result ) - 1;
			if ( $last >= 0 && $result[ $last ]['role'] === $message['role'] ) {
				$result[ $last ]['content'] .= "\n\n" . $message['content'];
				continue;
			}
			$result[] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}

		return $result;
	}

	/**
	 * Prompt système en un seul texte : partie stable d'abord (préfixe commun
	 * entre deux requêtes), partie variable ensuite.
	 *
	 * @param array{stable:string,variable:string} $systeme
	 */
	protected function systemeTexte( array $systeme ): string {
		return implode( "\n\n", array_filter( array( $systeme['stable'], $systeme['variable'] ), static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Liste de messages au format « chat completions » : message system en tête
	 * (s'il n'est pas vide), puis la conversation.
	 *
	 * @param array{stable:string,variable:string}          $systeme
	 * @param array<int, array{role:string,content:string}> $messages
	 * @return array<int, array{role:string,content:string}>
	 */
	protected function buildChatMessages( array $systeme, array $messages ): array {
		$result = array();
		$system = $this->systemeTexte( $systeme );
		if ( '' !== $system ) {
			$result[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}

		return array_merge( $result, $this->buildMessages( $messages ) );
	}

	/**
	 * @param array<string, mixed> $parameters `systeme` ({stable, variable}) et `messages`
	 *                                         remplacent l'ancien couple system_prompt / prompt.
	 * @return array|\WP_Error
	 */
	abstract public function generate( string $base_url, string $api_key, array $parameters ): array|\WP_Error;
}
