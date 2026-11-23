<?php

namespace WPIA\Service\Prompt;

use WPIA\Service\Document\KeywordNormalizer;

/**
 * Version du contexte d'un chatbot (issue 006, lot B) : une empreinte de tout ce
 * qui change ce que l'IA reçoit. Elle sert de clé au cache (issue 011).
 *
 * Elle entre : la version du texte des règles fixes, les champs de cadrage qui
 * figurent dans le prompt (sujet, ton, langue, consignes, marqueur), la liste
 * ordonnée des triplets (empreinte, rôle, désignation) des documents `ready` et
 * actifs (la désignation figure dans les attributs des sections), le nom du site, les trois réglages de budget, et la version de la normalisation des
 * mots-clés (elle change la sélection).
 *
 * Fonction pure. Elle est calculée à la demande plutôt que stockée : aucun
 * événement à intercepter (import, suppression, réindexation, rôle, ordre,
 * enregistrement du chatbot ou des réglages), donc aucun risque de version
 * périmée.
 */
final class ContextVersion {
	private const CHAMPS_PROMPT = array( 'sujet', 'ton', 'langue', 'system_prompt', 'marqueur_temoin' );

	private const REGLAGES = array( 'context_token_budget', 'context_fixed_max_tokens', 'context_section_max_tokens' );

	/**
	 * @param array<string, mixed>                          $chatbot   Ligne wpia_chatbot.
	 * @param array<int, array{hash:string,role:string,designation?:string}> $documents Documents `ready` et actifs, dans l'ordre.
	 * @param array<string, mixed>                          $reglages  AdminSettings::get().
	 * @param string                                        $nom_site  Nom du site, présent dans les règles fixes.
	 */
	public static function calculer( array $chatbot, array $documents, array $reglages, string $nom_site = '' ): string {
		$cadrage = array();
		foreach ( self::CHAMPS_PROMPT as $champ ) {
			$valeur           = $chatbot[ $champ ] ?? '';
			$cadrage[ $champ ] = is_scalar( $valeur ) ? (string) $valeur : '';
		}

		$docs = array();
		foreach ( $documents as $document ) {
			$docs[] = array( (string) $document['hash'], (string) $document['role'], (string) ( $document['designation'] ?? '' ) );
		}

		$budgets = array();
		foreach ( self::REGLAGES as $reglage ) {
			$budgets[ $reglage ] = (int) ( $reglages[ $reglage ] ?? 0 );
		}

		return hash(
			'sha256',
			(string) json_encode(
				array(
					'prompt'   => PromptBuilder::VERSION,
					'mots'     => KeywordNormalizer::VERSION,
					'site'     => $nom_site,
					'cadrage'  => $cadrage,
					'documents' => $docs,
					'budgets'  => $budgets,
				),
				JSON_UNESCAPED_UNICODE
			)
		);
	}
}
