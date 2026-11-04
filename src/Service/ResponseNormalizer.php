<?php

namespace WPIA\Service;

use JsonException;

class ResponseNormalizer {
	private const MAX_RESPONSE_SIZE = 1048576;

	/**
	 * @param array|\WP_Error $response
	 * @return array{content:string,model:string,usage:array{input_tokens:?int,output_tokens:?int,total_tokens:?int}}|\WP_Error
	 */
	public function normalize( string $provider, $response, string $configured_model, int $max_tokens ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			$error = strtolower( $response->get_error_code() . ' ' . $response->get_error_message() );
			if ( str_contains( $error, 'timeout' ) || str_contains( $error, 'timed out' ) ) {
				return $this->error( 'wpia_timeout', __( 'Le délai de réponse du fournisseur est dépassé.', 'plugin-wordpress-ia' ) );
			}

			return $this->error( 'wpia_network_error', __( 'Le fournisseur est momentanément inaccessible.', 'plugin-wordpress-ia' ) );
		}

		if ( ! is_array( $response ) ) {
			return $this->error( 'wpia_invalid_response', __( 'Le fournisseur a retourné une réponse invalide.', 'plugin-wordpress-ia' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			if ( 401 === $status || 403 === $status ) {
				return $this->error( 'wpia_authentication_error', __( 'L’authentification auprès du fournisseur a échoué.', 'plugin-wordpress-ia' ), $status );
			}

			return $this->error( 'wpia_provider_error', __( 'Le fournisseur n’a pas pu traiter la demande.', 'plugin-wordpress-ia' ), $status );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === trim( $body ) || strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
			return $this->error( 'wpia_invalid_response', __( 'Le fournisseur a retourné une réponse invalide.', 'plugin-wordpress-ia' ), $status );
		}

		try {
			$data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			return $this->error( 'wpia_invalid_response', __( 'Le fournisseur a retourné une réponse invalide.', 'plugin-wordpress-ia' ), $status );
		}

		if ( ! is_array( $data ) ) {
			return $this->error( 'wpia_invalid_response', __( 'Le fournisseur a retourné une réponse invalide.', 'plugin-wordpress-ia' ), $status );
		}

		if ( ! $this->hasContentField( $provider, $data ) ) {
			return $this->error( 'wpia_invalid_response', __( 'Le fournisseur a retourné une réponse invalide.', 'plugin-wordpress-ia' ), $status );
		}

		$content = $this->extractContent( $provider, $data );
		if ( null === $content ) {
			return $this->error( 'wpia_invalid_response', __( 'Le fournisseur a retourné une réponse invalide.', 'plugin-wordpress-ia' ), $status );
		}
		if ( '' === trim( $content ) ) {
			return $this->error( 'wpia_empty_response', __( 'Le fournisseur n’a retourné aucun texte.', 'plugin-wordpress-ia' ), $status );
		}

		$usage = $this->extractUsage( $provider, $data );
		if ( null !== $usage['output_tokens'] && $usage['output_tokens'] > $max_tokens ) {
			return $this->error( 'wpia_output_limit', __( 'La réponse dépasse la limite de tokens configurée.', 'plugin-wordpress-ia' ), $status );
		}

		$model = isset( $data['model'] ) && is_string( $data['model'] ) && '' !== $data['model']
			? sanitize_text_field( $data['model'] )
			: $configured_model;

		return array(
			'content' => $content,
			'model'   => $model,
			'usage'   => $usage,
		);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function hasContentField( string $provider, array $data ): bool {
		if ( 'anthropic' === $provider ) {
			return isset( $data['content'] ) && is_array( $data['content'] );
		}

		if ( 'ollama' === $provider ) {
			return isset( $data['message'] ) && is_array( $data['message'] ) && array_key_exists( 'content', $data['message'] );
		}

		return isset( $data['choices'][0]['message'] )
			&& is_array( $data['choices'][0]['message'] )
			&& array_key_exists( 'content', $data['choices'][0]['message'] );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function extractContent( string $provider, array $data ): ?string {
		if ( 'anthropic' === $provider ) {
			if ( ! isset( $data['content'] ) || ! is_array( $data['content'] ) ) {
				return null;
			}

			$parts = array();
			foreach ( $data['content'] as $block ) {
				if ( ! is_array( $block ) || ! isset( $block['type'], $block['text'] ) || 'text' !== $block['type'] || ! is_string( $block['text'] ) ) {
					continue;
				}
				$parts[] = $block['text'];
			}

			return empty( $parts ) ? null : implode( '', $parts );
		}

		if ( 'ollama' === $provider ) {
			return isset( $data['message']['content'] ) && is_string( $data['message']['content'] )
				? $data['message']['content']
				: null;
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return null;
		}

		$content = $data['choices'][0]['message']['content'];
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return null;
		}

		$parts = array();
		foreach ( $content as $part ) {
			if ( ! is_array( $part ) || ! isset( $part['text'] ) || ! is_string( $part['text'] ) ) {
				return null;
			}
			$parts[] = $part['text'];
		}

		return empty( $parts ) ? null : implode( '', $parts );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{input_tokens:?int,output_tokens:?int,total_tokens:?int}
	 */
	private function extractUsage( string $provider, array $data ): array {
		if ( 'ollama' === $provider ) {
			$input  = $data['prompt_eval_count'] ?? null;
			$output = $data['eval_count'] ?? null;
		} else {
			$usage  = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
			$input  = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
			$output = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null;
		}

		$input  = is_numeric( $input ) && (int) $input >= 0 ? (int) $input : null;
		$output = is_numeric( $output ) && (int) $output >= 0 ? (int) $output : null;

		return array(
			'input_tokens'  => $input,
			'output_tokens' => $output,
			'total_tokens'  => null !== $input && null !== $output ? $input + $output : null,
		);
	}

	private function error( string $code, string $message, int $status = 0 ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			$status > 0 ? array( 'status' => $status ) : null
		);
	}
}
