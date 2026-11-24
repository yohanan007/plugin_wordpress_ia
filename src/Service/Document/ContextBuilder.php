<?php

namespace WPIA\Service\Document;

use RuntimeException;
use WPIA\Admin\AdminSettings;
use WPIA\Domain\ChatbotDocument;
use WPIA\Repository\DocumentSectionRepository;
use WPIA\Service\Securite\SecretCipher;

/**
 * Construit les blocs documentaires du prompt système (issue 006, lot B) :
 * [3] `<contexte>` et [4] `<documents>`.
 *
 * - Les documents au rôle « contexte » sont toujours envoyés en entier, jamais
 *   tronqués ni filtrés.
 * - Les documents au rôle « complet » partagent le budget restant : en entier s'ils
 *   y tiennent (mode « complet »), sinon par sections choisies selon la question
 *   (mode « selection »).
 *
 * Ne lit que `wpia_document_section` : aucun accès disque, aucun redécoupage.
 * Seules les sections réellement envoyées sont déchiffrées. Tout contenu est
 * échappé : un document ne peut pas fermer son bloc.
 */
class ContextBuilder {
	public const MODE_AUCUN     = 'aucun';
	public const MODE_COMPLET   = 'complet';
	public const MODE_SELECTION = 'selection';

	/** Poids d'un terme du titre par rapport à un terme du corps. */
	private const POIDS_TITRE = 2;

	private DocumentSectionRepository $sections;
	private SecretCipher $cipher;
	private KeywordNormalizer $keywords;

	public function __construct( DocumentSectionRepository $sections, SecretCipher $cipher, KeywordNormalizer $keywords ) {
		$this->sections = $sections;
		$this->cipher   = $cipher;
		$this->keywords = $keywords;
	}

	public static function create( \wpdb $wpdb ): self {
		$cipher = new SecretCipher();

		return new self(
			new DocumentSectionRepository( $wpdb ),
			$cipher,
			new KeywordNormalizer( $cipher->derive( KeywordNormalizer::KEY_CONTEXT ) )
		);
	}

	/**
	 * @param int|null $budget Budget total des documents (tokens) ; réglage `context_token_budget` par défaut.
	 * @return array{contexte:string,complet:string,mode:string,tokens:array{contexte:int,complet:int},sections:int[],avec_documents:bool}
	 * @throws RuntimeException Si la lecture en base échoue.
	 */
	public function construire( int $chatbot_id, string $question, ?int $budget = null ): array {
		if ( null === $budget ) {
			$budget = (int) AdminSettings::get()['context_token_budget'];
		}
		$budget = max( 0, $budget );

		$contexte = array();
		$complet  = array();
		foreach ( $this->sections->getForChatbot( $chatbot_id ) as $section ) {
			if ( ChatbotDocument::ROLE_CONTEXTE === $section['role'] ) {
				$contexte[] = $section;
			} else {
				$complet[] = $section;
			}
		}

		$resultat = array(
			'contexte'       => '',
			'complet'        => '',
			'mode'           => self::MODE_AUCUN,
			'tokens'         => array( 'contexte' => 0, 'complet' => 0 ),
			'sections'       => array(),
			'avec_documents' => array() !== $contexte || array() !== $complet,
		);

		$contexte = $this->lisibles( $contexte );
		$tokens_contexte = $this->total( $contexte );
		if ( array() !== $contexte ) {
			$resultat['contexte']           = self::bloc( 'contexte', $contexte );
			$resultat['tokens']['contexte'] = $tokens_contexte;
		}

		// Le contexte n'est jamais tronqué : il réduit seulement la place des documents complets.
		$restant = max( 0, $budget - $tokens_contexte );

		if ( array() !== $complet ) {
			if ( $this->total( $complet ) <= $restant ) {
				$resultat['mode'] = self::MODE_COMPLET;
				$choisies         = $complet;
			} else {
				$resultat['mode'] = self::MODE_SELECTION;
				$choisies         = $this->selectionner( $complet, $question, $restant );
			}
			$choisies = $this->lisibles( $choisies );
			if ( array() !== $choisies ) {
				$resultat['complet']           = self::bloc( 'documents', $choisies );
				$resultat['tokens']['complet'] = $this->total( $choisies );
			}
		} elseif ( array() !== $contexte ) {
			$resultat['mode'] = self::MODE_COMPLET;
		}

		$resultat['sections'] = array_merge(
			array_column( $contexte, 'id' ),
			array_column( $choisies ?? array(), 'id' )
		);

		return $resultat;
	}

