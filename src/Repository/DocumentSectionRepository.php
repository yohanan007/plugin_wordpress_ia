<?php

namespace WPIA\Repository;

use InvalidArgumentException;
use RuntimeException;

/**
 * Sections d'un document Markdown (issue 006). `contenu` est chiffré ; `mots_cles`
 * ne contient que des empreintes HMAC.
 */
class DocumentSectionRepository extends AbstractRepository {
	protected string $table_suffix = 'document_section';

	protected array $columns = array(
		'document_id' => '%d',
		'ordre'       => '%d',
		'titre'       => '%s',
		'contenu'     => '%s',
		'tokens'      => '%d',
		'mots_cles'   => '%s',
	);

	/**
	 * Remplace toutes les sections d'un document, dans une seule transaction.
	 * Ne pas l'appeler à l'intérieur d'une transaction déjà ouverte : MySQL
	 * validerait celle-ci implicitement.
	 *
	 * @param array<int, array{titre:string,contenu:string,tokens:int,mots_cles:string}> $sections
	 */
	public function replaceForDocument( int $document_id, array $sections ): void {
		if ( $document_id < 1 ) {
			throw new InvalidArgumentException( 'The document identifier must be positive.' );
		}

		if ( false === $this->wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( 'Unable to start the section transaction.' );
		}

		try {
			$this->deleteByDocument( $document_id );
			foreach ( array_values( $sections ) as $ordre => $section ) {
				$this->create(
					array(
						'document_id' => $document_id,
						'ordre'       => $ordre + 1,
						'titre'       => substr( $section['titre'], 0, 191 ),
						'contenu'     => $section['contenu'],
						'tokens'      => $section['tokens'],
						'mots_cles'   => $section['mots_cles'],
					)
				);
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Unable to commit the sections.' );
			}
		} catch ( \Throwable $exception ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $exception instanceof RuntimeException || $exception instanceof InvalidArgumentException
				? $exception
				: new RuntimeException( 'Unable to store the document sections.', 0, $exception );
		}
	}

	public function deleteByDocument( int $document_id ): void {
		if ( $document_id < 1 ) {
			throw new InvalidArgumentException( 'The document identifier must be positive.' );
		}

		$result = $this->wpdb->delete(
			$this->wpdb->prefix . 'wpia_document_section',
			array( 'document_id' => $document_id ),
			array( '%d' )
		);
		if ( false === $result ) {
			throw new RuntimeException( 'Unable to delete the document sections: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Nombre de sections par document.
	 *
	 * @param array<int, int> $document_ids
	 * @return array<int, int> document_id => nombre de sections
	 */
	public function countByDocuments( array $document_ids ): array {
		$document_ids = array_values( array_filter( array_map( 'absint', $document_ids ) ) );
		if ( empty( $document_ids ) ) {
			return array();
		}

		$sql  = 'SELECT document_id, COUNT(*) AS total FROM ' . $this->wpdb->prefix . 'wpia_document_section'
			. ' WHERE document_id IN (' . implode( ',', array_fill( 0, count( $document_ids ), '%d' ) ) . ')'
			. ' GROUP BY document_id';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$document_ids ), \ARRAY_A );
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to count the document sections: ' . $this->wpdb->last_error );
		}

		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (int) $row['document_id'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
