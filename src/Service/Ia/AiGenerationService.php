<?php

namespace WPIA\Service\Ia;

use RuntimeException;
use WPIA\Admin\AdminSettings;
use WPIA\Repository\ChatbotRepository;
use WPIA\Repository\CompteApiRepository;
use WPIA\Repository\FournisseurIARepository;
use WPIA\Repository\LogRepository;
use WPIA\Service\Ia\Fournisseur\AbstractProvider;
use WPIA\Service\Ia\Fournisseur\AnthropicProvider;
use WPIA\Service\Ia\Fournisseur\CustomProvider;
use WPIA\Service\Ia\Fournisseur\MistralProvider;
use WPIA\Service\Ia\Fournisseur\OllamaProvider;
use WPIA\Service\Ia\Fournisseur\OpenAiProvider;
use WPIA\Repository\LimiteUtilisationRepository;
use WPIA\Service\Prompt\PromptAssembler;
use WPIA\Service\Prompt\TokenEstimator;
use WPIA\Service\Securite\SecretCipher;
use WPIA\Service\Usage\UsageLimiter;
use WPIA\Service\Usage\VisitorIdentity;

class AiGenerationService {
	/** Bornes de sécurité sur la conversation reçue (avant troncature). */
	private const MAX_MESSAGES = 40;
	private const MESSAGE_MAX_CARACTERES = 8000;

	private ?\wpdb $wpdb;
	private SecretCipher $cipher;
	private ResponseNormalizer $normalizer;
	private ?UsageLimiter $limiter;
	private VisitorIdentity $identity;
	private ?PromptAssembler $assembler;

	public function __construct( ?\wpdb $wpdb = null, ?SecretCipher $cipher = null, ?ResponseNormalizer $normalizer = null, ?UsageLimiter $limiter = null, ?VisitorIdentity $identity = null, ?PromptAssembler $assembler = null ) {
		if ( null === $wpdb && isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof \wpdb ) {
			$wpdb = $GLOBALS['wpdb'];
		}

		$this->wpdb       = $wpdb;
		$this->cipher     = $cipher ?? new SecretCipher();
		$this->normalizer = $normalizer ?? new ResponseNormalizer();
		$this->limiter    = $limiter ?? ( null !== $wpdb ? new UsageLimiter( new LimiteUtilisationRepository( $wpdb ) ) : null );
		$this->identity   = $identity ?? new VisitorIdentity();
		$this->assembler  = $assembler;
	}

	/**
	 * Échange unique : conservé pour la compatibilité (test de connexion, appelants existants).
	 *
	 * @param array{visiteur?:string,session?:string} $contexte
	 *        visiteur : identifiant pseudonyme déjà calculé (par défaut : VisitorIdentity::fromRequest()).
	 *        session  : jeton brut de conversation (cookie wpia_session), haché avant stockage.
	 * @return array{content:string,model:string,usage:array{input_tokens:?int,output_tokens:?int,total_tokens:?int}}|\WP_Error
	 */
	public function generate( string $prompt, array $contexte = array() ): array|\WP_Error {
		if ( '' === trim( $prompt ) ) {
			return $this->failure( 'wpia_invalid_request', __( 'Le prompt ne peut pas être vide.', 'plugin-wordpress-ia' ) );
		}

		return $this->generateChat(
			array(
				'messages'  => array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'identites' => $contexte,
			)
		);
	}

