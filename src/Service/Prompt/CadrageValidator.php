<?php

namespace WPIA\Service\Prompt;

/**
 * Valide les champs de cadrage d'un chatbot (issue 009).
 *
 * Classe pure, sans fonction WordPress, pour être testée seule. Elle reçoit le
 * texte BRUT du formulaire (après wp_unslash(), avant tout sanitize_*()) : un
 * nettoyage préalable retirerait les balises et rendrait le contrôle inopérant.
 *
 * Refus ciblé : un champ invalide garde sa valeur actuelle et produit un code
 * d'erreur ; les autres champs sont acceptés. L'appelant traduit les codes en
 * messages, puis applique le nettoyage WordPress aux valeurs renvoyées.
 */
final class CadrageValidator {
	public const TONS          = array( 'formel', 'neutre', 'chaleureux' );
	public const TON_DEFAUT    = 'neutre';
	public const LANGUES       = array( 'visiteur', 'fr', 'en' );
	public const LANGUE_DEFAUT = 'visiteur';

	// Limites en caractères (les consignes, elles, sont limitées en tokens).
	public const SUJET_MAX     = 200;
	public const MESSAGE_MAX   = 300;
	public const QUESTIONS_MAX = 4;
	public const QUESTION_MAX  = 120;

	/**
	 * Fragments refusés quelle que soit la casse : ils permettraient de forger un
	 * faux bloc de données (006) ou de déclencher le remplacement hors sujet.
	 */
	public const FRAGMENTS_INTERDITS = array(
		'<contexte',
		'</contexte',
		'<documents',
		'</documents',
		'<section',
		'</section',
		PromptBuilder::JETON_HORS_SUJET,
	);

	// Codes d'erreur renvoyés par champ.
	public const ERREUR_OBLIGATOIRE       = 'obligatoire';
	public const ERREUR_TROP_LONG         = 'trop_long';
	public const ERREUR_INTERDIT          = 'fragment_interdit';
	public const ERREUR_TROP_DE_QUESTIONS = 'trop_de_questions';
	public const ERREUR_INVALIDE          = 'invalide';

	/**
	 * @param array<string, mixed> $brut                 Champs soumis, sans slashes, non nettoyés.
	 *                                                   Un champ absent garde sa valeur actuelle.
	 * @param array<string, mixed> $actuel               Ligne wpia_chatbot actuelle (vide à la création).
	 * @param int                  $max_tokens_consignes Réglage prompt_admin_max_tokens.
	 * @return array{valeurs: array<string, string>, erreurs: array<string, string>}
	 *         `valeurs` contient toujours les 7 colonnes de cadrage, prêtes à être nettoyées ;
	 *         `erreurs` associe un champ refusé à son code d'erreur.
	 */
	public function valider( array $brut, array $actuel, int $max_tokens_consignes ): array {
		$valeurs = array();
		$erreurs = array();

		// Champs texte d'une ligne ou d'un paragraphe, limités en caractères.
		$limites = array(
			'sujet'              => array( self::SUJET_MAX, true ),
			'message_accueil'    => array( self::MESSAGE_MAX, false ),
			'message_hors_sujet' => array( self::MESSAGE_MAX, true ),
		);
		foreach ( $limites as $champ => list( $max, $obligatoire ) ) {
			$courant           = $this->texte( $actuel[ $champ ] ?? '' );
			$valeurs[ $champ ] = $courant;
			if ( ! array_key_exists( $champ, $brut ) ) {
				continue;
			}
			if ( ! is_string( $brut[ $champ ] ) ) {
				$erreurs[ $champ ] = self::ERREUR_INVALIDE;
				continue;
			}
			$soumis = $this->texte( $brut[ $champ ] );
			$erreur = $this->controlerTexte( $soumis, $max, $obligatoire );
			if ( null !== $erreur ) {
				$erreurs[ $champ ] = $erreur;
				continue;
			}
			$valeurs[ $champ ] = $soumis;
		}

		// Listes fermées : une valeur inconnue est ramenée à la valeur par défaut, sans erreur.
		$valeurs['ton']    = $this->choix( $brut, $actuel, 'ton', self::TONS, self::TON_DEFAUT );
		$valeurs['langue'] = $this->choix( $brut, $actuel, 'langue', self::LANGUES, self::LANGUE_DEFAUT );

		// Questions suggérées : stockées en JSON, les lignes vides sont ignorées.
		$questions_actuelles            = $this->texte( $actuel['questions_suggerees'] ?? '' );
		$valeurs['questions_suggerees'] = '' === $questions_actuelles ? '[]' : $questions_actuelles;
		if ( array_key_exists( 'questions_suggerees', $brut ) ) {
			$resultat = $this->validerQuestions( $brut['questions_suggerees'] );
			if ( is_string( $resultat['erreur'] ) ) {
				$erreurs['questions_suggerees'] = $resultat['erreur'];
			} else {
				$valeurs['questions_suggerees'] = $resultat['json'];
			}
		}

		// Consignes complémentaires (colonne system_prompt), limitées en tokens.
		$consignes_actuelles      = $this->texte( $actuel['system_prompt'] ?? '' );
		$valeurs['system_prompt'] = $consignes_actuelles;
		if ( array_key_exists( 'system_prompt', $brut ) ) {
			if ( ! is_string( $brut['system_prompt'] ) ) {
				$erreurs['system_prompt'] = self::ERREUR_INVALIDE;
			} else {
				$soumis = $this->texte( $brut['system_prompt'] );
				if ( $this->contientFragmentInterdit( $soumis ) ) {
					$erreurs['system_prompt'] = self::ERREUR_INTERDIT;
				} elseif ( TokenEstimator::estimer( $soumis ) > $max_tokens_consignes && $soumis !== $consignes_actuelles ) {
					// Une valeur existante trop longue, renvoyée telle quelle par le
					// formulaire, est acceptée : sinon chaque enregistrement (même un
					// simple changement de modèle) afficherait une erreur. L'admin voit
					// un avertissement tant qu'il ne l'a pas raccourcie.
					$erreurs['system_prompt'] = self::ERREUR_TROP_LONG;
				} else {
					$valeurs['system_prompt'] = $soumis;
				}
			}
		}

		return array(
			'valeurs' => $valeurs,
			'erreurs' => $erreurs,
		);
	}

