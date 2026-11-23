<?php

namespace WPIA\Repository;

use InvalidArgumentException;
use RuntimeException;
use WPIA\Domain\ChatbotDocument;

class ChatbotDocumentRepository extends AbstractRepository {
	protected string $table_suffix = 'chatbot_document';

	protected array $columns = array(
		'chatbot_id'  => '%d',
		'document_id' => '%d',
		'ordre'       => '%d',
		'role'        => '%s',
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
			'SELECT document_id, ordre, role FROM ' . $table . ' WHERE chatbot_id = %d ORDER BY ordre ASC, document_id ASC',
			$chatbot_id
		);
		$rows  = $this->wpdb->get_results( $sql, \ARRAY_A );

		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to retrieve the chatbot document order: ' . $this->wpdb->last_error );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Documents `ready` et actifs d'un chatbot, dans l'ordre : empreinte et rôle.
	 * Sert à calculer la version du contexte (issue 006).
	 *
	 * @return array<int, array{hash:string,role:string,designation:string}>
	 */
	public function getReadyDocuments( int $chatbot_id ): array {
		if ( $chatbot_id < 1 ) {
			throw new InvalidArgumentException( 'The chatbot identifier must be positive.' );
		}

		$prefix = $this->wpdb->prefix;
		$sql    = $this->wpdb->prepare(
			'SELECT d.hash, d.designation, cd.role FROM ' . $prefix . 'wpia_chatbot_document cd'
			. ' INNER JOIN ' . $prefix . 'wpia_document d ON d.id = cd.document_id'
			. ' WHERE cd.chatbot_id = %d AND d.actif = 1 AND d.statut_indexation = \'ready\''
			. ' ORDER BY cd.ordre ASC, d.id ASC',
			$chatbot_id
		);
		$rows   = $this->wpdb->get_results( $sql, \ARRAY_A );
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to read the chatbot documents: ' . $this->wpdb->last_error );
		}

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'hash'        => (string) $row['hash'],
				'role'        => (string) $row['role'],
				'designation' => (string) $row['designation'],
			);
		}

		return $result;
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

		// Le rôle est porté par l'association : il doit survivre à la réécriture de l'ordre.
		$roles = array();
		foreach ( $this->getOrderedDocuments( $chatbot_id ) as $row ) {
			$roles[ (int) $row['document_id'] ] = $row['role'] ?? ChatbotDocument::ROLE_COMPLET;
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
						'role'        => ChatbotDocument::isValidRole( $roles[ $document_id ] ?? null ) ? $roles[ $document_id ] : ChatbotDocument::ROLE_COMPLET,
					),
					array( '%d', '%d', '%d', '%s' )
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

	public function append( int $chatbot_id, int $document_id, string $role = ChatbotDocument::ROLE_COMPLET ): void {
		if ( $chatbot_id < 1 || $document_id < 1 ) {
			throw new InvalidArgumentException( 'Chatbot and document identifiers must be positive.' );
		}
		if ( ! ChatbotDocument::isValidRole( $role ) ) {
			throw new InvalidArgumentException( 'The document role is invalid.' );
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
				'role'        => $role,
			)
		);
	}

	public function hasDocument( int $chatbot_id, int $document_id ): bool {
		foreach ( $this->getOrderedDocuments( $chatbot_id ) as $row ) {
			if ( (int) $row['document_id'] === $document_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Change le rôle d'une association existante.
	 */
	public function setRole( int $chatbot_id, int $document_id, string $role ): void {
		if ( $chatbot_id < 1 || $document_id < 1 ) {
			throw new InvalidArgumentException( 'Chatbot and document identifiers must be positive.' );
		}
		if ( ! ChatbotDocument::isValidRole( $role ) ) {
			throw new InvalidArgumentException( 'The document role is invalid.' );
		}

		$result = $this->wpdb->update(
			$this->wpdb->prefix . 'wpia_chatbot_document',
			array( 'role' => $role ),
			array(
				'chatbot_id'  => $chatbot_id,
				'document_id' => $document_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
		if ( false === $result ) {
			throw new RuntimeException( 'Unable to change the document role: ' . $this->wpdb->last_error );
		}
	}
}