	/**
	 * Conversation complète : historique et question.
	 *
	 * @param array{messages:array<int, array{role:string,content:string}>,chatbot_id?:int,identites?:array{visiteur?:string,session?:string}} $requete
	 *        messages   : conversation, le dernier message étant la question (rôle user).
	 *        chatbot_id : facultatif ; doit être le chatbot actif (MVP).
	 *        identites  : même contenu que le `$contexte` de generate().
	 * @return array{content:string,model:string,usage:array{input_tokens:?int,output_tokens:?int,total_tokens:?int}}|\WP_Error
	 */
	public function generateChat( array $requete ): array|\WP_Error {
		$messages = $this->validerMessages( $requete['messages'] ?? null );
		if ( is_wp_error( $messages ) ) {
			return $messages;
		}
		$identites  = isset( $requete['identites'] ) && is_array( $requete['identites'] ) ? $requete['identites'] : array();
		$demande_id = isset( $requete['chatbot_id'] ) ? absint( $requete['chatbot_id'] ) : null;

		if ( null === $this->wpdb ) {
			return $this->failure( 'wpia_configuration_error', __( 'La configuration de génération est indisponible.', 'plugin-wordpress-ia' ) );
		}

		try {
			$config = $this->loadConfiguration( $demande_id );
		} catch ( RuntimeException $exception ) {
			return $this->logFailure(
				'configuration',
				$this->failure( 'wpia_configuration_error', __( 'La configuration de génération est indisponible.', 'plugin-wordpress-ia' ) )
			);
		}

		if ( is_wp_error( $config ) ) {
			return $this->logFailure( $config->get_error_code(), $config );
		}

		$api_key = '';
		if ( 'ollama' !== $config['provider'] ) {
			if ( '' === $config['encrypted_key'] ) {
				return $this->logFailure(
					$config['provider'],
					$this->failure( 'wpia_missing_api_key', __( 'Aucune clé API n’est configurée pour ce fournisseur.', 'plugin-wordpress-ia' ) )
				);
			}
			if ( ! $this->cipher->isEncrypted( $config['encrypted_key'] ) ) {
				return $this->logFailure(
					$config['provider'],
					$this->failure( 'wpia_secret_error', __( 'La clé API doit être chiffrée depuis la configuration du plugin.', 'plugin-wordpress-ia' ) )
				);
			}

			try {
				$api_key = $this->cipher->decrypt( $config['encrypted_key'] );
			} catch ( RuntimeException $exception ) {
				return $this->logFailure(
					$config['provider'],
					$this->failure( 'wpia_secret_error', __( 'La clé API enregistrée ne peut pas être utilisée.', 'plugin-wordpress-ia' ) )
				);
			}
		}

		if ( null === $this->limiter ) {
			return $this->failure( 'wpia_configuration_error', __( 'La configuration de génération est indisponible.', 'plugin-wordpress-ia' ) );
		}

		$question     = $messages[ count( $messages ) - 1 ];
		$systeme      = $this->buildSystem( $config, $question['content'] );
		if ( is_wp_error( $systeme ) ) {
			return $this->logFailure( 'prompt', $systeme );
		}
		$historique   = $this->tronquerHistorique( array_slice( $messages, 0, -1 ), AdminSettings::get() );
		$conversation = array_merge( $historique, array( $question ) );

		// Réservation avant l'appel : en cas de refus, le fournisseur n'est pas contacté.
		// L'estimation porte sur tout ce qui est envoyé, après troncature de l'historique.
		$estimation  = $this->limiter->estimer(
			array_merge( array( $systeme['stable'], $systeme['variable'] ), array_column( $conversation, 'content' ) ),
			$config['max_tokens']
		);
		$reservation = $this->limiter->reserver(
			$config['chatbot_id'],
			array(
				'visiteur' => isset( $identites['visiteur'] ) && is_string( $identites['visiteur'] ) ? $identites['visiteur'] : $this->identity->fromRequest(),
				'session'  => isset( $identites['session'] ) && is_string( $identites['session'] ) ? $this->identity->forSession( $identites['session'] ) : '',
			),
			$estimation
		);
		if ( is_wp_error( $reservation ) ) {
			return $this->logFailure( 'limits', $reservation );
		}

		$provider = $this->getProvider( $config['provider'] );
		$response = $provider->generate(
			$config['url'],
			$api_key,
			array(
				'model'       => $config['model'],
				'systeme'     => $systeme,
				'messages'    => $conversation,
				'temperature' => $config['temperature'],
				'max_tokens'  => $config['max_tokens'],
				'timeout'     => $config['timeout'],
			)
		);

		$result = $this->normalizer->normalize( $config['provider'], $response, $config['model'], $config['max_tokens'] );
		if ( is_wp_error( $result ) ) {
			// Une réponse 2xx a été facturée même si elle est inexploitable :
			// l'estimation est conservée. Sinon (réseau, 4xx, 5xx), on libère.
			if ( $this->wasBilled( $response ) ) {
				$this->limiter->confirmer( $reservation, null );
			} else {
				$this->limiter->annuler( $reservation );
			}
			return $this->logFailure( $config['provider'], $result );
		}

		$this->limiter->confirmer( $reservation, $result['usage']['total_tokens'] );

		return $result;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function loadConfiguration( ?int $demande_id = null ): array|\WP_Error {
		$active = get_option( 'wpia_active_configuration', array() );
		if ( ! is_array( $active ) ) {
			return $this->failure( 'wpia_configuration_error', __( 'La configuration de génération est indisponible.', 'plugin-wordpress-ia' ) );
		}

		$type = isset( $active['provider_type'] ) && is_string( $active['provider_type'] )
			? sanitize_key( $active['provider_type'] )
			: '';
		$catalog = AIProviderCatalog::all();
		if ( ! isset( $catalog[ $type ] ) ) {
			return $this->failure( 'wpia_configuration_error', __( 'Le fournisseur sélectionné est invalide.', 'plugin-wordpress-ia' ) );
		}

		$provider_id = isset( $active['provider_id'] ) ? absint( $active['provider_id'] ) : 0;
		$account_id  = isset( $active['account_id'] ) ? absint( $active['account_id'] ) : 0;
		$chatbot_id  = isset( $active['chatbot_id'] ) ? absint( $active['chatbot_id'] ) : 0;
		if ( $provider_id < 1 || $account_id < 1 || $chatbot_id < 1 ) {
			return $this->failure( 'wpia_configuration_error', __( 'La configuration de génération est incomplète.', 'plugin-wordpress-ia' ) );
		}
		// MVP : seul le chatbot actif peut être demandé (le type de fournisseur vient de l'option).
		if ( null !== $demande_id && $demande_id !== $chatbot_id ) {
			return $this->failure( 'wpia_configuration_error', __( 'Le chatbot demandé est indisponible.', 'plugin-wordpress-ia' ) );
		}

		$provider = ( new FournisseurIARepository( $this->wpdb ) )->getById( $provider_id );
		$account  = ( new CompteApiRepository( $this->wpdb ) )->getById( $account_id );
		$chatbot  = ( new ChatbotRepository( $this->wpdb ) )->getById( $chatbot_id );
		if (
			! is_array( $provider )
			|| ! is_array( $account )
			|| ! is_array( $chatbot )
			|| 1 !== (int) $provider['actif']
			|| 1 !== (int) $account['actif']
			|| 1 !== (int) $chatbot['actif']
			|| $provider_id !== (int) $account['fournisseur_id']
			|| $account_id !== (int) $chatbot['compte_api_id']
		) {
			return $this->failure( 'wpia_configuration_error', __( 'La configuration de génération est invalide ou inactive.', 'plugin-wordpress-ia' ) );
		}

		$url   = isset( $provider['url_api'] ) && is_string( $provider['url_api'] ) ? untrailingslashit( trim( $provider['url_api'] ) ) : '';
		$model = isset( $chatbot['modele'] ) && is_string( $chatbot['modele'] ) ? trim( $chatbot['modele'] ) : '';
		if ( '' === $url || '' === $model || ! $this->isValidProviderUrl( $type, $url ) ) {
			return $this->failure( 'wpia_configuration_error', __( 'L’URL ou le modèle configuré est invalide.', 'plugin-wordpress-ia' ) );
		}

		$settings = AdminSettings::get();
		$max      = min( (int) $chatbot['max_tokens'], (int) $settings['max_tokens_request'] );
		$timeout  = (int) $settings['api_timeout'];
		$temp     = is_numeric( $chatbot['temperature'] ) ? (float) $chatbot['temperature'] : -1;
		if ( $max < 1 || $max > 128000 || $timeout < 1 || $timeout > 120 || ! is_finite( $temp ) || $temp < 0 || $temp > 2 ) {
			return $this->failure( 'wpia_configuration_error', __( 'Les paramètres de génération configurés sont invalides.', 'plugin-wordpress-ia' ) );
		}

		return array(
			'chatbot_id'    => $chatbot_id,
			'provider'      => $type,
			'url'            => $url,
			'model'          => $model,
			'chatbot'        => $chatbot,
			'system_prompt'  => isset( $chatbot['system_prompt'] ) && is_string( $chatbot['system_prompt'] ) ? $chatbot['system_prompt'] : '',
			'temperature'    => $temp,
			'max_tokens'     => $max,
			'timeout'        => $timeout,
			'encrypted_key'  => isset( $account['api_key'] ) && is_string( $account['api_key'] ) ? $account['api_key'] : '',
		);
	}

	/**
	 * Prompt système construit côté serveur, jamais reçu de la requête : les quatre
	 * blocs de PromptAssembler (règles, consignes de l'admin, contexte, documents).
	 * En cas d'échec de lecture, la requête est refusée plutôt qu'envoyée sans les
	 * règles ni les documents.
	 *
	 * @param array<string, mixed> $config
	 * @return array{stable:string,variable:string}|\WP_Error
	 */
	private function buildSystem( array $config, string $question ): array|\WP_Error {
		try {
			// Création différée : elle dérive des clés et peut échouer (OpenSSL indisponible).
			$assembler = $this->assembler ?? ( null !== $this->wpdb ? PromptAssembler::create( $this->wpdb ) : null );
			if ( null === $assembler ) {
				return $this->failure( 'wpia_configuration_error', __( 'La configuration de génération est indisponible.', 'plugin-wordpress-ia' ) );
			}
			$resultat = $assembler->assembler( $config['chatbot'], (string) get_bloginfo( 'name' ), $question );
		} catch ( RuntimeException $exception ) {
			return $this->failure( 'wpia_configuration_error', __( 'Le contexte du chatbot est indisponible.', 'plugin-wordpress-ia' ) );
		}

		// Relevé sans aucun contenu : mode, tokens par bloc, identifiants de sections.
		try {
			( new LogRepository( $this->wpdb ) )->create( 'info', 'prompt', (string) wp_json_encode( $resultat['releve'] ) );
		} catch ( RuntimeException $exception ) {
			error_log( 'WPIA: unable to log the prompt statement.' );
		}

		return $resultat['systeme'];
	}

	/**
	 * @param mixed $messages
	 * @return array<int, array{role:string,content:string}>|\WP_Error
	 */
	private function validerMessages( $messages ): array|\WP_Error {
		$invalide = $this->failure( 'wpia_invalid_request', __( 'La conversation est invalide.', 'plugin-wordpress-ia' ) );

		if ( ! is_array( $messages ) || array() === $messages || count( $messages ) > self::MAX_MESSAGES || array_keys( $messages ) !== range( 0, count( $messages ) - 1 ) ) {
			return $invalide;
		}

		$resultat = array();
		foreach ( $messages as $message ) {
			if (
				! is_array( $message )
				|| ! isset( $message['role'], $message['content'] )
				|| ! is_string( $message['role'] )
				|| ! in_array( $message['role'], array( 'user', 'assistant' ), true )
				|| ! is_string( $message['content'] )
				|| '' === trim( $message['content'] )
				|| TokenEstimator::caracteres( $message['content'] ) > self::MESSAGE_MAX_CARACTERES
			) {
				return $invalide;
			}
			$resultat[] = array(
				'role'    => $message['role'],
				'content' => $message['content'],
			);
		}

		return 'user' === $resultat[ count( $resultat ) - 1 ]['role'] ? $resultat : $invalide;
	}

	/**
	 * Garde les derniers messages de l'historique (la question n'en fait pas partie),
	 * dans le budget de tokens, en coupant toujours au début. Le premier message
	 * conservé est un message `user`. Option désactivée : aucun historique.
	 *
	 * @param array<int, array{role:string,content:string}> $historique
	 * @param array<string, mixed>                          $settings
	 * @return array<int, array{role:string,content:string}>
	 */
	private function tronquerHistorique( array $historique, array $settings ): array {
		if ( empty( $settings['history_enabled'] ) ) {
			return array();
		}

		$max    = max( 1, (int) $settings['history_max_messages'] );
		$budget = (int) $settings['history_token_budget'];
		if ( count( $historique ) > $max ) {
			$historique = array_slice( $historique, -$max );
		}

		$historique = $this->commencerParUser( $historique );
		while ( array() !== $historique && $this->tokensDe( $historique ) > $budget ) {
			$historique = $this->commencerParUser( array_slice( $historique, 2 ) );
		}

		return $historique;
	}

	/**
	 * @param array<int, array{role:string,content:string}> $messages
	 * @return array<int, array{role:string,content:string}>
	 */
	private function commencerParUser( array $messages ): array {
		while ( array() !== $messages && 'user' !== $messages[0]['role'] ) {
			array_shift( $messages );
		}

		return array_values( $messages );
	}

	/**
	 * @param array<int, array{role:string,content:string}> $messages
	 */
	private function tokensDe( array $messages ): int {
		$total = 0;
		foreach ( $messages as $message ) {
			$total += TokenEstimator::estimer( $message['content'] );
		}

		return $total;
	}

	private function isValidProviderUrl( string $type, string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}

		if ( 'ollama' === $type ) {
			return 'http' === strtolower( $parts['scheme'] )
				&& in_array( strtolower( $parts['host'] ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		}

		return 'https' === strtolower( $parts['scheme'] );
	}

	private function getProvider( string $type ): AbstractProvider {
		$providers = array(
			'openai'    => OpenAiProvider::class,
			'mistral'   => MistralProvider::class,
			'anthropic' => AnthropicProvider::class,
			'ollama'    => OllamaProvider::class,
			'custom'    => CustomProvider::class,
		);

		$provider_class = $providers[ $type ];
		return new $provider_class();
	}

	/**
	 * @param mixed $response
	 */
	private function wasBilled( $response ): bool {
		if ( ! is_array( $response ) ) {
			return false;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 300;
	}

	private function failure( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	/**
	 * @param \WP_Error $error
	 * @return \WP_Error
	 */
	private function logFailure( string $provider, \WP_Error $error ): \WP_Error {
		$provider = in_array( $provider, array( 'openai', 'mistral', 'anthropic', 'ollama', 'custom', 'configuration', 'limits' ), true )
			? $provider
			: 'configuration';
		$code = sanitize_key( $error->get_error_code() );

		if ( null !== $this->wpdb ) {
			try {
				( new LogRepository( $this->wpdb ) )->create( 'error', 'api', 'AI generation failed (' . $provider . ', ' . $code . ').' );
			} catch ( RuntimeException $exception ) {
				error_log( 'WPIA: unable to write a sanitized AI generation log.' );
			}
		}

		return $error;
	}
}
