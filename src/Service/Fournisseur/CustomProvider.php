<?php

namespace WPIA\Service\Fournisseur;

class CustomProvider extends AbstractProvider {
	/**
	 * @param array<string, mixed> $parameters
	 * @return array|\WP_Error
	 */
	public function generate( string $base_url, string $api_key, array $parameters ): array|\WP_Error {
		$messages = array();
		if ( '' !== $parameters['system_prompt'] ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $parameters['system_prompt'],
			);
		}
		$messages[] = array(
			'role'    => 'user',
			'content' => $parameters['prompt'],
		);

		return $this->post(
			untrailingslashit( $base_url ) . '/chat/completions',
			array( 'Authorization' => 'Bearer ' . $api_key ),
			array(
				'model'       => $parameters['model'],
				'messages'    => $messages,
				'temperature' => $parameters['temperature'],
				'max_tokens'  => $parameters['max_tokens'],
			),
			$parameters['timeout']
		);
	}
}
