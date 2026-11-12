<?php

declare(strict_types=1);

define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

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

function wp_date( string $format, ?int $timestamp = null ): string {
	return gmdate( $format, $timestamp ?? time() );
}

function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['wpia_options'][ $name ] = $value;
	return true;
}

function wp_mail( string $to, string $subject, string $message ): bool {
	$GLOBALS['wpia_mails'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message );
	return true;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}

function get_current_user_id(): int {
	return (int) ( $GLOBALS['wpia_user_id'] ?? 0 );
}

function apply_filters( string $hook, $value ) {
	return $value;
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

require __DIR__ . '/InMemoryUsageStore.php';

use WPIA\Service\Usage\UsageKey;
use WPIA\Service\Usage\UsageLimiter;
use WPIA\Service\Usage\VisitorIdentity;

/**
 * Faux wpdb qui enregistre le SQL préparé et renvoie des résultats scriptés,
 * pour vérifier les requêtes de LimiteUtilisationRepository.
 */
class RecordingWpdb extends wpdb {
	public array $queries = array();
	public array $results = array();

	public function prepare( string $query, ...$args ): string {
		$i = 0;
		return preg_replace_callback(
			'/%[dsf]/',
			static function ( array $m ) use ( &$i, $args ): string {
				$value = $args[ $i++ ];
				return '%s' === $m[0] ? "'" . addslashes( (string) $value ) . "'" : (string) (int) $value;
			},
			$query
		);
	}

	public function query( string $sql ): int|false {
		$this->queries[] = $sql;
		return array_shift( $this->results ) ?? 1;
	}
}

class TestClock {
	public int $now;

	public function __construct( string $date ) {
		$this->now = strtotime( $date . ' UTC' );
	}

	public function __invoke(): int {
		return $this->now;
	}
}

function limitSettings( array $overrides = array() ): void {
	$GLOBALS['wpia_options']['wpia_admin_settings'] = array_merge(
		array(
			'max_tokens_request'      => 100,
			'api_timeout'             => 17,
			'daily_token_limit'       => 1000,
			'max_messages_user'       => 3,
			'max_tokens_conversation' => 600,
			'token_alert_threshold'   => 80,
		),
		$overrides
	);
}

function activeOpenAi(): void {
	$GLOBALS['wpdb']->rows = serviceRows( 'openai' );
	$GLOBALS['wpdb']->rows['chatbot'][1]['max_tokens'] = 100;
	$GLOBALS['wpia_options']['wpia_active_configuration'] = array(
		'provider_type' => 'openai',
		'provider_id'   => 1,
		'account_id'    => 1,
		'chatbot_id'    => 1,
	);
}

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

function service( TestCipher $cipher, ?UsageLimiter $limiter = null ): WPIA\Service\AiGenerationService {
	return new WPIA\Service\AiGenerationService(
		$GLOBALS['wpdb'],
		$cipher,
		null,
		$limiter ?? new UsageLimiter( new InMemoryUsageStore(), new TestClock( '2026-01-01 12:00:00' ) )
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

			$result = service( $cipher )->generate( 'Prompt de test' );
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
			$result = service( $cipher )->generate( 'private prompt' );
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
		$result = service( $cipher )->generate( 'Prompt' );
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
		$result = service( $cipher )->generate( 'Prompt' );
		assertTrue( is_wp_error( $result ), 'plaintext secret should fail' );
		assertSameValue( 'wpia_secret_error', $result->get_error_code(), 'plaintext-key error code' );
		assertTrue( ! $called, 'plaintext key must not be sent' );
		assertSameValue( $decrypt_calls, $cipher->decryptCalls, 'plaintext key must not be decrypted' );
	}
);

// ---------------------------------------------------------------------------
// Limites de consommation (issue #11). Paramètres des critères d'acceptation :
// daily_token_limit = 1000, max_messages_user = 3, max_tokens_conversation = 600,
// max_tokens = 100.
// ---------------------------------------------------------------------------

$GLOBALS['wpia_options']['admin_email'] = 'admin@example.test';

/**
 * Prépare un service réel branché sur un store en mémoire et un fournisseur
 * simulé qui compte ses appels.
 *
 * @return array{0:WPIA\Service\AiGenerationService,1:InMemoryUsageStore,2:TestClock,3:object}
 */
function limitedService( TestCipher $cipher, array|WP_Error|null $response = null ): array {
	limitSettings();
	activeOpenAi();
	$store   = new InMemoryUsageStore();
	$clock   = new TestClock( '2026-01-01 12:00:00' );
	$counter = new class() {
		public int $calls = 0;
	};
	$response = $response ?? response( 200, '{"choices":[{"message":{"content":"ok"}}],"usage":{"prompt_tokens":5,"completion_tokens":3}}' );
	$GLOBALS['wpia_http_callback'] = static function ( string $url, array $args ) use ( $counter, $response ): array|WP_Error {
		++$counter->calls;
		return $response;
	};

	return array( service( $cipher, new UsageLimiter( $store, $clock ) ), $store, $clock, $counter );
}

$run(
	'4th message of the same visitor is refused (429, visiteur) without provider call',
	static function () use ( $cipher ): void {
		list( $service, , , $counter ) = limitedService( $cipher );
		for ( $i = 1; $i <= 3; ++$i ) {
			assertTrue( ! is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:7' ) ) ), "message {$i} should pass" );
		}
		$result = $service->generate( 'Bonjour', array( 'visiteur' => 'u:7' ) );
		assertTrue( is_wp_error( $result ), '4th message should be refused' );
		assertSameValue( 'wpia_limit_reached', $result->get_error_code(), 'refusal code' );
		assertSameValue( array( 'status' => 429, 'portee' => 'visiteur' ), $result->get_error_data(), 'refusal data' );
		assertSameValue( 3, $counter->calls, 'provider must not be called for a refused message' );

		assertTrue( ! is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:8' ) ) ), 'another visitor should still pass' );
		assertSameValue( 4, $counter->calls, 'second visitor reaches the provider' );
	}
);

$run(
	'site budget refuses every visitor with portee = site',
	static function () use ( $cipher ): void {
		// Sans usage renvoyé, chaque message garde son estimation (108 tokens).
		list( $service, $store, , $counter ) = limitedService( $cipher, response( 200, '{"choices":[{"message":{"content":"ok"}}]}' ) );
		for ( $i = 1; $i <= 9; ++$i ) {
			assertTrue( ! is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:' . $i ) ) ), "visitor {$i} should pass" );
		}
		assertSameValue( 972, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'site counter keeps estimates' );
		foreach ( array( 'u:100', 'u:101' ) as $visitor ) {
			$result = $service->generate( 'Bonjour', array( 'visiteur' => $visitor ) );
			assertTrue( is_wp_error( $result ), 'site budget should refuse ' . $visitor );
			assertSameValue( 'site', $result->get_error_data()['portee'], 'refused scope' );
		}
		assertSameValue( 9, $counter->calls, 'no provider call once the budget is reached' );
		assertSameValue( 0, $store->messages( UsageKey::visiteur( 1, 'u:100', '2026-01-01' ) ), 'refused visitor is not charged' );
	}
);

$run(
	'next day the same visitor can send 3 messages again, without cron',
	static function () use ( $cipher ): void {
		list( $service, , $clock ) = limitedService( $cipher );
		for ( $i = 1; $i <= 3; ++$i ) {
			$service->generate( 'Bonjour', array( 'visiteur' => 'u:7' ) );
		}
		assertTrue( is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:7' ) ) ), 'refused on day J' );
		$clock->now += DAY_IN_SECONDS;
		for ( $i = 1; $i <= 3; ++$i ) {
			assertTrue( ! is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:7' ) ) ), "J+1 message {$i} should pass" );
		}
	}
);

$run(
	'two reservations exceeding the remaining budget: first passes, second refused',
	static function (): void {
		limitSettings();
		$store   = new InMemoryUsageStore();
		$limiter = new UsageLimiter( $store, new TestClock( '2026-01-01 12:00:00' ) );
		$first   = $limiter->reserver( 1, array( 'visiteur' => 'u:1' ), 600 );
		$second  = $limiter->reserver( 1, array( 'visiteur' => 'u:2' ), 600 );
		assertTrue( is_array( $first ), 'first reservation passes' );
		assertTrue( is_wp_error( $second ), 'second reservation is refused' );
		assertSameValue( 600, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'only the first reservation is counted' );
		assertSameValue( 0, $store->messages( UsageKey::visiteur( 1, 'u:2', '2026-01-01' ) ), 'refused visitor row is untouched' );
	}
);

$run(
	'repository reserves with a single conditional UPDATE',
	static function (): void {
		$wpdb       = new RecordingWpdb();
		$repository = new WPIA\Repository\LimiteUtilisationRepository( $wpdb );
		$wpdb->results = array( 0, 1, 0, 0 );
		$passed = $repository->reserve( UsageKey::site( '2026-01-01' ), 600, 1, 1000, null, '2026-01-01 12:00:00' );
		assertTrue( $passed, 'one affected row means the reservation passed' );
		assertTrue( str_starts_with( $wpdb->queries[0], 'INSERT IGNORE INTO wp_wpia_limite_utilisation' ), 'row is created idempotently' );
		assertTrue( str_contains( $wpdb->queries[1], 'SET nb_tokens = nb_tokens + 600' ), 'increment in the UPDATE' );
		assertTrue( str_contains( $wpdb->queries[1], "portee = 'site' AND identifiant = '' AND jour = '2026-01-01' AND nb_tokens + 600 <= 1000" ), 'ceiling checked in the same statement' );

		$refused = $repository->reserve( UsageKey::site( '2026-01-01' ), 600, 1, 1000, null, '2026-01-01 12:00:00' );
		assertTrue( ! $refused, 'zero affected rows means refusal' );

		$wpdb->queries = array();
		$repository->adjust( UsageKey::site( '2026-01-01' ), -50, -1, '2026-01-01 12:00:00' );
		assertTrue( str_contains( $wpdb->queries[1], 'nb_tokens = IF(nb_tokens > 50, nb_tokens - 50, 0)' ), 'negative correction is floored at 0' );

		$wpdb->queries = array();
		$repository->purgeInactiveBefore( '2025-12-25 12:00:00' );
		assertSameValue( "DELETE FROM wp_wpia_limite_utilisation WHERE portee IN ('visiteur', 'session') AND date_maj < '2025-12-25 12:00:00'", $wpdb->queries[0], 'purge query' );
	}
);

$run(
	'provider total_tokens replaces the estimate',
	static function (): void {
		limitSettings();
		$store       = new InMemoryUsageStore();
		$limiter     = new UsageLimiter( $store, new TestClock( '2026-01-01 12:00:00' ) );
		$reservation = $limiter->reserver( 1, array( 'visiteur' => 'u:1', 'session' => 's:abc' ), 150 );
		assertSameValue( 150, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'estimate reserved' );
		$limiter->confirmer( $reservation, 40 );
		assertSameValue( 40, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'site counter uses real usage' );
		assertSameValue( 40, $store->tokens( UsageKey::session( 1, 's:abc' ) ), 'session counter uses real usage' );
		assertSameValue( 1, $store->messages( UsageKey::visiteur( 1, 'u:1', '2026-01-01' ) ), 'message is counted' );
	}
);

$run(
	'missing provider usage keeps the estimate, 2xx invalid response stays billed',
	static function () use ( $cipher ): void {
		list( $service, $store ) = limitedService( $cipher, response( 200, '{"choices":[{"message":{"content":"ok"}}]}' ) );
		$service->generate( 'Bonjour', array( 'visiteur' => 'u:1' ) );
		assertSameValue( 108, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'estimate kept without usage' );

		list( $service, $store ) = limitedService( $cipher, response( 200, '{"choices":[{"message":{"content":""}}]}' ) );
		assertTrue( is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:1' ) ) ), 'empty answer fails' );
		assertSameValue( 108, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'a 2xx answer was billed: estimate kept' );
	}
);

$run(
	'timeout releases the reservation',
	static function () use ( $cipher ): void {
		list( $service, $store, , $counter ) = limitedService( $cipher, new WP_Error( 'http_request_failed', 'cURL error: operation timed out' ) );
		$result = $service->generate( 'Bonjour', array( 'visiteur' => 'u:1', 'session' => 'jeton' ) );
		assertSameValue( 'wpia_timeout', $result->get_error_code(), 'timeout error' );
		assertSameValue( 1, $counter->calls, 'provider was called' );
		assertSameValue( 0, $store->tokens( UsageKey::site( '2026-01-01' ) ), 'site tokens restored' );
		assertSameValue( 0, $store->messages( UsageKey::site( '2026-01-01' ) ), 'site messages restored' );
		assertSameValue( 0, $store->messages( UsageKey::visiteur( 1, 'u:1', '2026-01-01' ) ), 'visitor messages restored' );
	}
);

$run(
	'conversation budget refuses with portee = session',
	static function () use ( $cipher ): void {
		list( $service, , , $counter ) = limitedService( $cipher, response( 200, '{"choices":[{"message":{"content":"ok"}}]}' ) );
		limitSettings( array( 'max_messages_user' => 100 ) );
		for ( $i = 1; $i <= 5; ++$i ) {
			assertTrue( ! is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:1', 'session' => 'jeton' ) ) ), "message {$i} fits in 600 tokens" );
		}
		$result = $service->generate( 'Bonjour', array( 'visiteur' => 'u:1', 'session' => 'jeton' ) );
		assertSameValue( 'session', $result->get_error_data()['portee'], 'conversation scope refused' );
		assertTrue( ! is_wp_error( $service->generate( 'Bonjour', array( 'visiteur' => 'u:1', 'session' => 'autre' ) ) ), 'a new conversation can start' );
		assertSameValue( 6, $counter->calls, 'no provider call on refusal' );
	}
);

$run(
	'alert e-mail is sent once a day at the threshold',
	static function (): void {
		limitSettings();
		$GLOBALS['wpia_mails'] = array();
		unset( $GLOBALS['wpia_options'][ UsageLimiter::ALERT_OPTION ] );
		$clock   = new TestClock( '2026-01-01 12:00:00' );
		$limiter = new UsageLimiter( new InMemoryUsageStore(), $clock );
		$limiter->confirmer( $limiter->reserver( 1, array(), 790 ), null );
		assertSameValue( 0, count( $GLOBALS['wpia_mails'] ), 'no mail below 80 %' );
		for ( $i = 0; $i < 10; ++$i ) {
			$reservation = $limiter->reserver( 1, array(), 10 );
			if ( is_array( $reservation ) ) {
				$limiter->confirmer( $reservation, null );
			}
		}
		assertSameValue( 1, count( $GLOBALS['wpia_mails'] ), 'a single mail on the same day' );
		assertSameValue( 'admin@example.test', $GLOBALS['wpia_mails'][0]['to'], 'mail recipient' );
		assertSameValue( '2026-01-01 12:00:00', $GLOBALS['wpia_options'][ UsageLimiter::ALERT_OPTION ], 'last alert date stored' );

		$clock->now += DAY_IN_SECONDS;
		$limiter->confirmer( $limiter->reserver( 1, array(), 850 ), null );
		assertSameValue( 2, count( $GLOBALS['wpia_mails'] ), 'a new mail the next day' );
	}
);

$run(
	'no plain IP is stored; IPv6 is counted per /64',
	static function () use ( $cipher ): void {
		$identity = new VisitorIdentity();
		$id       = $identity->forIp( '203.0.113.7' );
		assertTrue( str_starts_with( $id, 'ip:' ) && 67 === strlen( $id ), 'HMAC identifier' );
		assertTrue( ! str_contains( $id, '203.0.113.7' ), 'IP is not in the identifier' );
		assertSameValue( '', $identity->forIp( 'not-an-ip' ), 'invalid IP is ignored' );
		assertSameValue( $identity->forIp( '2001:db8:1:2::aaaa' ), $identity->forIp( '2001:db8:1:2:ffff::1' ), 'same /64 prefix' );
		assertTrue( $identity->forIp( '2001:db8:1:2::1' ) !== $identity->forIp( '2001:db8:1:3::1' ), 'different /64 prefix' );
		assertTrue( ! str_contains( $identity->forSession( 'jeton-secret' ), 'jeton-secret' ), 'session token is hashed' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		list( $service, $store ) = limitedService( $cipher );
		$service->generate( 'Bonjour', array( 'session' => 'jeton-secret' ) );
		unset( $_SERVER['REMOTE_ADDR'] );
		$dump = serialize( $store->rows );
		assertTrue( ! str_contains( $dump, '203.0.113.7' ), 'no plain IP in usage rows' );
		assertTrue( ! str_contains( $dump, 'jeton-secret' ), 'no plain session token in usage rows' );
		assertSameValue( 1, $store->messages( UsageKey::visiteur( 1, $id, '2026-01-01' ) ), 'anonymous visitor counted by IP hash' );
	}
);

$run(
	'storage failure refuses (503) without calling the provider',
	static function () use ( $cipher ): void {
		limitSettings();
		activeOpenAi();
		$store = new InMemoryUsageStore();
		$store->failNext = true;
		$called = false;
		$GLOBALS['wpia_http_callback'] = static function () use ( &$called ): array {
			$called = true;
			return response( 200, '{}' );
		};
		$result = service( $cipher, new UsageLimiter( $store, new TestClock( '2026-01-01 12:00:00' ) ) )->generate( 'Bonjour', array( 'visiteur' => 'u:1' ) );
		assertSameValue( 'wpia_usage_unavailable', $result->get_error_code(), 'fail closed' );
		assertSameValue( 503, $result->get_error_data()['status'], 'service unavailable' );
		assertTrue( ! $called, 'provider not called' );
	}
);

$run(
	'purge removes inactive visitor and session rows, keeps site rows',
	static function (): void {
		limitSettings();
		$store = new InMemoryUsageStore();
		$clock = new TestClock( '2026-01-01 12:00:00' );
		$old   = new UsageLimiter( $store, $clock );
		$old->reserver( 1, array( 'visiteur' => 'u:1', 'session' => 's:old' ), 10 );

		$clock->now += 6 * DAY_IN_SECONDS;
		$old->reserver( 1, array( 'visiteur' => 'u:2', 'session' => 's:recent' ), 10 );

		$clock->now += 2 * DAY_IN_SECONDS;
		$deleted = $old->purger( 7 );
		assertSameValue( 2, $deleted, 'old visitor and session rows deleted' );
		assertSameValue( 0, $store->messages( UsageKey::visiteur( 1, 'u:1', '2026-01-01' ) ), 'old visitor row purged' );
		assertSameValue( 1, $store->messages( UsageKey::visiteur( 1, 'u:2', '2026-01-07' ) ), 'recent visitor row kept' );
		assertSameValue( 1, $store->messages( UsageKey::session( 1, 's:recent' ) ), 'recent session kept' );
		assertSameValue( 1, $store->messages( UsageKey::site( '2026-01-01' ) ), 'site history kept' );
	}
);

assertSameValue( 27, $tests, 'total passing test count' );
echo "All {$tests} tests passed.\n";
