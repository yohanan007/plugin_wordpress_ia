<?php

namespace WPIA\Admin;

use WPIA\Service\Prompt\CadrageValidator;

/**
 * Traite les champs de cadrage soumis par le formulaire de configuration IA
 * (issue 009) : validation sur le texte brut par CadrageValidator, nettoyage
 * WordPress des seules valeurs acceptées, messages d'erreur par champ.
 *
 * Un champ refusé garde sa valeur précédente et n'empêche pas l'enregistrement
 * des autres champs ni du fournisseur, du compte et du modèle.
 */
final class CadrageSanitizer {
	/** Champs du formulaire traités ici (les 7 colonnes de cadrage sauf le marqueur, plus les consignes). */
	public const CHAMPS = array( 'sujet', 'ton', 'langue', 'message_accueil', 'message_hors_sujet', 'questions_suggerees', 'system_prompt' );

	private CadrageValidator $validator;

	public function __construct( ?CadrageValidator $validator = null ) {
		$this->validator = $validator ?? new CadrageValidator();
	}

	/**
	 * @param array<string, mixed> $input                Formulaire, après wp_unslash() mais avant tout nettoyage.
	 * @param array<string, mixed> $actuel               Ligne wpia_chatbot actuelle (vide à la création).
	 * @param int                  $max_tokens_consignes Réglage prompt_admin_max_tokens.
	 * @return array{valeurs: array<string, string>, messages: array<string, string>}
	 *         `messages` associe un champ refusé à son message, déjà traduit.
	 */
	public function traiter( array $input, array $actuel, int $max_tokens_consignes ): array {
		$brut = array_intersect_key( $input, array_flip( self::CHAMPS ) );

		// Réponse hors sujet pré-remplie : tant qu'aucune n'est enregistrée et que le
		// formulaire n'en envoie pas, le texte par défaut s'applique.
		if ( '' === trim( (string) ( $actuel['message_hors_sujet'] ?? '' ) ) && ! array_key_exists( 'message_hors_sujet', $brut ) ) {
			$brut['message_hors_sujet'] = self::messageHorsSujetParDefaut();
		}

		$resultat = $this->validator->valider( $brut, $actuel, $max_tokens_consignes );
		$valeurs  = $resultat['valeurs'];
		$erreurs  = $resultat['erreurs'];

		// Nettoyage des seules valeurs acceptées (une valeur refusée est la valeur
		// précédente, déjà nettoyée à son enregistrement).
		foreach ( array( 'sujet', 'message_accueil', 'message_hors_sujet' ) as $champ ) {
			if ( isset( $erreurs[ $champ ] ) || ! array_key_exists( $champ, $brut ) ) {
				continue;
			}
			$propre = sanitize_text_field( $valeurs[ $champ ] );
			if ( '' === $propre && in_array( $champ, array( 'sujet', 'message_hors_sujet' ), true ) ) {
				// Des balises seules donnent une valeur vide après nettoyage.
				$erreurs[ $champ ]  = CadrageValidator::ERREUR_OBLIGATOIRE;
				$valeurs[ $champ ] = trim( (string) ( $actuel[ $champ ] ?? '' ) );
				continue;
			}
			$valeurs[ $champ ] = $propre;
		}
		if ( ! isset( $erreurs['system_prompt'] ) && array_key_exists( 'system_prompt', $brut ) ) {
			$valeurs['system_prompt'] = sanitize_textarea_field( $valeurs['system_prompt'] );
		}
		if ( ! isset( $erreurs['questions_suggerees'] ) && array_key_exists( 'questions_suggerees', $brut ) ) {
			$valeurs['questions_suggerees'] = $this->nettoyerQuestions( $valeurs['questions_suggerees'] );
		}

		$messages = array();
		foreach ( $erreurs as $champ => $code ) {
			$messages[ $champ ] = self::message( $champ, $code, $max_tokens_consignes );
		}

		return array(
			'valeurs'  => $valeurs,
			'messages' => $messages,
		);
	}

	/**
	 * Nouveau marqueur témoin, distinct pour chaque chatbot.
	 */
	public static function nouveauMarqueur(): string {
		return 'wpia-' . bin2hex( random_bytes( 8 ) );
	}

	public static function messageHorsSujetParDefaut(): string {
		return __( 'Je ne peux répondre qu’aux questions sur le sujet de ce site. Reformulez votre question dans ce cadre.', 'plugin-wordpress-ia' );
	}

	public static function message( string $champ, string $code, int $max_tokens_consignes ): string {
		$libelles = array(
			'sujet'               => __( 'Le sujet', 'plugin-wordpress-ia' ),
			'ton'                 => __( 'Le ton', 'plugin-wordpress-ia' ),
			'langue'              => __( 'La langue', 'plugin-wordpress-ia' ),
			'message_accueil'     => __( 'Le message d’accueil', 'plugin-wordpress-ia' ),
			'message_hors_sujet'  => __( 'La réponse hors sujet', 'plugin-wordpress-ia' ),
			'questions_suggerees' => __( 'Les questions suggérées', 'plugin-wordpress-ia' ),
			'system_prompt'       => __( 'Les consignes complémentaires', 'plugin-wordpress-ia' ),
		);
		$libelle = $libelles[ $champ ] ?? $champ;

		switch ( $code ) {
			case CadrageValidator::ERREUR_OBLIGATOIRE:
				$detail = __( 'est obligatoire.', 'plugin-wordpress-ia' );
				break;
			case CadrageValidator::ERREUR_TROP_LONG:
				$detail = 'system_prompt' === $champ
					/* translators: %d: maximum number of tokens. */
					? sprintf( __( 'dépassent la limite de %d tokens.', 'plugin-wordpress-ia' ), $max_tokens_consignes )
					: __( 'dépasse la longueur maximale.', 'plugin-wordpress-ia' );
				break;
			case CadrageValidator::ERREUR_INTERDIT:
				$detail = __( 'contient un fragment réservé au plugin (balise de données ou jeton interne).', 'plugin-wordpress-ia' );
				break;
			case CadrageValidator::ERREUR_TROP_DE_QUESTIONS:
				/* translators: %d: maximum number of suggested questions. */
				$detail = sprintf( __( 'sont limitées à %d questions.', 'plugin-wordpress-ia' ), CadrageValidator::QUESTIONS_MAX );
				break;
			default:
				$detail = __( 'est invalide.', 'plugin-wordpress-ia' );
		}

		/* translators: 1: field name, 2: reason. */
		return sprintf( __( '%1$s %2$s La valeur précédente est conservée.', 'plugin-wordpress-ia' ), $libelle, $detail );
	}

	private function nettoyerQuestions( string $json ): string {
		$questions = json_decode( $json, true );
		if ( ! is_array( $questions ) ) {
			return '[]';
		}

		$propres = array();
		foreach ( $questions as $question ) {
			$question = sanitize_text_field( (string) $question );
			if ( '' !== $question ) {
				$propres[] = $question;
			}
		}

		return (string) json_encode( $propres, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