	/**
	 * Vrai si des consignes dépassent la limite (sert à l'avertissement de l'admin).
	 */
	public function consignesTropLongues( string $consignes, int $max_tokens_consignes ): bool {
		return TokenEstimator::estimer( $this->texte( $consignes ) ) > $max_tokens_consignes;
	}

	private function controlerTexte( string $valeur, int $max, bool $obligatoire ): ?string {
		if ( '' === $valeur ) {
			return $obligatoire ? self::ERREUR_OBLIGATOIRE : null;
		}
		if ( $this->contientFragmentInterdit( $valeur ) ) {
			return self::ERREUR_INTERDIT;
		}
		if ( TokenEstimator::caracteres( $valeur ) > $max ) {
			return self::ERREUR_TROP_LONG;
		}

		return null;
	}

	/**
	 * @param mixed $soumis
	 * @return array{json: string, erreur: string|null}
	 */
	private function validerQuestions( $soumis ): array {
		if ( ! is_array( $soumis ) ) {
			return array( 'json' => '', 'erreur' => self::ERREUR_INVALIDE );
		}

		$questions = array();
		foreach ( $soumis as $question ) {
			if ( ! is_string( $question ) ) {
				return array( 'json' => '', 'erreur' => self::ERREUR_INVALIDE );
			}
			$question = $this->texte( $question );
			if ( '' === $question ) {
				continue;
			}
			$erreur = $this->controlerTexte( $question, self::QUESTION_MAX, false );
			if ( null !== $erreur ) {
				return array( 'json' => '', 'erreur' => $erreur );
			}
			$questions[] = $question;
		}

		if ( count( $questions ) > self::QUESTIONS_MAX ) {
			return array( 'json' => '', 'erreur' => self::ERREUR_TROP_DE_QUESTIONS );
		}

		$json = json_encode( $questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json
			? array( 'json' => '', 'erreur' => self::ERREUR_INVALIDE )
			: array( 'json' => $json, 'erreur' => null );
	}

	/**
	 * @param array<string, mixed> $brut
	 * @param array<string, mixed> $actuel
	 * @param array<int, string>   $autorisees
	 */
	private function choix( array $brut, array $actuel, string $champ, array $autorisees, string $defaut ): string {
		$valeur = array_key_exists( $champ, $brut ) ? $brut[ $champ ] : ( $actuel[ $champ ] ?? $defaut );

		return is_string( $valeur ) && in_array( $valeur, $autorisees, true ) ? $valeur : $defaut;
	}

	private function contientFragmentInterdit( string $valeur ): bool {
		foreach ( self::FRAGMENTS_INTERDITS as $fragment ) {
			if ( false !== stripos( $valeur, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise une valeur : fins de ligne en \n (les navigateurs envoient \r\n
	 * pour un textarea), espaces de début et de fin retirés.
	 *
	 * @param mixed $valeur
	 */
	private function texte( $valeur ): string {
		return is_scalar( $valeur ) ? trim( str_replace( "\r\n", "\n", (string) $valeur ) ) : '';
	}
}
