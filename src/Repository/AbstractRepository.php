<?php

namespace WPIA\Repository;

use InvalidArgumentException;
use RuntimeException;

abstract class AbstractRepository {
	protected string $table_suffix;

	/**
	 * Allowed database columns and their wpdb placeholder formats.
	 *
	 * @var array<string, string>
	 */
	protected array $columns = array();

	protected \wpdb $wpdb;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	public function create( array $data ): int {
		$data = $this->validateData( $data );
		$this->clearDatabaseError();

		$result = $this->wpdb->insert(
			$this->getTableName(),
			$data,
			$this->getFormats( $data )
		);
		if ( false === $result ) {
			$this->throwDatabaseError();
		}

		$id = (int) $this->wpdb->insert_id;
		if ( $id < 1 ) {
			throw new RuntimeException( 'The database did not return a valid record identifier.' );
		}

		return $id;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getById( int $id ): ?array {
		$this->assertPositiveId( $id );
		$this->clearDatabaseError();

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM ' . $this->getTableName() . ' WHERE `id` = %d',
				$id
			),
			\ARRAY_A
		);

		if ( '' !== $this->wpdb->last_error ) {
			$this->throwDatabaseError();
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, scalar> $criteria
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll( array $criteria = array(), int $limit = 100, int $offset = 0 ): array {
		if ( $limit < 1 || $offset < 0 ) {
			throw new InvalidArgumentException( 'The result limit must be positive and offset non-negative.' );
		}

		$criteria = $this->validateData( $criteria, true );
		$sql      = 'SELECT * FROM ' . $this->getTableName();
		$values   = array_values( $criteria );

		if ( ! empty( $criteria ) ) {
			$conditions = array();
			foreach ( array_keys( $criteria ) as $column ) {
				$conditions[] = '`' . $column . '` = ' . $this->columns[ $column ];
			}
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		$sql     .= ' ORDER BY `id` ASC LIMIT %d OFFSET %d';
		$values[] = $limit;
		$values[] = $offset;
		$this->clearDatabaseError();

		$prepared = $this->wpdb->prepare( $sql, ...$values );
		$rows     = $this->wpdb->get_results( $prepared, \ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			$this->throwDatabaseError();
		}

		return is_array( $rows ) ? $rows : array();
	}

	public function update( int $id, array $data ): int {
		$this->assertPositiveId( $id );
		$data = $this->validateData( $data );
		$this->clearDatabaseError();

		$result = $this->wpdb->update(
			$this->getTableName(),
			$data,
			array( 'id' => $id ),
			$this->getFormats( $data ),
			array( '%d' )
		);
		if ( false === $result ) {
			$this->throwDatabaseError();
		}

		return $result;
	}

	public function delete( int $id ): int {
		$this->assertPositiveId( $id );
		$this->clearDatabaseError();

		$result = $this->wpdb->delete(
			$this->getTableName(),
			array( 'id' => $id ),
			array( '%d' )
		);
		if ( false === $result ) {
			$this->throwDatabaseError();
		}

		return $result;
	}

	/**
	 * @param array<string, scalar> $criteria
	 */
	public function countAll( array $criteria = array() ): int {
		$criteria = $this->validateData( $criteria, true );
		$sql      = 'SELECT COUNT(*) FROM ' . $this->getTableName();
		$values   = array_values( $criteria );

		if ( ! empty( $criteria ) ) {
			$conditions = array();
			foreach ( array_keys( $criteria ) as $column ) {
				$conditions[] = '`' . $column . '` = ' . $this->columns[ $column ];
			}
			$sql .= ' WHERE ' . implode( ' AND ', $conditions );
		}

		$this->clearDatabaseError();
		$count = $this->wpdb->get_var(
			empty( $values ) ? $sql : $this->wpdb->prepare( $sql, ...$values )
		);

		if ( '' !== $this->wpdb->last_error ) {
			$this->throwDatabaseError();
		}

		return (int) $count;
	}

	private function getTableName(): string {
		return $this->wpdb->prefix . 'wpia_' . $this->table_suffix;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, scalar>
	 */
	private function validateData( array $data, bool $allow_empty = false ): array {
		if ( empty( $data ) && ! $allow_empty ) {
			throw new InvalidArgumentException( 'At least one field is required.' );
		}

		foreach ( $data as $column => $value ) {
			if ( ! isset( $this->columns[ $column ] ) ) {
				throw new InvalidArgumentException( 'Unsupported database field: ' . $column );
			}
			if ( ! is_scalar( $value ) ) {
				throw new InvalidArgumentException( 'Database values must be scalar.' );
			}
		}

		return $data;
	}

	/**
	 * @param array<string, scalar> $data
	 * @return array<int, string>
	 */
	private function getFormats( array $data ): array {
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = $this->columns[ $column ];
		}

		return $formats;
	}

	private function assertPositiveId( int $id ): void {
		if ( $id < 1 ) {
			throw new InvalidArgumentException( 'The identifier must be positive.' );
		}
	}

	private function clearDatabaseError(): void {
		$this->wpdb->last_error = '';
	}

	private function throwDatabaseError(): void {
		throw new RuntimeException( 'Database operation failed: ' . $this->wpdb->last_error );
	}
}
