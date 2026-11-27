<?php

namespace WPIA\Service\Ia\Fournisseur;

class MistralProvider extends AbstractProvider {
	/**
	 * @param array<string, mixed> $parameters
	 * @return array|\WP_Error
	 */
	public function generate( string $base_url, string $api_key, array $parameters ): array|\WP_Error {
		$messages = $this->buildChatMessages( $parameters['systeme'], $parameters['messages'] );

		return $this->post(
			untrailingslashit( $base_url ) . '/chat/completions',
			array( 'Authorization' => 'Bearer ' . $api_key ),
			array(
				'model'            => $parameters['model'],
				'messages'         => $messages,
				'temperature'      => $parameters['temperature'],
				'max_tokens'       => $parameters['max_tokens'],
				// Réponse rapide, sans bloc de réflexion (modèles hybrides comme
				// Mistral Small 4). Non réglable dans l'admin.
				'reasoning_effort' => 'none',
			),
			$parameters['timeout']
		);
	}
}
