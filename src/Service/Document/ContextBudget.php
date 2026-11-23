<?php

namespace WPIA\Service\Document;

use WPIA\Domain\ChatbotDocument;

/**
 * Calculs de budget affichés et contrôlés dans l'admin (issue 006, lot B).
 *
 * Fonctions pures, sans WordPress. Les tokens sont ceux des sections `ready` des
 * documents actifs associés au chatbot (voir DocumentSectionRepository).
 */
final class ContextBudget {
	/**
	 * Tokens du contexte et des documents complets.
	 *
	 * @param array<int, array{role:string,tokens:int}> $par_document document_id => rôle et tokens.
	 * @return array{contexte:int,complet:int}
	 */
	public static function totaux( array $par_document ): array {
		$totaux = array( 'contexte' => 0, 'complet' => 0 );
		foreach ( $par_document as $document ) {
			$cle             = ChatbotDocument::ROLE_CONTEXTE === $document['role'] ? 'contexte' : 'complet';
			$totaux[ $cle ] += max( 0, (int) $document['tokens'] );
		}

		return $totaux;
	}

	/**
	 * Un document peut-il rejoindre le contexte sans dépasser sa limite ? Un document
	 * sans token (non indexé) est refusé : sa taille réelle est inconnue.
	 *
	 * @param int $contexte_actuel Tokens du contexte, sans le document candidat.
	 */
	public static function accepteContexte( int $contexte_actuel, int $tokens_document, int $max ): bool {
		return $tokens_document > 0 && $contexte_actuel + $tokens_document <= $max;
	}

	/**
	 * Résumé pour la jauge de la page « Documents ».
	 *
	 * @return array{contexte:int,complet:int,complet_envoye:int,libre:int,budget:int,echelle:int,mode:string,contexte_depasse:bool,budget_insuffisant:bool}
	 *         `complet_envoye` : part des documents complets qui tient dans le budget
	 *         (tout en mode complet, une partie seulement en mode sélection).
	 */
	public static function resume( int $contexte, int $complet, int $budget, int $contexte_max ): array {
		$restant = max( 0, $budget - $contexte );
		$envoye  = min( $complet, $restant );
		if ( 0 === $contexte && 0 === $complet ) {
			$mode = ContextBuilder::MODE_AUCUN;
		} else {
			$mode = $complet <= $restant ? ContextBuilder::MODE_COMPLET : ContextBuilder::MODE_SELECTION;
		}

		return array(
			'contexte'         => $contexte,
			'complet'          => $complet,
			'complet_envoye'   => $envoye,
			'libre'            => max( 0, $restant - $envoye ),
			'budget'           => $budget,
			'echelle'          => max( 1, $budget, $contexte ),
			'mode'             => $mode,
			'contexte_depasse' => $contexte > $contexte_max,
			// Le contexte occupe tout le budget : aucun document complet ne peut être transmis.
			'budget_insuffisant' => $contexte > 0 && $complet > 0 && $contexte >= $budget,
		);
	}
}
