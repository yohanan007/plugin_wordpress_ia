<?php

namespace WPIA\Front;

use WPIA\Admin\AdminSettings;
use WPIA\Repository\ChatbotRepository;
use WPIA\Service\Ia\AiGenerationService;
use WPIA\Service\MarkdownRenderer;
use WPIA\Service\Prompt\TokenEstimator;

/**
 * Logique du chat public (issue 007), sans objet REST : prend des tableaux, renvoie
 * `{status, body}`. `PublicChatController` ne fait que le lien avec WordPress.
 *
 * Aucune réponse ne contient la clé API, le prompt système ni les documents. Les erreurs
 * du fournisseur ne sont jamais transmises : le détail reste dans les journaux du service.
 */
final class PublicChatHandler {
	/** Longueur maximale d'un message de l'historique (les réponses de l'IA sont plus longues qu'une question). */
	public const HISTORY_MESSAGE_MAX = 4000;

	/** Délai minimal entre deux requêtes d'une même identité, en secondes. */
	public const BURST_SECONDS = 2;

	private AiGenerationService $ia;
	private ChatbotRepository $chatbots;
	private MarkdownRenderer $markdown;
	/** @var callable(string): string */
	private $sanitize_html;
	/** @var callable(string): bool */
	private $burst;

	/**
	 * @param callable(string): string $sanitize_html Assainissement final du HTML (wp_kses avec la liste blanche).
	 * @param callable(string): bool   $burst         Vrai si l'identité peut envoyer (et enregistre la requête) ; faux si trop rapide.
	 */
	public function __construct( AiGenerationService $ia, ChatbotRepository $chatbots, MarkdownRenderer $markdown, callable $sanitize_html, callable $burst ) {
		$this->ia            = $ia;
		$this->chatbots      = $chatbots;
		$this->markdown      = $markdown;
		$this->sanitize_html = $sanitize_html;
		$this->burst         = $burst;
	}

	/**
	 * Identifiant du chatbot public, ou 0 s'il n'y en a pas : affichage désactivé,
	 * aucune configuration active, ou chatbot inactif / différent de celui demandé.
	 */
	public function chatbotPublic( ?int $demande = null ): int {
		$reglages = AdminSettings::get();
		if ( empty( $reglages['chatbot_enabled'] ) ) {
			return 0;
		}

		$active = get_option( 'wpia_active_configuration', array() );
		$id     = is_array( $active ) && isset( $active['chatbot_id'] ) ? absint( $active['chatbot_id'] ) : 0;
		if ( $id < 1 || ( null !== $demande && $demande > 0 && $demande !== $id ) ) {
			return 0;
		}

		$chatbot = $this->chatbots->getById( $id );

		return is_array( $chatbot ) && 1 === (int) $chatbot['actif'] ? $id : 0;
	}

	/**
	 * Configuration publique pour `GET session` : rien d'autre que ce qu'un visiteur peut voir.
	 *
	 * @return array{status:int, body:array<string, mixed>}
	 */
	public function config( ?int $demande = null ): array {
		$id = $this->chatbotPublic( $demande );
		if ( 0 === $id ) {
			return $this->erreur( 404, 'wpia_unavailable', __( 'Le chat est indisponible.', 'plugin-wordpress-ia' ) );
		}

		$chatbot   = (array) $this->chatbots->getById( $id );
		$reglages  = AdminSettings::get();
		$questions = json_decode( is_string( $chatbot['questions_suggerees'] ?? null ) ? $chatbot['questions_suggerees'] : '', true );
		$questions = is_array( $questions ) ? array_values( array_filter( $questions, 'is_string' ) ) : array();

		return array(
			'status' => 200,
			'body'   => array(
				'chatbot'            => $id,
				'accueil'            => (string) ( $chatbot['message_accueil'] ?? '' ),
				'questions'          => $questions,
				'longueur_max'       => (int) $reglages['max_message_length'],
				'historique_actif'   => ! empty( $reglages['history_enabled'] ),
				'historique_max'     => (int) $reglages['history_max_messages'],
			),
		);
	}

