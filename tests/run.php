<?php

declare(strict_types=1);

define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private string $code;
	private string $message;
	private $data;

	public function __construct( string $code, string $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}
}

class wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 1;
	public array $rows = array();
	public array $logs = array();

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[dsf]/', (string) (int) $arg, $query, 1 );
		}

		return $query;
	}

	public function get_row( string $query, string $output ): ?array {
		$suffix = '';
		foreach ( array( 'fournisseur_ia', 'compte_api', 'chatbot' ) as $candidate ) {
			if ( str_contains( $query, 'wpia_' . $candidate ) ) {
				$suffix = $candidate;
				break;
			}
		}
		preg_match( '/`id` = (\d+)/', $query, $matches );

		return $this->rows[ $suffix ][ (int) ( $matches[1] ?? 0 ) ] ?? null;
	}

	public function insert( string $table, array $data, array $formats ): int {
		$this->logs[] = $data;
		return 1;
	}
}

function __( string $message, string $domain = '' ): string {
	return $message;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function sanitize_textarea_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
}

function absint( $value ): int {
	return abs( (int) $value );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/' );
}

function wp_parse_url( string $url ): array|false {
	return parse_url( $url );
}

function wp_json_encode( $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_post( string $url, array $args ): array|WP_Error {
	return ( $GLOBALS['wpia_http_callback'] )( $url, $args );
}

function wp_safe_remote_post( string $url, array $args ): array|WP_Error {
	return wp_remote_post( $url, $args );
}

function get_option( string $name, $default = false ) {
	return $GLOBALS['wpia_options'][ $name ] ?? $default;
}

function current_time( string $type ): string {
	return '2026-01-01 00:00:00';
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WPIA\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);

class TestCipher extends WPIA\Service\SecretCipher {
	public int $decryptCalls = 0;

	public function decrypt( string $value ): string {
		++$this->decryptCalls;
		if ( 'wpia:v1:encrypted-test-key' !== $value ) {
			throw new RuntimeException( 'Unexpected test secret.' );
		}

		return 'test-secret-never-log';
	}
}

function assertTrue( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function assertSameValue( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

function serviceRows( string $provider, string $api_key = 'wpia:v1:encrypted-test-key' ): array {
	$urls = array(
		'openai'    => 'https://api.openai.com/v1',
		'mistral'   => 'https://api.mistral.ai/v1',
		'anthropic' => 'https://api.anthropic.com/v1',
		'ollama'    => 'http://localhost:11434',
		'custom'    => 'https://custom.example/v1',
	);

	return array(
		'fournisseur_ia' => array(
			1 => array( 'id' => 1, 'designation' => $provider, 'url_api' => $urls[ $provider ], 'actif' => 1 ),
		),
		'compte_api' => array(
			1 => array( 'id' => 1, 'fournisseur_id' => 1, 'api_key' => $api_key, 'actif' => 1 ),
		),
		'chatbot' => array(
			1 => array(
				'id'            => 1,
				'compte_api_id' => 1,
				'modele'        => 'test-model',
				'system_prompt' => 'Réponds brièvement.',
				'max_tokens'    => 80,
				'temperature'   => '0.4',
				'actif'         => 1,
			),
		),
	);
}

function response( int $status, string $body ): array {
	return array(
		'response' => array( 'code' => $status ),
		'body'     => $body,
	);
}

$tests = 0;
$run   = static function ( string $name, callable $test ) use ( &$tests ): void {
	$test();
	++$tests;
	echo "PASS {$name}\n";
};

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpia_options'] = array(
	'wpia_admin_settings' => array(
		'max_tokens_request' => 64,
		'api_timeout'        => 17,
	),
);
$cipher = new TestCipher();

foreach ( array( 'openai', 'mistral', 'anthropic', 'ollama', 'custom' ) as $type ) {
	$run(
		$type . ' request and normalized response',
		static function () use ( $type, $cipher ): void {
			$GLOBALS['wpdb']->rows = serviceRows( $type );
			$GLOBALS['wpia_options']['wpia_active_configuration'] = array(
				'provider_type' => $type,
				'provider_id'   => 1,
				'account_id'    => 1,
				'chatbot_id'    => 1,
			);
			$capture = array();
			$GLOBALS['wpia_http_callback'] = static function ( string $url, array $args ) use ( &$capture, $type ): array {
				$capture = array( 'url' => $url, 'args' => $args );
				$body = 'anthropic' === $type
					? array( 'content' => array( array( 'type' => 'text', 'text' => 'Réponse' ) ), 'usage' => array( 'input_tokens' => 5, 'output_tokens' => 3 ) )
					: ( 'ollama' === $type
						? array( 'message' => array( 'content' => 'Réponse' ), 'prompt_eval_count' => 5, 'eval_count' => 3 )
						: array( 'choices' => array( array( 'message' => array( 'content' => 'Réponse' ) ) ), 'usage' => array( 'prompt_tokens' => 5, 'completion_tokens' => 3 ) ) );

				return response( 200, json_encode( $body ) );
			};

			$result = ( new WPIA\Service\AiGenerationService( $GLOBALS['wpdb'], $cipher ) )->generate( 'Prompt de test' );
			assertTrue( ! is_wp_error( $result ), $type . ' should produce a normalized response' );
			assertSameValue( 'Réponse', $result['content'], $type . ' content' );
			assertSameValue( 'test-model', $result['model'], $type . ' model fallback' );
			assertSameValue( 3, $result['usage']['output_tokens'], $type . ' output token count' );
			assertSameValue( 17, $capture['args']['timeout'], $type . ' timeout' );

			$paths = array(
				'openai'    => '/chat/completions',
				'mistral'   => '/chat/completions',
				'anthropic' => '/messages',
				'ollama'    => '/api/chat',
				'custom'    => '/chat/completions',
			);
			assertTrue( str_ends_with( $capture['url'], $paths[ $type ] ), $type . ' endpoint' );
			$payload = json_decode( $capture['args']['body'], true );
			assertSameValue( 'test-model', $payload['model'], $type . ' configured model' );
			assertSameValue( 0.4, 'ollama' === $type ? $payload['options']['temperature'] : $payload['temperature'], $type . ' temperature' );
			assertSameValue( 64, 'ollama' === $type ? $payload['options']['num_predict'] : ( 'anthropic' === $type ? $payload['max_tokens'] : $payload['max_tokens'] ), $type . ' token cap' );
			assertTrue( ! str_contains( $capture['args']['body'], 'test-secret-never-log' ), $type . ' must not include the secret in the body' );

			if ( 'anthropic' === $type ) {
				assertSameValue( 'test-secret-never-log', $capture['args']['headers']['x-api-key'], 'Anthropic API key header' );
			} elseif ( 'ollama' !== $type ) {
				assertSameValue( 'Bearer test-secret-never-log', $capture['args']['headers']['Authorization'], $type . ' authorization header' );
			}
		}
	);
}

$failureCases = array(
	'timeout' => array( new WP_Error( 'http_request_failed', 'cURL error: operation timed out' ), 'wpia_timeout' ),
	'network error' => array( new WP_Error( 'http_request_failed', 'private endpoint details' ), 'wpia_network_error' ),
	'authentication' => array( response( 401, '{"error":"sensitive vendor response"}' ), 'wpia_authentication_error' ),
	'invalid JSON' => array( response( 200, '{invalid' ), 'wpia_invalid_response' ),
	'invalid structure' => array( response( 200, '{"message":"missing choices"}' ), 'wpia_invalid_response' ),
	'empty response' => array( response( 200, '{"choices":[{"message":{"content":""}}]}' ), 'wpia_empty_response' ),
	'output limit' => array( response( 200, '{"choices":[{"message":{"content":"text"}}],"usage":{"completion_tokens":65}}' ), 'wpia_output_limit' ),
);
foreach ( $failureCases as $name => $case ) {
	$run(
		$name . ' is normalized without leaking provider details',
		static function () use ( $case, $cipher ): void {
			$GLOBALS['wpdb']->rows = serviceRows( 'openai' );
			$GLOBALS['wpia_options']['wpia_active_configuration'] = array(
				'provider_type' => 'openai',
				'provider_id'   => 1,
				'account_id'    => 1,
				'chatbot_id'    => 1,
			);
			$GLOBALS['wpia_http_callback'] = static function ( string $url, array $args ) use ( $case ): array|WP_Error {
				return $case[0];
			};
			$result = ( new WPIA\Service\AiGenerationService( $GLOBALS['wpdb'], $cipher ) )->generate( 'private prompt' );
			assertTrue( is_wp_error( $result ), 'failure should return WP_Error' );
			assertSameValue( $case[1], $result->get_error_code(), 'normalized error code' );
			assertTrue( ! str_contains( $result->get_error_message(), 'private endpoint' ), 'error must not reveal endpoint' );
			assertTrue( ! str_contains( $result->get_error_message(), 'sensitive vendor response' ), 'error must not reveal provider body' );
			$log = end( $GLOBALS['wpdb']->logs );
			assertTrue( ! str_contains( $log['message'], 'private prompt' ), 'logs must not contain prompts' );
			assertTrue( ! str_contains( $log['message'], 'test-secret-never-log' ), 'logs must not contain keys' );
		}
	);
}

$run(
	'missing key fails before HTTP call',
	static function () use ( $cipher ): void {
		$GLOBALS['wpdb']->rows = serviceRows( 'openai', '' );
		$GLOBALS['wpia_options']['wpia_active_configuration'] = array(
			'provider_type' => 'openai',
			'provider_id'   => 1,
			'account_id'    => 1,
			'chatbot_id'    => 1,
		);
		$called = false;
		$decrypt_calls = $cipher->decryptCalls;
		$GLOBALS['wpia_http_callback'] = static function ( string $url, array $args ) use ( &$called ): array {
			$called = true;
			return response( 200, '{}' );
		};
		$result = ( new WPIA\Service\AiGenerationService( $GLOBALS['wpdb'], $cipher ) )->generate( 'Prompt' );
		assertTrue( is_wp_error( $result ), 'missing key should fail' );
		assertSameValue( 'wpia_missing_api_key', $result->get_error_code(), 'missing-key error code' );
		assertTrue( ! $called, 'missing key must not make an HTTP request' );
		assertSameValue( $decrypt_calls, $cipher->decryptCalls, 'missing key must not trigger decryption' );
	}
);

$run(
	'plaintext legacy key is rejected without decrypting or sending it',
	static function () use ( $cipher ): void {
		$GLOBALS['wpdb']->rows = serviceRows( 'openai', 'legacy-plaintext-secret' );
		$GLOBALS['wpia_options']['wpia_active_configuration'] = array(
			'provider_type' => 'openai',
			'provider_id'   => 1,
			'account_id'    => 1,
			'chatbot_id'    => 1,
		);
		$called = false;
		$decrypt_calls = $cipher->decryptCalls;
		$GLOBALS['wpia_http_callback'] = static function ( string $url, array $args ) use ( &$called ): array {
			$called = true;
			return response( 200, '{}' );
		};
		$result = ( new WPIA\Service\AiGenerationService( $GLOBALS['wpdb'], $cipher ) )->generate( 'Prompt' );
		assertTrue( is_wp_error( $result ), 'plaintext secret should fail' );
		assertSameValue( 'wpia_secret_error', $result->get_error_code(), 'plaintext-key error code' );
		assertTrue( ! $called, 'plaintext key must not be sent' );
		assertSameValue( $decrypt_calls, $cipher->decryptCalls, 'plaintext key must not be decrypted' );
	}
);

assertSameValue( 14, $tests, 'total passing test count' );
echo "All {$tests} tests passed.\n";
