<?php

namespace WPIA\Admin;

use WPIA\Service\Prompt\PromptBuilder;
use WPIA\Service\Prompt\TokenEstimator;

/**
 * Aperçu en lecture seule des blocs [1] (règles) et [2] (consignes) du prompt
 * système, tels que PromptBuilder les construit (issue 009).
 *
 * Le marqueur témoin n'est jamais inclus : l'administrateur ne doit pas le voir.
 */
final class CadrageApercu {
	/**
	 * @param array<string, mixed> $chatbot        Ligne wpia_chatbot enregistrée.
	 * @param bool                 $avec_documents Vrai si au moins un document est `ready` et actif.
	 * @return array{base: string, admin: string, tokens: array{base: int, admin: int}}
	 */
	public static function construire( array $chatbot, string $nom_site, bool $avec_documents ): array {
		$blocs = ( new PromptBuilder() )->construire( $chatbot, $nom_site, $avec_documents, false );

		return array(
			'base'   => $blocs['base'],
			'admin'  => $blocs['admin'],
			'tokens' => array(
				'base'  => TokenEstimator::estimer( $blocs['base'] ),
				'admin' => TokenEstimator::estimer( $blocs['admin'] ),
			),
		);
	}
}
