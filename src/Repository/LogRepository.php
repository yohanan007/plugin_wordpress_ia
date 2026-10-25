<?php

namespace WPIA\Repository;

use RuntimeException;

class LogRepository {
	private \wpdb $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function create( string $level, string $category, string $message ): void {
		$table = $this->wpdb->prefix . 'wpia_log';
		$result = $this->wpdb->insert(
			$table,
			array(
				'niveau'        => sanitize_key( $level ),
				'categorie'     => sanitize_key( $category ),
				'message'       => substr( sanitize_textarea_field( $message ), 0, 4000 ),
				'date_creation' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to write the application log: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll( int $limit = 50, int $offset = 0, string $category = '' ): array {
		$table  = $this->wpdb->prefix . 'wpia_log';
		$sql    = 'SELECT id, niveau, categorie, message, date_creation FROM ' . $table;
		$values = array();

		if ( '' !== $category ) {
			$sql     .= ' WHERE categorie = %s';
			$values[] = sanitize_key( $category );
		}

		$sql     .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$values[] = max( 1, min( 100, $limit ) );
		$values[] = max( 0, $offset );
		$rows     = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$values ), \ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to retrieve application logs: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	public function count( string $category = '' ): int {
		$table = $this->wpdb->prefix . 'wpia_log';
		$sql   = 'SELECT COUNT(*) FROM ' . $table;
		$count = '' === $category
			? $this->wpdb->get_var( $sql )
			: $this->wpdb->get_var( $this->wpdb->prepare( $sql . ' WHERE categorie = %s', sanitize_key( $category ) ) );

		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to count application logs: ' . $this->wpdb->last_error );
		}

		return (int) $count;
	}

	public function deleteBefore( string $date ): int {
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'DELETE FROM ' . $this->wpdb->prefix . 'wpia_log WHERE date_creation < %s',
				$date
			)
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to purge application logs: ' . $this->wpdb->last_error );
		}

		return (int) $result;
	}

	public function delete( int $id ): int {
		$result = $this->wpdb->delete(
			$this->wpdb->prefix . 'wpia_log',
			array( 'id' => absint( $id ) ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to delete the application log: ' . $this->wpdb->last_error );
		}

		return (int) $result;
	}
}
