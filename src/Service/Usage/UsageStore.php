<?php

namespace WPIA\Service\Usage;

/**
 * Stockage des compteurs de consommation. Toute erreur de stockage lève une
 * RuntimeException : l'appelant refuse alors la génération (échec fermé).
 */
interface UsageStore {
	/**
	 * Ajoute atomiquement $tokens et $messages à la ligne (créée à 0 si besoin),
	 * uniquement si les plafonds non nuls restent respectés.
	 *
	 * @return bool false si un plafond aurait été dépassé (rien n'est modifié).
	 */
	public function reserve( UsageKey $key, int $tokens, int $messages, ?int $max_tokens, ?int $max_messages, string $now ): bool;

	/**
	 * Applique des écarts (positifs ou négatifs) sans descendre sous 0.
	 */
	public function adjust( UsageKey $key, int $delta_tokens, int $delta_messages, string $now ): void;

	/**
	 * @return array{nb_messages:int,nb_tokens:int,alerte_envoyee:bool}
	 */
	public function get( UsageKey $key ): array;

	/**
	 * Marque l'alerte comme envoyée.
	 *
	 * @return bool true seulement pour l'appel qui a fait passer le drapeau de 0 à 1.
	 */
	public function markAlertSent( UsageKey $key ): bool;

	/**
	 * Supprime les lignes visiteur et session non mises à jour depuis $cutoff.
	 *
	 * @return int Nombre de lignes supprimées.
	 */
	public function purgeInactiveBefore( string $cutoff ): int;
}