	/**
	 * @param array<int, array<string, mixed>> $sections
	 */
	private function total( array $sections ): int {
		return (int) array_sum( array_column( $sections, 'tokens' ) );
	}

	/**
	 * Meilleures sections dans la limite du budget, remises dans l'ordre du document.
	 * Une section de score nul n'est jamais envoyée « au hasard ».
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return array<int, array<string, mixed>>
	 */
	private function selectionner( array $sections, string $question, int $budget ): array {
		$question_terms = array_flip( $this->keywords->empreintes( $question ) );
		if ( array() === $question_terms || $budget < 1 ) {
			return array();
		}

		$scores = array();
		foreach ( $sections as $index => $section ) {
			$corps = array_flip( array_filter( explode( ' ', $section['mots_cles'] ) ) );
			$score = count( array_intersect_key( $question_terms, $corps ) );
			$titre = array_flip( $this->keywords->empreintes( $section['titre'] ) );
			$score += self::POIDS_TITRE * count( array_intersect_key( $question_terms, $titre ) );
			if ( $score > 0 ) {
				$scores[ $index ] = $score;
			}
		}

		// Score décroissant ; à score égal, l'ordre du document décide (stable).
		uksort(
			$scores,
			static function ( int $a, int $b ) use ( $scores ): int {
				return $scores[ $b ] <=> $scores[ $a ] ?: $a <=> $b;
			}
		);

		$retenues = array();
		$utilise  = 0;
		foreach ( array_keys( $scores ) as $index ) {
			$tokens = (int) $sections[ $index ]['tokens'];
			if ( $utilise + $tokens <= $budget ) {
				$retenues[ $index ] = true;
				$utilise           += $tokens;
			}
		}
		ksort( $retenues );

		return array_values( array_intersect_key( $sections, $retenues ) );
	}

	/**
	 * Déchiffre les sections à envoyer. Une section illisible est ignorée (et
	 * journalisée sans contenu) : mieux vaut un contexte partiel qu'une réponse en erreur.
	 *
	 * @param array<int, array<string, mixed>> $sections
	 * @return array<int, array<string, mixed>>
	 */
	private function lisibles( array $sections ): array {
		$resultat = array();
		foreach ( $sections as $section ) {
			if ( isset( $section['texte'] ) ) {
				$resultat[] = $section;
				continue;
			}
			try {
				$section['texte'] = $this->cipher->decrypt( $section['contenu'] );
			} catch ( RuntimeException $exception ) {
				error_log( 'WPIA: unable to read the section ' . (int) $section['id'] . '.' );
				continue;
			}
			$resultat[] = $section;
		}

		return $resultat;
	}

	/**
	 * @param array<int, array<string, mixed>> $sections
	 */
	public static function bloc( string $balise, array $sections ): string {
		$lignes = array( '<' . $balise . '>' );
		foreach ( $sections as $section ) {
			$lignes[] = sprintf(
				'<section document="%s" titre="%s">',
				htmlspecialchars( $section['designation'], ENT_QUOTES | ENT_XML1, 'UTF-8' ),
				htmlspecialchars( $section['titre'], ENT_QUOTES | ENT_XML1, 'UTF-8' )
			);
			$lignes[] = MarkdownSectionizer::echapper( $section['texte'] );
			$lignes[] = '</section>';
		}
		$lignes[] = '</' . $balise . '>';

		return implode( "\n", $lignes );
	}
}
