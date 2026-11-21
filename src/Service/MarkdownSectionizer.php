<?php

namespace WPIA\Service;

/**
 * Découpe un document Markdown en sections bornées (issue 006, lot A).
 *
 * Fonction pure, sans WordPress : le découpage se fait à l'import et à la
 * réindexation uniquement, jamais à chaque question.
 *
 * Règles :
 * - une section commence à un titre `#`, `##` ou `###` (les `#` situés dans un
 *   bloc de code « ``` » ou « ~~~ » sont ignorés) ;
 * - le texte avant le premier titre forme la section « Introduction » ;
 * - une section de plus de $max_tokens tokens (estimés sur le texte échappé,
 *   tel qu'il sera envoyé) est recoupée par paragraphes ;
 * - une section sans contenu est ignorée.
 */
final class MarkdownSectionizer {
	public const INTRODUCTION = 'Introduction';

	/**
	 * @return array<int, array{titre:string,contenu:string,tokens:int}>
	 */
	public function decouper( string $markdown, int $max_tokens = 800 ): array {
		$max_tokens = max( 1, $max_tokens );
		$lignes     = preg_split( '/\R/u', $markdown );
		if ( false === $lignes ) {
			$lignes = explode( "\n", $markdown );
		}

		$brutes  = array();
		$titre   = self::INTRODUCTION;
		$corps   = array();
		$fence   = '';

		foreach ( $lignes as $ligne ) {
			if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/', $ligne, $m ) ) {
				if ( '' === $fence ) {
					$fence = $m[1][0];
				} elseif ( $fence === $m[1][0] ) {
					$fence = '';
				}
			} elseif ( '' === $fence && preg_match( '/^ {0,3}#{1,3}[ \t]+(.+?)[ \t]*#*[ \t]*$/u', $ligne, $m ) ) {
				$brutes[] = array( $titre, implode( "\n", $corps ) );
				$titre    = trim( $m[1] );
				$corps    = array();
				continue;
			}
			$corps[] = $ligne;
		}
		$brutes[] = array( $titre, implode( "\n", $corps ) );

		$sections = array();
		foreach ( $brutes as list( $titre, $contenu ) ) {
			$contenu = trim( $contenu );
			if ( '' === $contenu ) {
				continue;
			}
			foreach ( $this->recouper( $contenu, $max_tokens ) as $morceau ) {
				$sections[] = array(
					'titre'   => $titre,
					'contenu' => $morceau,
					'tokens'  => self::tokens( $morceau ),
				);
			}
		}

		return $sections;
	}

	/**
	 * Échappe `&`, `<` et `>` : un document ne doit pas pouvoir fermer le bloc
	 * `<documents>` dans lequel il sera placé (issue 006, lot B).
	 */
	public static function echapper( string $texte ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $texte );
	}

	/**
	 * Tokens d'un texte tel qu'il sera envoyé, c'est-à-dire échappé.
	 */
	public static function tokens( string $texte ): int {
		return TokenEstimator::estimer( self::echapper( $texte ) );
	}

	/**
	 * @return array<int, string>
	 */
	private function recouper( string $contenu, int $max_tokens ): array {
		if ( self::tokens( $contenu ) <= $max_tokens ) {
			return array( $contenu );
		}

		$morceaux = array();
		$courant  = '';
		foreach ( $this->paragraphes( $contenu ) as $paragraphe ) {
			foreach ( $this->bornerParagraphe( $paragraphe, $max_tokens ) as $partie ) {
				$candidat = '' === $courant ? $partie : $courant . "\n\n" . $partie;
				if ( '' !== $courant && self::tokens( $candidat ) > $max_tokens ) {
					$morceaux[] = $courant;
					$courant    = $partie;
				} else {
					$courant = $candidat;
				}
			}
		}
		if ( '' !== $courant ) {
			$morceaux[] = $courant;
		}

		return $morceaux;
	}

	/**
	 * Paragraphes séparés par une ligne vide, hors blocs de code (un bloc de code
	 * contenant une ligne vide reste d'un seul tenant).
	 *
	 * @return array<int, string>
	 */
	private function paragraphes( string $contenu ): array {
		$paragraphes = array();
		$courant     = array();
		$fence       = '';

		foreach ( preg_split( '/\R/u', $contenu ) ?: array() as $ligne ) {
			if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/', $ligne, $m ) ) {
				if ( '' === $fence ) {
					$fence = $m[1][0];
				} elseif ( $fence === $m[1][0] ) {
					$fence = '';
				}
			}
			if ( '' === $fence && '' === trim( $ligne ) ) {
				if ( ! empty( $courant ) ) {
					$paragraphes[] = implode( "\n", $courant );
					$courant       = array();
				}
				continue;
			}
			$courant[] = $ligne;
		}
		if ( ! empty( $courant ) ) {
			$paragraphes[] = implode( "\n", $courant );
		}

		return $paragraphes;
	}

	/**
	 * Un paragraphe plus grand que le plafond (bloc de code énorme, ligne
	 * interminable) est coupé par lignes, puis par caractères en dernier recours.
	 *
	 * @return array<int, string>
	 */
	private function bornerParagraphe( string $paragraphe, int $max_tokens ): array {
		if ( self::tokens( $paragraphe ) <= $max_tokens ) {
			return array( $paragraphe );
		}

		$parties = array();
		$courant = '';
		foreach ( preg_split( '/\R/u', $paragraphe ) ?: array() as $ligne ) {
			foreach ( $this->couperLigne( $ligne, $max_tokens ) as $morceau ) {
				$candidat = '' === $courant ? $morceau : $courant . "\n" . $morceau;
				if ( '' !== $courant && self::tokens( $candidat ) > $max_tokens ) {
					$parties[] = $courant;
					$courant   = $morceau;
				} else {
					$courant = $candidat;
				}
			}
		}
		if ( '' !== $courant ) {
			$parties[] = $courant;
		}

		return $parties;
	}

	/**
	 * @return array<int, string>
	 */
	private function couperLigne( string $ligne, int $max_tokens ): array {
		if ( self::tokens( $ligne ) <= $max_tokens ) {
			return array( $ligne );
		}

		// Le texte échappé peut multiplier la taille par 5 au pire (« & » devient
		// « &amp; ») : on découpe sur une taille prudente en caractères.
		$taille = max( 1, intdiv( $max_tokens * 4, 5 ) );
		$chars  = preg_split( '//u', $ligne, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			$chars = str_split( $ligne );
		}

		return array_map( 'implode', array_chunk( $chars, $taille ) );
	}
}