	/**
	 * @param array{chatbot?:mixed,message?:mixed,historique?:mixed} $entree
	 * @param array{visiteur?:string,session?:string}                 $identites Identités déjà calculées (visiteur) et jeton brut (session).
	 * @return array{status:int, body:array<string, mixed>}
	 */
	public function chat( array $entree, array $identites ): array {
		$demande = isset( $entree['chatbot'] ) ? absint( $entree['chatbot'] ) : 0;
		$id      = $this->chatbotPublic( $demande );
		if ( 0 === $id ) {
			return $this->erreur( 404, 'wpia_unavailable', __( 'Le chat est indisponible.', 'plugin-wordpress-ia' ) );
		}

		$reglages = AdminSettings::get();
		$message  = $this->nettoyerMessage( $entree['message'] ?? null, (int) $reglages['max_message_length'] );
		if ( null === $message ) {
			return $this->erreur( 400, 'wpia_invalid_message', __( 'Le message est vide ou trop long.', 'plugin-wordpress-ia' ) );
		}

		$historique = array();
		if ( ! empty( $reglages['history_enabled'] ) ) {
			$historique = $this->validerHistorique( $entree['historique'] ?? array(), (int) $reglages['history_max_messages'] );
			if ( null === $historique ) {
				return $this->erreur( 400, 'wpia_invalid_history', __( 'L’historique de la conversation est invalide.', 'plugin-wordpress-ia' ) );
			}
		}

		// Anti-rafale : après la validation, pour qu'une requête refusée ne bloque pas la suivante.
		$cle = (string) ( $identites['visiteur'] ?? '' );
		if ( '' === $cle ) {
			$cle = (string) ( $identites['session'] ?? '' );
		}
		// Sans identité, une clé commune ferait attendre tous les visiteurs anonymes : on s'en passe
		// (les quotas de 005 restent appliqués).
		if ( '' !== $cle && ! ( $this->burst )( $cle ) ) {
			return $this->erreur( 429, 'wpia_too_fast', __( 'Merci de patienter quelques secondes entre deux messages.', 'plugin-wordpress-ia' ), array( 'retry_after' => self::BURST_SECONDS ) );
		}

		$historique[] = array(
			'role'    => 'user',
			'content' => $message,
		);
		$resultat     = $this->ia->generateChat(
			array(
				'messages'   => $historique,
				'chatbot_id' => $id,
				'identites'  => $identites,
			)
		);

		if ( is_wp_error( $resultat ) ) {
			return $this->erreurDepuis( $resultat );
		}

		$texte = (string) $resultat['content']; // Déjà passé par OutputGuard dans generateChat().

		return array(
			'status' => 200,
			'body'   => array(
				'texte' => $texte,
				'html'  => ( $this->sanitize_html )( $this->markdown->render( $texte ) ),
			),
		);
	}

	private function nettoyerMessage( $brut, int $max ): ?string {
		if ( ! is_string( $brut ) ) {
			return null;
		}

		$message = trim( sanitize_textarea_field( $brut ) );
		if ( '' === $message || TokenEstimator::caracteres( $message ) > $max ) {
			return null;
		}

		return $message;
	}

	private static function tronquer( string $texte, int $max ): string {
		if ( TokenEstimator::caracteres( $texte ) <= $max ) {
			return $texte;
		}
		if ( 1 === preg_match( '/^.{0,' . $max . '}/us', $texte, $debut ) ) {
			return $debut[0];
		}

		return substr( $texte, 0, $max );
	}

	/**
	 * Un visiteur qui falsifie son historique ne trompe que sa propre conversation (risque accepté,
	 * voir l'issue 007) ; on borne néanmoins la forme, les rôles, la longueur et le nombre.
	 *
	 * @param mixed $brut
	 * @return array<int, array{role:string, content:string}>|null Null si la forme est invalide.
	 */
	private function validerHistorique( $brut, int $max ): ?array {
		if ( null === $brut || array() === $brut ) {
			return array();
		}
		if ( ! is_array( $brut ) || array_keys( $brut ) !== range( 0, count( $brut ) - 1 ) || count( $brut ) > 100 ) {
			return null;
		}

		$messages = array();
		foreach ( $brut as $element ) {
			if (
				! is_array( $element )
				|| ! isset( $element['role'], $element['content'] )
				|| ! is_string( $element['role'] )
				|| ! in_array( $element['role'], array( 'user', 'assistant' ), true )
				|| ! is_string( $element['content'] )
				|| '' === trim( $element['content'] )
			) {
				return null;
			}
			// Les réponses de l'IA peuvent dépasser la borne : on tronque au lieu de rejeter,
			// sinon le visiteur serait bloqué après une longue réponse.
			$contenu    = sanitize_textarea_field( $element['content'] );
			$messages[] = array(
				'role'    => $element['role'],
				'content' => self::tronquer( $contenu, self::HISTORY_MESSAGE_MAX ),
			);
		}

		// Seuls les derniers messages comptent ; la troncature fine (tokens) est faite par le service.
		return array_slice( $messages, -$max );
	}

	/**
	 * Messages génériques et traduisibles : le détail du fournisseur n'est jamais transmis.
	 *
	 * @return array{status:int, body:array<string, mixed>}
	 */
	private function erreurDepuis( \WP_Error $erreur ): array {
		switch ( $erreur->get_error_code() ) {
			case 'wpia_limit_reached':
				$data = $erreur->get_error_data();

				return $this->erreur(
					429,
					'wpia_limit_reached',
					$erreur->get_error_message(), // Message de 005, déjà destiné au visiteur.
					array( 'portee' => is_array( $data ) && isset( $data['portee'] ) ? (string) $data['portee'] : '' )
				);
			case 'wpia_invalid_request':
				return $this->erreur( 400, 'wpia_invalid_message', __( 'Le message ou l’historique est invalide.', 'plugin-wordpress-ia' ) );
			default:
				return $this->erreur( 503, 'wpia_service_unavailable', __( 'Le service est momentanément indisponible. Réessayez dans quelques instants.', 'plugin-wordpress-ia' ) );
		}
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array{status:int, body:array<string, mixed>}
	 */
	private function erreur( int $status, string $code, string $message, array $extra = array() ): array {
		return array(
			'status' => $status,
			'body'   => array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
		);
	}
}
