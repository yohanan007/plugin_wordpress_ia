<?php

namespace WPIA\Service\Fournisseur;

class AnthropicProvider extends AbstractProvider {
	/**
	 * @param array<string, mixed> $parameters
	 * @return array|\WP_Error
	 */
	public function generate( string $base_url, string $api_key, array $parameters ): array|\WP_Error {
		$payload = array(
			'model'      => $parameters['model'],
			'max_tokens' => $parameters['max_tokens'],
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $parameters['prompt'],
				),
			),
			'temperature' => $parameters['temperature'],
		);
		if ( '' !== $parameters['system_prompt'] ) {
			$payload['system'] = $parameters['system_prompt'];
		}

		return $this->post(
			untrailingslashit( $base_url ) . '/messages',
			array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			$payload,
			$parameters['timeout']
		);
	}
}
