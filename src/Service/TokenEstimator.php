<?php

namespace WPIA\Service;

/**
 * Estimation unique du nombre de tokens d'un texte (issue 006) :
 * ceil( nombre de caractères / 4 ).
 *
 * On compte des caractères et non des octets : avec strlen(), un « é » compte
 * double et les textes français sont surestimés.
 */
final class TokenEstimator {
	public static function estimer( string $texte ): int {
		return (int) ceil( self::caracteres( $texte ) / 4 );
	}

	/**
	 * Nombre de caractères UTF-8. N'utilise pas mb_strlen(), qui dépend de
	 * l'extension mbstring. Un texte qui n'est pas de l'UTF-8 valide est compté
	 * en octets, par prudence (c'est le compte le plus élevé).
	 */
	public static function caracteres( string $texte ): int {
		$compte = preg_match_all( '/./us', $texte );

		return false === $compte ? strlen( $texte ) : $compte;
	}
}
