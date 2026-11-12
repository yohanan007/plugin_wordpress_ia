<?php

namespace WPIA\Service\Usage;

/**
 * Identifie une ligne de compteur : (chatbot, portée, identifiant, jour).
 */
final class UsageKey {
	public const SITE     = 'site';
	public const VISITEUR = 'visiteur';
	public const SESSION  = 'session';

	/** Jour fixe des compteurs de session, cumulés tous jours confondus. */
	public const JOUR_SESSION = '1970-01-01';

	public int $chatbotId;
	public string $portee;
	public string $identifiant;
	public string $jour;

	public function __construct( int $chatbot_id, string $portee, string $identifiant, string $jour ) {
		$this->chatbotId   = $chatbot_id;
		$this->portee      = $portee;
		$this->identifiant = $identifiant;
		$this->jour        = $jour;
	}

	public static function site( string $jour ): self {
		return new self( 0, self::SITE, '', $jour );
	}

	public static function visiteur( int $chatbot_id, string $identifiant, string $jour ): self {
		return new self( $chatbot_id, self::VISITEUR, $identifiant, $jour );
	}

	public static function session( int $chatbot_id, string $identifiant ): self {
		return new self( $chatbot_id, self::SESSION, $identifiant, self::JOUR_SESSION );
	}
}
