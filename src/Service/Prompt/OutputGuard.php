<?php

namespace WPIA\Service\Prompt;

/**
 * Contrôle de sortie léger (issue 009), appliqué à la réponse brute du modèle
 * avant toute mise en cache (011) et avant son affichage.
 *
 * Deux cas, dans cet ordre :
 * 1. la réponse contient le marqueur témoin du chatbot : le prompt a fuité, la
 *    réponse est remplacée et l'appelant journalise l'événement (sans contenu) ;
 * 2. la réponse contient le jeton `[[HORS_SUJET]]` : le modèle juge la question
 *    hors sujet, la réponse est remplacée par celle de l'admin.
 *
 * Classe pure, sans WordPress. Aucun autre filtrage : pas d'appel supplémentaire à l'IA.
 */
final class OutputGuard {
	public const MOTIF_MARQUEUR   = 'marqueur';
	public const MOTIF_HORS_SUJET = 'hors_sujet';

	/** Texte utilisé si le chatbot n'a pas de réponse hors sujet (chatbot antérieur à 009). */
	public const REPONSE_PAR_DEFAUT = 'Je ne peux répondre qu\'aux questions sur le sujet de ce site.';

	/**
	 * @param array<string, mixed> $chatbot Ligne wpia_chatbot (marqueur_temoin, message_hors_sujet).
	 * @return array{content: string, remplacee: bool, motif: string|null}
	 *         `remplacee` est vrai quand le contenu n'est plus celui du modèle : une telle réponse ne doit jamais être mise en cache.
	 */
	public function controler( string $reponse, array $chatbot ): array {
		$marqueur = is_scalar( $chatbot['marqueur_temoin'] ?? null ) ? trim( (string) $chatbot['marqueur_temoin'] ) : '';
		$refus    = is_scalar( $chatbot['message_hors_sujet'] ?? null ) ? trim( (string) $chatbot['message_hors_sujet'] ) : '';
		if ( '' === $refus ) {
			$refus = self::REPONSE_PAR_DEFAUT;
		}

		if ( '' !== $marqueur && false !== stripos( $reponse, $marqueur ) ) {
			return array(
				'content'   => $refus,
				'remplacee' => true,
				'motif'     => self::MOTIF_MARQUEUR,
			);
		}

		if ( false !== stripos( $reponse, PromptBuilder::JETON_HORS_SUJET ) ) {
			return array(
				'content'   => $refus,
				'remplacee' => true,
				'motif'     => self::MOTIF_HORS_SUJET,
			);
		}

		return array(
			'content'   => $reponse,
			'remplacee' => false,
			'motif'     => null,
		);
	}
}
