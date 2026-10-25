<?php

namespace WPIA\Repository;

class DocumentRepository extends AbstractRepository {
	protected string $table_suffix = 'document';

	protected array $columns = array(
		'designation'   => '%s',
		'description'   => '%s',
		'fichier'       => '%s',
		'hash'          => '%s',
		'taille_tokens' => '%d',
		'taille_octets' => '%d',
		'date_ajout'    => '%s',
		'statut_indexation' => '%s',
		'actif'         => '%d',
	);

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function search( string $search, string $status, int $limit, int $offset ): array {
		$table  = $this->wpdb->prefix . 'wpia_document';
		$where  = array();
		$values = array();

		if ( '' !== $search ) {
			$where[]  = 'designation LIKE %s';
			$values[] = '%' . $this->wpdb->esc_like( $search ) . '%';
		}
		if ( in_array( $status, array( 'pending', 'ready', 'error' ), true ) ) {
			$where[]  = 'statut_indexation = %s';
			$values[] = $status;
		}

		$sql = 'SELECT * FROM ' . $table;
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql     .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$values[] = max( 1, min( 100, $limit ) );
		$values[] = max( 0, $offset );
		$rows     = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$values ), \ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			throw new \RuntimeException( 'Unable to search documents: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	public function countSearch( string $search, string $status ): int {
		$table  = $this->wpdb->prefix . 'wpia_document';
		$where  = array();
		$values = array();
		if ( '' !== $search ) {
			$where[]  = 'designation LIKE %s';
			$values[] = '%' . $this->wpdb->esc_like( $search ) . '%';
		}
		if ( in_array( $status, array( 'pending', 'ready', 'error' ), true ) ) {
			$where[]  = 'statut_indexation = %s';
			$values[] = $status;
		}

		$sql = 'SELECT COUNT(*) FROM ' . $table;
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$count = empty( $values )
			? $this->wpdb->get_var( $sql )
			: $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$values ) );

		if ( '' !== $this->wpdb->last_error ) {
			throw new \RuntimeException( 'Unable to count documents: ' . $this->wpdb->last_error );
		}

		return (int) $count;
	}
}
