<?php

declare(strict_types=1);

use WPIA\Service\Usage\UsageKey;
use WPIA\Service\Usage\UsageStore;

/**
 * Double de test reproduisant la sémantique de LimiteUtilisationRepository :
 * INSERT IGNORE puis UPDATE conditionnel (aucune modification si un plafond
 * serait dépassé), plancher à 0 pour les corrections.
 */
class InMemoryUsageStore implements UsageStore {
	/** @var array<string, array{key:UsageKey,nb_messages:int,nb_tokens:int,alerte_envoyee:bool,date_maj:string}> */
	public array $rows = array();
	public bool $failNext = false;

	public function reserve( UsageKey $key, int $tokens, int $messages, ?int $max_tokens, ?int $max_messages, string $now ): bool {
		$this->maybeFail();
		$id = $this->ensure( $key, $now );
		if ( null !== $max_tokens && $this->rows[ $id ]['nb_tokens'] + $tokens > $max_tokens ) {
			return false;
		}
		if ( null !== $max_messages && $this->rows[ $id ]['nb_messages'] + $messages > $max_messages ) {
			return false;
		}
		$this->rows[ $id ]['nb_tokens']   += $tokens;
		$this->rows[ $id ]['nb_messages'] += $messages;
		$this->rows[ $id ]['date_maj']     = $now;

		return true;
	}

	public function adjust( UsageKey $key, int $delta_tokens, int $delta_messages, string $now ): void {
		$this->maybeFail();
		$id = $this->ensure( $key, $now );
		$this->rows[ $id ]['nb_tokens']   = max( 0, $this->rows[ $id ]['nb_tokens'] + $delta_tokens );
		$this->rows[ $id ]['nb_messages'] = max( 0, $this->rows[ $id ]['nb_messages'] + $delta_messages );
		$this->rows[ $id ]['date_maj']    = $now;
	}

	public function get( UsageKey $key ): array {
		$this->maybeFail();
		$row = $this->rows[ $this->id( $key ) ] ?? null;

		return array(
			'nb_messages'    => $row['nb_messages'] ?? 0,
			'nb_tokens'      => $row['nb_tokens'] ?? 0,
			'alerte_envoyee' => $row['alerte_envoyee'] ?? false,
		);
	}

	public function markAlertSent( UsageKey $key ): bool {
		$id = $this->id( $key );
		if ( ! isset( $this->rows[ $id ] ) || $this->rows[ $id ]['alerte_envoyee'] ) {
			return false;
		}
		$this->rows[ $id ]['alerte_envoyee'] = true;

		return true;
	}

	public function purgeInactiveBefore( string $cutoff ): int {
		$deleted = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( in_array( $row['key']->portee, array( UsageKey::VISITEUR, UsageKey::SESSION ), true ) && $row['date_maj'] < $cutoff ) {
				unset( $this->rows[ $id ] );
				++$deleted;
			}
		}

		return $deleted;
	}

	public function tokens( UsageKey $key ): int {
		return $this->rows[ $this->id( $key ) ]['nb_tokens'] ?? 0;
	}

	public function messages( UsageKey $key ): int {
		return $this->rows[ $this->id( $key ) ]['nb_messages'] ?? 0;
	}

	private function ensure( UsageKey $key, string $now ): string {
		$id = $this->id( $key );
		if ( ! isset( $this->rows[ $id ] ) ) {
			$this->rows[ $id ] = array(
				'key'            => $key,
				'nb_messages'    => 0,
				'nb_tokens'      => 0,
				'alerte_envoyee' => false,
				'date_maj'       => $now,
			);
		}

		return $id;
	}

	private function id( UsageKey $key ): string {
		return implode( '|', array( $key->chatbotId, $key->portee, $key->identifiant, $key->jour ) );
	}

	private function maybeFail(): void {
		if ( $this->failNext ) {
			$this->failNext = false;
			throw new RuntimeException( 'Simulated database failure.' );
		}
	}
}
