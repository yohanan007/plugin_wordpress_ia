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
						'titre'       => self::tronquer( $section['titre'], 191 ),
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

	/**
	 * Tronque en caractères UTF-8 (la colonne `varchar(191)` compte des caractères),
	 * sans couper un caractère en deux ni dépendre de mbstring.
	 */
	private static function tronquer( string $texte, int $max ): string {
		return 1 === preg_match( '/^.{0,' . $max . '}/us', $texte, $m ) ? $m[0] : substr( $texte, 0, $max );
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
	 * Toutes les sections envoyables à l'IA pour un chatbot, en une seule requête :
	 * documents associés, actifs et `ready`, dans l'ordre des associations puis des
	 * sections. `contenu` reste chiffré. Aucun accès disque.
	 *
	 * @return array<int, array{id:int,document_id:int,designation:string,role:string,titre:string,contenu:string,tokens:int,mots_cles:string}>
	 */
	public function getForChatbot( int $chatbot_id ): array {
		if ( $chatbot_id < 1 ) {
			throw new InvalidArgumentException( 'The chatbot identifier must be positive.' );
		}

		$prefix = $this->wpdb->prefix;
		$sql    = $this->wpdb->prepare(
			'SELECT s.id, s.document_id, d.designation, cd.role, s.titre, s.contenu, s.tokens, s.mots_cles'
			. ' FROM ' . $prefix . 'wpia_chatbot_document cd'
			. ' INNER JOIN ' . $prefix . 'wpia_document d ON d.id = cd.document_id'
			. ' INNER JOIN ' . $prefix . 'wpia_document_section s ON s.document_id = d.id'
			. ' WHERE cd.chatbot_id = %d AND d.actif = 1 AND d.statut_indexation = \'ready\''
			. ' ORDER BY cd.ordre ASC, d.id ASC, s.ordre ASC',
			$chatbot_id
		);
		$rows   = $this->wpdb->get_results( $sql, \ARRAY_A );
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to read the chatbot sections: ' . $this->wpdb->last_error );
		}

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[] = array(
				'id'          => (int) $row['id'],
				'document_id' => (int) $row['document_id'],
				'designation' => (string) $row['designation'],
				'role'        => (string) $row['role'],
				'titre'       => (string) $row['titre'],
				'contenu'     => (string) $row['contenu'],
				'tokens'      => (int) $row['tokens'],
				'mots_cles'   => (string) $row['mots_cles'],
			);
		}

		return $result;
	}

	/**
	 * Tokens des sections, par document, pour les documents `ready` et actifs
	 * associés à un chatbot (jauge et contrôles de la page « Documents »).
	 *
	 * @return array<int, array{role:string,tokens:int}> document_id => rôle et tokens
	 */
	public function tokensByChatbot( int $chatbot_id ): array {
		if ( $chatbot_id < 1 ) {
			throw new InvalidArgumentException( 'The chatbot identifier must be positive.' );
		}

		$prefix = $this->wpdb->prefix;
		$sql    = $this->wpdb->prepare(
			'SELECT d.id AS document_id, cd.role, SUM(s.tokens) AS tokens FROM ' . $prefix . 'wpia_chatbot_document cd'
			. ' INNER JOIN ' . $prefix . 'wpia_document d ON d.id = cd.document_id'
			. ' INNER JOIN ' . $prefix . 'wpia_document_section s ON s.document_id = d.id'
			. ' WHERE cd.chatbot_id = %d AND d.actif = 1 AND d.statut_indexation = \'ready\''
			. ' GROUP BY d.id, cd.role',
			$chatbot_id
		);
		$rows   = $this->wpdb->get_results( $sql, \ARRAY_A );
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to total the chatbot section tokens: ' . $this->wpdb->last_error );
		}

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$result[ (int) $row['document_id'] ] = array(
				'role'   => (string) $row['role'],
				'tokens' => (int) $row['tokens'],
			);
		}

		return $result;
	}

	/**
	 * Tokens des sections d'un document (0 s'il n'est pas indexé).
	 */
	public function tokensForDocument( int $document_id ): int {
		if ( $document_id < 1 ) {
			throw new InvalidArgumentException( 'The document identifier must be positive.' );
		}

		$value = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT SUM(tokens) FROM ' . $this->wpdb->prefix . 'wpia_document_section WHERE document_id = %d', $document_id )
		);
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Unable to total the document section tokens: ' . $this->wpdb->last_error );
		}

		return (int) $value;
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
