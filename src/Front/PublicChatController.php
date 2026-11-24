<?php

namespace WPIA\Front;

use WPIA\Repository\ChatbotRepository;
use WPIA\Service\Ia\AiGenerationService;
use WPIA\Service\MarkdownRenderer;
use WPIA\Service\Usage\VisitorIdentity;

/**
 * Lien entre WordPress et le chat public (issue 007) : routes REST, cookie de session,
 * nonce, en-têtes de cache. Toute la logique est dans `PublicChatHandler`.
 *
 * Routes :
 * - GET  wpia/v1/session : nonce frais, configuration publique, cookie `wpia_session`.
 *   Jamais mise en cache : le nonce n'est donc jamais écrit dans le HTML d'une page
 *   que le cache de page pourrait servir plus de 24 h.
 * - POST wpia/v1/chat    : un message. Le nonce `wp_rest` est contrôlé par WordPress.
 */
final class PublicChatController {
	public const NAMESPACE_REST = 'wpia/v1';
	public const COOKIE         = 'wpia_session';

	/** Balises et attributs autorisés dans une réponse affichée (liste blanche de l'issue 007). */
	private const ALLOWED_HTML = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'em'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'code'   => array(),
		'pre'    => array(),
		'a'      => array(
			'href'   => true,
			'rel'    => true,
			'target' => true,
		),
	);

	private ?PublicChatHandler $handler = null;

	public const SHORTCODE = 'wpia_chat';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'renderShortcode' ) );
	}

	/**
	 * `[wpia_chat chatbot="ID"]` : sans attribut, le chatbot actif. Les assets ne sont mis en
	 * file d'attente qu'ici, donc seulement sur une page qui contient le shortcode. Le nonce
	 * n'est jamais écrit dans le HTML (voir `session()`).
	 *
	 * @param array<string, string>|string $atts
	 */
	public function renderShortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'chatbot' => '0' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$id   = absint( $atts['chatbot'] );

		$public = $this->handler()->chatbotPublic( $id );
		if ( 0 === $public ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="wpia-chat-notice">' . esc_html__( 'Chat IA non affiché (visible des administrateurs seulement) : l’affichage du chatbot est désactivé, ou le chatbot demandé est inactif ou introuvable.', 'plugin-wordpress-ia' ) . '</p>';
			}

			return '';
		}

		$this->enqueueAssets();

		return '<div class="wpia-chat" data-wpia-chatbot="' . esc_attr( (string) $public ) . '"></div>';
	}

	private function enqueueAssets(): void {
		$base = dirname( __DIR__, 2 ) . '/plugin-wordpress-ia.php';
		wp_enqueue_style( 'wpia-chat', plugins_url( 'assets/css/chat.css', $base ), array(), WPIA_VERSION );
		wp_enqueue_script( 'wpia-chat', plugins_url( 'assets/js/chat.js', $base ), array(), WPIA_VERSION, true );

		static $localise = false; // Une seule fois, même avec plusieurs shortcodes sur la page.
		if ( $localise ) {
			return;
		}
		$localise = true;

		$confidentialite = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';
		wp_localize_script(
			'wpia-chat',
			'wpiaChat',
			array(
				'restUrl'         => esc_url_raw( rest_url( self::NAMESPACE_REST ) ),
				'confidentialite' => $confidentialite ? esc_url_raw( $confidentialite ) : '',
				'textes'          => array(
					'ouvrir'          => __( 'Ouvrir le chat', 'plugin-wordpress-ia' ),
					'fermer'          => __( 'Fermer le chat', 'plugin-wordpress-ia' ),
					'titre'           => __( 'Assistant', 'plugin-wordpress-ia' ),
					'messages'        => __( 'Conversation', 'plugin-wordpress-ia' ),
					'saisie'          => __( 'Votre message', 'plugin-wordpress-ia' ),
					'placeholder'     => __( 'Posez votre question…', 'plugin-wordpress-ia' ),
					'envoyer'         => __( 'Envoyer', 'plugin-wordpress-ia' ),
					'attente'         => __( 'L’assistant écrit…', 'plugin-wordpress-ia' ),
					'suggestions'     => __( 'Questions suggérées', 'plugin-wordpress-ia' ),
					'avertissement'   => __( 'Réponses générées par une IA, elles peuvent contenir des erreurs.', 'plugin-wordpress-ia' ),
					'confidentialite' => __( 'Politique de confidentialité', 'plugin-wordpress-ia' ),
					'vous'            => __( 'Vous', 'plugin-wordpress-ia' ),
					'assistant'       => __( 'Assistant', 'plugin-wordpress-ia' ),
					'erreur_service'  => __( 'Le service est momentanément indisponible. Réessayez dans quelques instants.', 'plugin-wordpress-ia' ),
					'erreur_long'     => __( 'Votre message est trop long.', 'plugin-wordpress-ia' ),
					'erreur_session'  => __( 'La session a expiré. Rechargez la page puis réessayez.', 'plugin-wordpress-ia' ),
					'erreur_rapide'   => __( 'Merci de patienter quelques secondes entre deux messages.', 'plugin-wordpress-ia' ),
					'limite'          => __( 'Limite atteinte : vous pourrez écrire de nouveau demain.', 'plugin-wordpress-ia' ),
					'indisponible'    => __( 'Le chat est indisponible.', 'plugin-wordpress-ia' ),
				),
			)
		);
	}

	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE_REST,
			'/session',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'session' ),
				'permission_callback' => '__return_true', // Configuration publique uniquement, aucun secret.
				'args'                => array(
					'chatbot' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_REST,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => array( $this, 'canChat' ),
				'args'                => array(
					'chatbot'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'message'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'historique' => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Le chatbot demandé doit être actif et public. Le nonce, lui, est contrôlé par le
	 * noyau de WordPress avant ce rappel (403 `rest_cookie_invalid_nonce` s'il est périmé).
	 *
	 * @return true|\WP_Error
	 */
	public function canChat( \WP_REST_Request $request ) {
		$demande = $request->get_param( 'chatbot' );
		if ( 0 === $this->handler()->chatbotPublic( null === $demande ? null : absint( $demande ) ) ) {
			return new \WP_Error( 'wpia_unavailable', __( 'Le chat est indisponible.', 'plugin-wordpress-ia' ), array( 'status' => 404 ) );
		}

		return true;
	}

	public function session( \WP_REST_Request $request ): \WP_REST_Response {
		$demande  = $request->get_param( 'chatbot' );
		$resultat = $this->handler()->config( null === $demande ? null : absint( $demande ) );
		if ( 200 === $resultat['status'] ) {
			$resultat['body']['nonce'] = $this->nonce();
			$this->poserCookie();
		}

		return $this->reponse( $resultat );
	}

	public function chat( \WP_REST_Request $request ): \WP_REST_Response {
		$jeton    = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$resultat = $this->handler()->chat(
			array(
				'chatbot'    => $request->get_param( 'chatbot' ),
				'message'    => $request->get_param( 'message' ),
				'historique' => $request->get_param( 'historique' ),
			),
			array(
				'visiteur' => ( new VisitorIdentity() )->fromRequest(),
				'session'  => 1 === preg_match( '/^[a-f0-9]{32}$/', $jeton ) ? $jeton : '',
			)
		);

		return $this->reponse( $resultat );
	}

	/**
	 * Nonce `wp_rest` du visiteur réel. Une requête REST sans nonce est traitée par WordPress
	 * comme anonyme : sans ce détour, un utilisateur connecté recevrait un nonce d'anonyme,
	 * refusé ensuite à l'envoi du message. Le cookie d'authentification est donc validé ici.
	 */
	private function nonce(): string {
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( is_int( $user_id ) && $user_id > 0 ) {
			wp_set_current_user( $user_id );
		}

		return wp_create_nonce( 'wp_rest' );
	}

	/** Jeton de conversation : aléatoire, jamais déduit d'une identité, stocké haché côté serveur. */
	private function poserCookie(): void {
		$actuel = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $actuel ) || headers_sent() ) {
			return;
		}

		$jeton = bin2hex( random_bytes( 16 ) );
		setcookie(
			self::COOKIE,
			$jeton,
			array(
				'expires'  => 0, // Cookie de session du navigateur.
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $jeton;
	}

	/**
	 * @param array{status:int, body:array<string, mixed>} $resultat
	 */
	private function reponse( array $resultat ): \WP_REST_Response {
		$reponse = new \WP_REST_Response( $resultat['body'], $resultat['status'] );
		$reponse->header( 'Cache-Control', 'no-store, max-age=0' );
		if ( isset( $resultat['body']['retry_after'] ) ) {
			$reponse->header( 'Retry-After', (string) (int) $resultat['body']['retry_after'] );
		}

		return $reponse;
	}

	private function handler(): PublicChatHandler {
		if ( null === $this->handler ) {
			$this->handler = new PublicChatHandler(
				new AiGenerationService(),
				new ChatbotRepository( $GLOBALS['wpdb'] ),
				new MarkdownRenderer(),
				static fn( string $html ): string => wp_kses( $html, self::ALLOWED_HTML, array( 'http', 'https', 'mailto' ) ),
				array( self::class, 'burst' )
			);
		}

		return $this->handler;
	}

	/**
	 * Une requête toutes les `BURST_SECONDS` secondes par identité (transient de courte durée).
	 * Complète les quotas de 005 sans les remplacer ; la course entre deux requêtes simultanées
	 * est acceptée.
	 */
	public static function burst( string $identite ): bool {
		if ( '' === $identite ) {
			return true;
		}
		$cle = 'wpia_burst_' . md5( $identite );
		if ( false !== get_transient( $cle ) ) {
			return false;
		}
		set_transient( $cle, 1, PublicChatHandler::BURST_SECONDS );

		return true;
	}
}
