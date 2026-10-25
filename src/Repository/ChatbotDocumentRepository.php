<?php

namespace WPIA\Repository;

use InvalidArgumentException;
use RuntimeException;

class ChatbotDocumentRepository extends AbstractRepository {
	protected string $table_suffix = 'chatbot_document';

	protected array $columns = array(
		'chatbot_id'  => '%d',
		'document_id' => '%d',
		'ordre'       => '%d',
	);

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getOrderedDocuments( int $chatbot_id ): array {
		if ( $chatbot_id < 1 ) {
			throw new InvalidArgumentException( 'The chatbot identifier must be positive.' );
		}

		$table = $this->wpdb->prefix . 'wpia_chatbot_document';
		$sql   = $this->wpdb->prepare(
			'SELECT document_id, ordre FROM ' . $table . ' WHERE chatbot_id = %d ORDER BY ordre ASC, document_id ASC',
			$chatbot_id
		);
		$rows  = $this->wpdb->get_results( $sql, \ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to retrieve the chatbot document order: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<int, int> $document_ids Document identifiers in priority order.
	 */
	public function replaceOrder( int $chatbot_id, array $document_ids ): void {
		if ( $chatbot_id < 1 ) {
			throw new InvalidArgumentException( 'The chatbot identifier must be positive.' );
		}

		$document_ids = array_values( array_unique( array_map( 'absint', $document_ids ) ) );
		if ( in_array( 0, $document_ids, true ) ) {
			throw new InvalidArgumentException( 'Document identifiers must be positive.' );
		}

		$table = $this->wpdb->prefix . 'wpia_chatbot_document';
		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Unable to start the document priority transaction.' );
		}

		try {
			if ( false === $this->wpdb->delete( $table, array( 'chatbot_id' => $chatbot_id ), array( '%d' ) ) ) {
				throw new RuntimeException( 'Unable to clear the previous document order.' );
			}

			foreach ( $document_ids as $order => $document_id ) {
				if ( false === $this->wpdb->insert(
					$table,
					array(
						'chatbot_id'  => $chatbot_id,
						'document_id' => $document_id,
						'ordre'       => $order,
					),
					array( '%d', '%d', '%d' )
				) ) {
					throw new RuntimeException( 'Unable to save the document priority.' );
				}
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Unable to commit the document priority.' );
			}
		} catch ( RuntimeException $exception ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	public function move( int $chatbot_id, int $document_id, string $direction ): void {
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) ) {
			throw new InvalidArgumentException( 'The requested priority direction is invalid.' );
		}

		$rows = $this->getOrderedDocuments( $chatbot_id );
		$ids  = array_map(
			static function ( array $row ): int {
				return (int) $row['document_id'];
			},
			$rows
		);
		$index = array_search( $document_id, $ids, true );
		if ( false === $index ) {
			throw new InvalidArgumentException( 'The document is not assigned to this chatbot.' );
		}

		$target = 'up' === $direction ? $index - 1 : $index + 1;
		if ( ! isset( $ids[ $target ] ) ) {
			return;
		}

		$swap          = $ids[ $index ];
		$ids[ $index ] = $ids[ $target ];
		$ids[ $target ] = $swap;
		$this->replaceOrder( $chatbot_id, $ids );
	}

	public function append( int $chatbot_id, int $document_id ): void {
		if ( $chatbot_id < 1 || $document_id < 1 ) {
			throw new InvalidArgumentException( 'Chatbot and document identifiers must be positive.' );
		}

		$rows = $this->getOrderedDocuments( $chatbot_id );
		foreach ( $rows as $row ) {
			if ( (int) $row['document_id'] === $document_id ) {
				return;
			}
		}

		$this->create(
			array(
				'chatbot_id'  => $chatbot_id,
				'document_id' => $document_id,
				'ordre'       => count( $rows ),
			)
		);
	}
}
