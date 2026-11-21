<?php

namespace WPIA\Service;

use InvalidArgumentException;

/**
 * Transforme un texte en empreintes de mots-clés (issue 006, lot A).
 *
 * Les sections sont stockées chiffrées ; pour pouvoir sélectionner celles qui
 * répondent à une question sans rien stocker en clair, chaque terme normalisé est
 * remplacé par un HMAC. La même normalisation s'applique aux sections et à la
 * question.
 *
 * ATTENTION : le hachage interdit toute correspondance approximative, donc la
 * normalisation est FIGÉE. Toute modification (accents, mots vides, suffixes)
 * change les empreintes : incrémenter VERSION et repasser tous les documents en
 * « pending » pour qu'ils soient réindexés.
 */
final class KeywordNormalizer {
	public const VERSION = 1;

	/** Contexte de dérivation de la clé HMAC (voir SecretCipher::derive()). */
	public const KEY_CONTEXT = 'wpia-keywords-v1';

	/** Nombre de caractères hexadécimaux conservés par empreinte (64 bits). */
	private const HASH_LENGTH = 16;

	private const MIN_LENGTH = 2;
	private const MIN_STEM   = 4;

	private const ACCENTS = array(
		'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
		'ç' => 'c',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
		'ñ' => 'n',
		'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
		'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
		'ÿ' => 'y', 'ý' => 'y',
		'œ' => 'oe', 'æ' => 'ae',
		'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Á' => 'a', 'Ã' => 'a', 'Å' => 'a',
		'Ç' => 'c',
		'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
		'Î' => 'i', 'Ï' => 'i', 'Í' => 'i', 'Ì' => 'i',
		'Ñ' => 'n',
		'Ô' => 'o', 'Ö' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o',
		'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ú' => 'u',
		'Ÿ' => 'y', 'Ý' => 'y',
		'Œ' => 'oe', 'Æ' => 'ae',
	);

	/** Liste fermée de mots vides, français et anglais, sans accents. */
	private const STOPWORDS = array(
		// Français.
		'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'au', 'aux', 'et', 'ou', 'ni', 'mais', 'donc', 'car',
		'que', 'qui', 'quoi', 'dont', 'ce', 'cet', 'cette', 'ces', 'ca', 'cela', 'ceci', 'se', 'sa', 'son', 'ses',
		'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'notre', 'nos', 'votre', 'vos', 'leur', 'leurs', 'je', 'tu', 'il',
		'elle', 'on', 'nous', 'vous', 'ils', 'elles', 'me', 'te', 'lui', 'y', 'en', 'dans', 'sur', 'sous', 'par',
		'pour', 'avec', 'sans', 'chez', 'vers', 'entre', 'est', 'sont', 'etre', 'ete', 'etait', 'sera', 'suis',
		'es', 'sommes', 'etes', 'ai', 'as', 'avons', 'avez', 'ont', 'avoir', 'avait', 'fait', 'faire', 'pas',
		'ne', 'plus', 'moins', 'tres', 'tout', 'tous', 'toute', 'toutes', 'comme', 'si', 'quel', 'quelle',
		'quels', 'quelles', 'quand', 'comment', 'ou', 'aussi', 'ainsi', 'alors', 'puis', 'depuis', 'avant',
		'apres', 'bien', 'peut', 'peux', 'parle',
		// Anglais.
		'the', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'with', 'from', 'as', 'is',
		'are', 'was', 'were', 'be', 'been', 'being', 'am', 'do', 'does', 'did', 'have', 'has', 'had', 'it', 'its',
		'this', 'that', 'these', 'those', 'he', 'she', 'they', 'we', 'you', 'his', 'her', 'their', 'our', 'your',
		'what', 'which', 'who', 'whom', 'how', 'when', 'where', 'not', 'no', 'can', 'could', 'will', 'would',
		'about', 'into', 'than', 'then', 'there', 'so', 'if',
	);

	/** Suffixes retirés après le pluriel, du plus long au plus court. */
	private const SUFFIXES = array( 'ement', 'ation', 'ateur', 'euse', 'eur', 'ment', 'ite', 'ion', 'ee', 'er', 'ez', 'e' );

	private string $key;

	public function __construct( string $key ) {
		if ( '' === $key ) {
			throw new InvalidArgumentException( 'The keyword hashing key cannot be empty.' );
		}
		$this->key = $key;
	}

	/**
	 * Termes normalisés et uniques, avant hachage.
	 *
	 * @return array<int, string>
	 */
	public function termes( string $texte ): array {
		$texte = strtolower( strtr( $texte, self::ACCENTS ) );
		$mots  = preg_split( '/[^\p{L}\p{N}+#]+/u', $texte, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $mots ) {
			return array();
		}

		$termes = array();
		foreach ( $mots as $mot ) {
			// « + » et « # » ne comptent que collés à la fin d'un mot (« c++ », « c# »).
			$mot = ltrim( $mot, '+#' );
			if ( strlen( $mot ) < self::MIN_LENGTH || in_array( $mot, self::STOPWORDS, true ) ) {
				continue;
			}
			$mot = $this->racine( $mot );
			if ( strlen( $mot ) >= self::MIN_LENGTH ) {
				$termes[ $mot ] = true;
			}
		}

		return array_map( 'strval', array_keys( $termes ) );
	}

	/**
	 * Empreintes HMAC uniques des termes d'un texte.
	 *
	 * @return array<int, string>
	 */
	public function empreintes( string $texte ): array {
		$empreintes = array();
		foreach ( $this->termes( $texte ) as $terme ) {
			$empreintes[] = substr( hash_hmac( 'sha256', $terme, $this->key ), 0, self::HASH_LENGTH );
		}

		return $empreintes;
	}

	/**
	 * Empreintes sous la forme stockée dans `wpia_document_section.mots_cles`.
	 */
	public function serialiser( string $texte ): string {
		return implode( ' ', $this->empreintes( $texte ) );
	}

	/**
	 * Racinisation légère du français : pluriel, puis un suffixe courant. Les
	 * termes contenant un chiffre, « + » ou « # » (« php8 », « c++ ») sont gardés tels quels.
	 */
	private function racine( string $mot ): string {
		if ( 1 === preg_match( '/[0-9+#]/', $mot ) ) {
			return $mot;
		}

		$longueur = strlen( $mot );
		if ( $longueur > self::MIN_STEM && ( 's' === $mot[ $longueur - 1 ] || 'x' === $mot[ $longueur - 1 ] ) ) {
			$mot = substr( $mot, 0, -1 );
		}
		foreach ( self::SUFFIXES as $suffixe ) {
			$reste = strlen( $mot ) - strlen( $suffixe );
			if ( $reste >= self::MIN_STEM && str_ends_with( $mot, $suffixe ) ) {
				return substr( $mot, 0, $reste );
			}
		}

		return $mot;
	}
}
