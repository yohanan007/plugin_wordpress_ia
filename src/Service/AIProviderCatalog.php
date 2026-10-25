<?php

namespace WPIA\Service;

class AIProviderCatalog {
	/**
	 * @return array<string, array<string, string>>
	 */
	public static function all(): array {
		return array(
			'openai' => array(
				'label' => __( 'OpenAI', 'plugin-wordpress-ia' ),
				'url'   => 'https://api.openai.com/v1',
				'model' => 'gpt-4o-mini',
			),
			'mistral' => array(
				'label' => __( 'Mistral', 'plugin-wordpress-ia' ),
				'url'   => 'https://api.mistral.ai/v1',
				'model' => 'mistral-small-latest',
			),
			'anthropic' => array(
				'label' => __( 'Anthropic', 'plugin-wordpress-ia' ),
				'url'   => 'https://api.anthropic.com/v1',
				'model' => 'claude-3-5-haiku-latest',
			),
			'ollama' => array(
				'label' => __( 'Ollama (local)', 'plugin-wordpress-ia' ),
				'url'   => 'http://localhost:11434',
				'model' => 'llama3.2',
			),
			'custom' => array(
				'label' => __( 'Personnalisé', 'plugin-wordpress-ia' ),
				'url'   => '',
				'model' => '',
			),
		);
	}
}
