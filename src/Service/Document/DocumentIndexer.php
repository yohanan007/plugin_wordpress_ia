<?php

namespace WPIA\Service\Document;

use InvalidArgumentException;
use RuntimeException;
use WPIA\Repository\DocumentRepository;
use WPIA\Repository\DocumentSectionRepository;
use WPIA\Service\Prompt\TokenEstimator;
use WPIA\Service\Securite\SecretCipher;

/**
 * Indexe un document (issue 006, lot A) : lit le fichier chiffré, le découpe en
 * sections, chiffre chaque section, calcule les empreintes de mots-clés, puis
 * remplace les sections en base et met à jour le document.
 *
 * C'est le seul endroit où le fichier est lu pour être découpé : la construction
 * du contexte (lot B) ne lit que `wpia_document_section`.
 *
 * Le statut passe à « ready » en cas de succès. En cas d'échec il passe à
 * « error », les anciennes sections sont retirées, et une RuntimeException est
 * levée. Son message ne contient jamais d'extrait du document.
 */
class DocumentIndexer {
	private DocumentStorage $storage;
	private DocumentRepository $documents;
	private DocumentSectionRepository $sections;
	private SecretCipher $cipher;
	private KeywordNormalizer $keywords;
	private MarkdownSectionizer $sectionizer;

	public function __construct(
		DocumentStorage $storage,
		DocumentRepository $documents,
		DocumentSectionRepository $sections,
		SecretCipher $cipher,
		KeywordNormalizer $keywords,
		?MarkdownSectionizer $sectionizer = null
	) {
		$this->storage     = $storage;
		$this->documents   = $documents;
		$this->sections    = $sections;
		$this->cipher      = $cipher;
		$this->keywords    = $keywords;
		$this->sectionizer = $sectionizer ?? new MarkdownSectionizer();
	}

	public static function create( \wpdb $wpdb ): self {
		$cipher = new SecretCipher();

		return new self(
			new DocumentStorage( $cipher ),
			new DocumentRepository( $wpdb ),
			new DocumentSectionRepository( $wpdb ),
			$cipher,
			new KeywordNormalizer( $cipher->derive( KeywordNormalizer::KEY_CONTEXT ) )
		);
	}

	/**
	 * @param int $max_section_tokens Plafond d'une section (réglage context_section_max_tokens).
	 * @return array{sections:int,tokens:int}
	 * @throws RuntimeException Si le document est introuvable ou l'indexation échoue.
	 */
	public function indexer( int $document_id, int $max_section_tokens = 800 ): array {
		$row = $this->documents->getById( $document_id );
		if ( ! is_array( $row ) ) {
			throw new RuntimeException( 'The document to index does not exist.' );
		}

		try {
			$contenu  = $this->storage->read( (string) $row['fichier'] );
			$decoupes = $this->sectionizer->decouper( $contenu, $max_section_tokens );
			if ( empty( $decoupes ) ) {
				throw new RuntimeException( 'The document contains no text to index.' );
			}

			$a_stocker = array();
			foreach ( $decoupes as $section ) {
				$a_stocker[] = array(
					'titre'     => $section['titre'],
					'contenu'   => $this->cipher->encrypt( $section['contenu'] ),
					'tokens'    => $section['tokens'],
					'mots_cles' => $this->keywords->serialiser( $section['titre'] . "\n" . $section['contenu'] ),
				);
			}

			$this->sections->replaceForDocument( $document_id, $a_stocker );
			$this->documents->update(
				$document_id,
				array(
					'hash'              => hash( 'sha256', $contenu ),
					'taille_tokens'     => TokenEstimator::estimer( $contenu ),
					'taille_octets'     => strlen( $contenu ),
					'statut_indexation' => 'ready',
				)
			);
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			$this->marquerEnErreur( $document_id );
			throw new RuntimeException( 'Document indexing failed: ' . $exception->getMessage(), 0, $exception );
		}

		return array(
			'sections' => count( $a_stocker ),
			'tokens'   => array_sum( array_column( $a_stocker, 'tokens' ) ),
		);
	}

	/**
	 * Meilleur effort : une erreur ici ne doit pas masquer l'erreur d'origine.
	 */
	private function marquerEnErreur( int $document_id ): void {
		try {
			$this->sections->deleteByDocument( $document_id );
			$this->documents->update( $document_id, array( 'statut_indexation' => 'error' ) );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			error_log( 'WPIA: unable to flag a document as failed.' );
		}
	}
}
