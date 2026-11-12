<?php

namespace WPIA\Repository;

use RuntimeException;
use WPIA\Service\Usage\UsageKey;
use WPIA\Service\Usage\UsageStore;

class LimiteUtilisationRepository extends AbstractRepository implements UsageStore {
	protected string $table_suffix = 'limite_utilisation';

	protected array $columns = array(
		'chatbot_id'     => '%d',
		'portee'         => '%s',
		'identifiant'    => '%s',
		'jour'           => '%s',
		'nb_messages'    => '%d',
		'nb_tokens'      => '%d',
		'alerte_envoyee' => '%d',
		'date_maj'       => '%s',
	);

	public function reserve( UsageKey $key, int $tokens, int $messages, ?int $max_tokens, ?int $max_messages, string $now ): bool {
		$this->ensureRow( $key, $now );

		// L'UPDATE conditionnel est l'opération atomique : la condition et
		// l'incrément sont évalués sous le même verrou de ligne InnoDB.
		$sql    = 'UPDATE ' . $this->table() . ' SET nb_tokens = nb_tokens + %d, nb_messages = nb_messages + %d, date_maj = %s WHERE ' . $this->keyCondition();
		$values = array_merge( array( max( 0, $tokens ), max( 0, $messages ), $now ), $this->keyValues( $key ) );
		if ( null !== $max_tokens ) {
			$sql     .= ' AND nb_tokens + %d <= %d';
			$values[] = max( 0, $tokens );
			$values[] = $max_tokens;
		}
		if ( null !== $max_messages ) {
			$sql     .= ' AND nb_messages + %d <= %d';
			$values[] = max( 0, $messages );
			$values[] = $max_messages;
		}

		return 1 === $this->execute( $sql, $values );
	}

	public function adjust( UsageKey $key, int $delta_tokens, int $delta_messages, string $now ): void {
		if ( 0 === $delta_tokens && 0 === $delta_messages ) {
			return;
		}

		$this->ensureRow( $key, $now );
		// Colonnes non signées : une soustraction négative provoquerait une erreur
		// MySQL, d'où le plancher explicite à 0.
		$sql = 'UPDATE ' . $this->table() . ' SET '
			. $this->deltaExpression( 'nb_tokens', $delta_tokens ) . ', '
			. $this->deltaExpression( 'nb_messages', $delta_messages ) . ', '
			. 'date_maj = %s WHERE ' . $this->keyCondition();
		$values = array_merge(
			$this->deltaValues( $delta_tokens ),
			$this->deltaValues( $delta_messages ),
			array( $now ),
			$this->keyValues( $key )
		);

		$this->execute( $sql, $values );
	}

	public function get( UsageKey $key ): array {
		$this->wpdb->last_error = '';
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT nb_messages, nb_tokens, alerte_envoyee FROM ' . $this->table() . ' WHERE ' . $this->keyCondition(),
				...$this->keyValues( $key )
			),
			\ARRAY_A
		);
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Database operation failed: ' . $this->wpdb->last_error );
		}

		return array(
			'nb_messages'    => is_array( $row ) ? (int) $row['nb_messages'] : 0,
			'nb_tokens'      => is_array( $row ) ? (int) $row['nb_tokens'] : 0,
			'alerte_envoyee' => is_array( $row ) && 1 === (int) $row['alerte_envoyee'],
		);
	}

	public function markAlertSent( UsageKey $key ): bool {
		return 1 === $this->execute(
			'UPDATE ' . $this->table() . ' SET alerte_envoyee = 1 WHERE ' . $this->keyCondition() . ' AND alerte_envoyee = 0',
			$this->keyValues( $key )
		);
	}

	public function purgeInactiveBefore( string $cutoff ): int {
		return $this->execute(
			'DELETE FROM ' . $this->table() . ' WHERE portee IN (%s, %s) AND date_maj < %s',
			array( UsageKey::VISITEUR, UsageKey::SESSION, $cutoff )
		);
	}

	private function ensureRow( UsageKey $key, string $now ): void {
		$this->execute(
			'INSERT IGNORE INTO ' . $this->table() . ' (chatbot_id, portee, identifiant, jour, nb_messages, nb_tokens, alerte_envoyee, date_maj) VALUES (%d, %s, %s, %s, 0, 0, 0, %s)',
			array_merge( $this->keyValues( $key ), array( $now ) )
		);
	}

	private function deltaExpression( string $column, int $delta ): string {
		return $delta >= 0
			? $column . ' = ' . $column . ' + %d'
			: $column . ' = IF(' . $column . ' > %d, ' . $column . ' - %d, 0)';
	}

	/**
	 * @return array<int, int>
	 */
	private function deltaValues( int $delta ): array {
		return $delta >= 0 ? array( $delta ) : array( -$delta, -$delta );
	}

	private function keyCondition(): string {
		return 'chatbot_id = %d AND portee = %s AND identifiant = %s AND jour = %s';
	}

	/**
	 * @return array<int, int|string>
	 */
	private function keyValues( UsageKey $key ): array {
		return array( $key->chatbotId, $key->portee, $key->identifiant, $key->jour );
	}

	/**
	 * @param array<int, int|string> $values
	 */
	private function execute( string $sql, array $values ): int {
		$this->wpdb->last_error = '';
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, ...$values ) );
		if ( false === $result ) {
			throw new RuntimeException( 'Database operation failed: ' . $this->wpdb->last_error );
		}

		return (int) $result;
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wpia_limite_utilisation';
	}
}
