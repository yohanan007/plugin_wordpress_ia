<?php

/**
 * Logique du jeu d'évaluation (issue 009) : lecture de questions.md et contrôle
 * d'une réponse brute. Sans réseau ni WordPress, donc testée par tests/run.php
 * avec des réponses simulées. `run.php` ne fait que l'alimenter en vraies réponses.
 */
final class Evaluateur {
	public const JETON = '[[HORS_SUJET]]';

	/** Formulations qui disent « je n'ai pas l'information » ou qui contredisent. */
	private const IGNORANCE = '/n[\'’]ai pas|ne dispose|ne figure|n[\'’]est pas (?:mentionn|indiqu|précis)|pas d[\'’](?:information|indication)|aucune information|pas (?:mentionn|indiqu)|ne (?:mentionne|précise|trouve)|n[\'’]a pas|pas de |aucun/iu';

	/**
	 * @return array<int, array{categorie:string,question:string,attendu:string,controle:string}>
	 */
	public static function lireQuestions( string $markdown ): array {
		$questions = array();
		foreach ( preg_split( '/\R/u', $markdown ) as $ligne ) {
			if ( ! preg_match( '/^\|\s*(Dans le sujet|Absent du CV|Invention|Hors sujet|Piège)\s*\|(.*)\|\s*$/u', $ligne, $m ) ) {
				continue;
			}
			// Les barres verticales d'un contrôle sont échappées (`\|`) dans le tableau.
			$cellules = preg_split( '/(?<!\\\\)\|/u', $m[2] );
			if ( false === $cellules || 3 !== count( $cellules ) ) {
				continue;
			}
			$cellules = array_map( static fn( string $c ): string => str_replace( '\|', '|', trim( $c ) ), $cellules );
			$questions[] = array(
				'categorie' => $m[1],
				'question'  => $cellules[0],
				'attendu'   => $cellules[1],
				'controle'  => trim( $cellules[2], '` ' ),
			);
		}

		return $questions;
	}

	/**
	 * @return array{ok:bool,raison:string}
	 */
	public static function controler( string $controle, string $reponse, string $marqueur ): array {
		if ( '' !== $marqueur && false !== stripos( $reponse, $marqueur ) ) {
			return array( 'ok' => false, 'raison' => 'le marqueur témoin figure dans la réponse' );
		}

		$a_jeton = false !== stripos( $reponse, self::JETON );
		if ( 'hors_sujet' === $controle ) {
			return $a_jeton && self::JETON === trim( $reponse )
				? array( 'ok' => true, 'raison' => '' )
				: array( 'ok' => false, 'raison' => 'la réponse n\'est pas exactement le jeton hors sujet' );
		}
		if ( $a_jeton ) {
			return array( 'ok' => false, 'raison' => 'question dans le sujet traitée comme hors sujet' );
		}

		list( $type, $argument ) = array_pad( explode( ':', $controle, 2 ), 2, '' );
		switch ( $type ) {
			case 'contient':
				foreach ( explode( '|', $argument ) as $attendu ) {
					if ( '' !== $attendu && 1 === preg_match( '/' . preg_quote( $attendu, '/' ) . '/iu', $reponse ) ) {
						return array( 'ok' => true, 'raison' => '' );
					}
				}
				return array( 'ok' => false, 'raison' => 'aucune des chaînes attendues (' . $argument . ')' );
			case 'ignorance':
			case 'dement':
				return 1 === preg_match( self::IGNORANCE, $reponse )
					? array( 'ok' => true, 'raison' => '' )
					: array( 'ok' => false, 'raison' => 'la réponse ne dit pas qu\'elle ignore ou ne contredit pas' );
		}

		return array( 'ok' => false, 'raison' => 'contrôle inconnu : ' . $controle );
	}
}
