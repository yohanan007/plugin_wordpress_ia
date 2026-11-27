<?php

namespace WPIA\Service\Ia\Fournisseur;

use WPIA\Service\Prompt\TokenEstimator;

class AnthropicProvider extends AbstractProvider {
	/**
	 * Au-dessus de ce nombre de tokens estimés, la partie stable du prompt système est
	 * marquée pour le cache. Sous le minimum propre au modèle (512 à 4 096 tokens),
	 * l'API ignore le marquage sans erreur : ce seuil évite seulement de marquer
	 * inutilement les petits prompts.
	 */
	private const SEUIL_CACHE_TOKENS = 1024;

	/**
	 * @param array<string, mixed> $parameters
	 * @return array|\WP_Error
	 */
	public function generate( string $base_url, string $api_key, array $parameters ): array|\WP_Error {
		// Pas de `temperature` : les modèles Claude récents la refusent (400
		// « temperature is deprecated for this model »). La température configurée
		// ne s'applique donc pas aux chatbots Anthropic.
		$payload = array(
			'model'      => $parameters['model'],
			'max_tokens' => $parameters['max_tokens'],
			'messages'   => $this->buildMessages( $parameters['messages'] ),
		);
		$systeme = $parameters['systeme'];
		if ( TokenEstimator::estimer( $systeme['stable'] ) > self::SEUIL_CACHE_TOKENS ) {
			// Blocs : la partie stable (préfixe commun) porte le point de cache, la partie variable la suit.
			$blocs = array(
				array(
					'type'          => 'text',
					'text'          => $systeme['stable'],
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			);
			if ( '' !== $systeme['variable'] ) {
				$blocs[] = array(
					'type' => 'text',
					'text' => $systeme['variable'],
				);
			}
			$payload['system'] = $blocs;
		} else {
			$system = $this->systemeTexte( $systeme );
			if ( '' !== $system ) {
				$payload['system'] = $system;
			}
		}

		return $this->post(
			untrailingslashit( $base_url ) . '/messages',
			array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			$payload,
			$parameters['timeout']
		);
	}
}
