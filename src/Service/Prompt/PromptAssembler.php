<?php

namespace WPIA\Service\Prompt;

use WPIA\Admin\AdminSettings;
use WPIA\Repository\ChatbotDocumentRepository;
use WPIA\Service\Document\ContextBuilder;

/**
 * Assemble le prompt système en quatre blocs, dans un ordre fixe (issue 006) :
 * [1] prompt de base, [2] consignes de l'admin (PromptBuilder, issue 009),
 * [3] <contexte>, [4] <documents> (ContextBuilder).
 *
 * L'ordre suit l'autorité décroissante (le plugin, puis l'admin, puis des données)
 * et la stabilité décroissante : tout ce qui est stable vient avant le point de
 * cache. Mode complet : `stable` = [1] à [4], `variable` vide. Mode sélection :
 * `stable` = [1] à [3], `variable` = [4], qui change à chaque question.
 */
class PromptAssembler {
	private PromptBuilder $builder;
	private ContextBuilder $context;
	private ChatbotDocumentRepository $associations;

	public function __construct( PromptBuilder $builder, ContextBuilder $context, ChatbotDocumentRepository $associations ) {
		$this->builder      = $builder;
		$this->context      = $context;
		$this->associations = $associations;
	}

	public static function create( \wpdb $wpdb ): self {
		return new self( new PromptBuilder(), ContextBuilder::create( $wpdb ), new ChatbotDocumentRepository( $wpdb ) );
	}

	/**
	 * @param array<string, mixed> $chatbot  Ligne wpia_chatbot (id, cadrage, system_prompt, marqueur).
	 * @param array<string, mixed>|null $reglages AdminSettings::get() par défaut.
	 * @return array{systeme:array{stable:string,variable:string},releve:array{mode:string,version:string,tokens:array<string,int>,sections:int[]}}
	 * @throws \RuntimeException Si la lecture en base échoue.
	 */
	public function assembler( array $chatbot, string $nom_site, string $question, ?array $reglages = null ): array {
		$reglages = $reglages ?? AdminSettings::get();
		$id       = (int) ( $chatbot['id'] ?? 0 );

		$contexte = $this->context->construire( $id, $question, (int) $reglages['context_token_budget'] );
		$blocs    = $this->builder->construire( $chatbot, $nom_site, $contexte['avec_documents'] );

		$stable   = array( $blocs['base'] );
		$variable = array();
		if ( '' !== $blocs['admin'] ) {
			$stable[] = $blocs['admin'];
		}
		if ( '' !== $contexte['contexte'] ) {
			$stable[] = $contexte['contexte'];
		}
		if ( '' !== $contexte['complet'] ) {
			if ( ContextBuilder::MODE_SELECTION === $contexte['mode'] ) {
				$variable[] = $contexte['complet'];
			} else {
				$stable[] = $contexte['complet'];
			}
		}

		$version = ContextVersion::calculer( $chatbot, $this->associations->getReadyDocuments( $id ), $reglages, $nom_site );

		return array(
			'systeme' => array(
				'stable'   => implode( "\n\n", $stable ),
				'variable' => implode( "\n\n", $variable ),
			),
			'releve'  => array(
				'mode'     => $contexte['mode'],
				'version'  => $version,
				'tokens'   => array(
					'base'     => TokenEstimator::estimer( $blocs['base'] ),
					'admin'    => TokenEstimator::estimer( $blocs['admin'] ),
					'contexte' => $contexte['tokens']['contexte'],
					'complet'  => $contexte['tokens']['complet'],
				),
				'sections' => $contexte['sections'],
			),
		);
	}
}
