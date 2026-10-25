<?php

namespace WPIA\Admin;

use InvalidArgumentException;
use RuntimeException;
use WPIA\Repository\ChatbotDocumentRepository;
use WPIA\Repository\ChatbotRepository;
use WPIA\Repository\CompteApiRepository;
use WPIA\Repository\ConversationRepository;
use WPIA\Repository\DocumentRepository;
use WPIA\Repository\FournisseurIARepository;
use WPIA\Repository\LogRepository;
use WPIA\Service\AIProviderCatalog;
use WPIA\Service\DocumentStorage;
use WPIA\Service\SecretCipher;

class AdminController {
	private const CONFIG_OPTION = 'wpia_active_configuration';
	private const PAGE_DASHBOARD = 'wpia-dashboard';
	private const PAGE_DOCUMENTS = 'wpia-documents';
	private const PAGE_AI = 'wpia-ai';
	private const PAGE_SECURITY = 'wpia-security';
	private const PAGE_LOGS = 'wpia-logs';
	private const PAGE_ALERTS = 'wpia-alerts';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'registerSettings' ) );
		add_action( 'admin_menu', array( $this, 'registerMenus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'check_admin_referer', array( $this, 'logFailedNonce' ), 10, 2 );
		add_action( 'admin_post_wpia_test_ai', array( $this, 'testAIConnection' ) );
		add_action( 'admin_post_wpia_upload_document', array( $this, 'uploadDocument' ) );
		add_action( 'admin_post_wpia_delete_document', array( $this, 'deleteDocument' ) );
		add_action( 'admin_post_wpia_reindex_document', array( $this, 'reindexDocument' ) );
		add_action( 'admin_post_wpia_move_document', array( $this, 'moveDocument' ) );
		add_action( 'admin_post_wpia_assign_document', array( $this, 'assignDocument' ) );
		add_action( 'admin_post_wpia_delete_log', array( $this, 'deleteLog' ) );
	}

	/**
	 * @param string|int $action Nonce action.
	 * @param bool       $result Nonce validation result.
	 */
	public function logFailedNonce( $action, $result ): void {
		if ( ! $result && is_string( $action ) && str_starts_with( $action, 'wpia_' ) ) {
			$this->log( 'warning', 'security', 'An administration request failed nonce verification.' );
		}
	}

	public function registerSettings(): void {
		( new AdminSettings() )->register();
		register_setting(
			'wpia_ai_settings_group',
			self::CONFIG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitizeAIConfiguration' ),
				'default'           => array(),
			)
		);
	}

	public function registerMenus(): void {
		add_menu_page(
			__( 'IA Chatbot', 'plugin-wordpress-ia' ),
			__( 'IA Chatbot', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_DASHBOARD,
			array( $this, 'renderDashboard' ),
			'dashicons-format-chat'
		);
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Tableau de bord', 'plugin-wordpress-ia' ),
			__( 'Tableau de bord', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_DASHBOARD,
			array( $this, 'renderDashboard' )
		);
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Documents Markdown', 'plugin-wordpress-ia' ),
			__( 'Documents Markdown', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_DOCUMENTS,
			array( $this, 'renderDocuments' )
		);
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Configuration IA', 'plugin-wordpress-ia' ),
			__( 'Configuration IA', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_AI,
			array( $this, 'renderAIConfiguration' )
		);
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Sécurité', 'plugin-wordpress-ia' ),
			__( 'Sécurité', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_SECURITY,
			array( $this, 'renderSecurity' )
		);
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Journalisation', 'plugin-wordpress-ia' ),
			__( 'Journalisation', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_LOGS,
			array( $this, 'renderLogs' )
		);
		add_submenu_page(
			self::PAGE_DASHBOARD,
			__( 'Alerte token', 'plugin-wordpress-ia' ),
			__( 'Alerte token', 'plugin-wordpress-ia' ),
			'manage_options',
			self::PAGE_ALERTS,
			array( $this, 'renderAlerts' )
		);
	}

	/**
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueueAssets( string $hook_suffix ): void {
		$page = sanitize_key( $this->requestString( $_GET, 'page' ) );
		if ( ! in_array(
			$page,
			array(
				self::PAGE_DASHBOARD,
				self::PAGE_DOCUMENTS,
				self::PAGE_AI,
				self::PAGE_SECURITY,
				self::PAGE_LOGS,
				self::PAGE_ALERTS,
			),
			true
		) ) {
			return;
		}

		wp_enqueue_style(
			'wpia-admin',
			plugins_url( 'assets/css/admin.css', dirname( __DIR__, 2 ) . '/plugin-wordpress-ia.php' ),
			array(),
			WPIA_VERSION
		);

		if ( self::PAGE_AI !== $page ) {
			return;
		}

		wp_enqueue_script(
			'wpia-provider-defaults',
			plugins_url( 'assets/js/admin.js', dirname( __DIR__, 2 ) . '/plugin-wordpress-ia.php' ),
			array(),
			WPIA_VERSION,
			true
		);
	}

	public function renderDashboard(): void {
		$this->authorize();
		global $wpdb;
		$documents     = new DocumentRepository( $wpdb );
		$conversations = new ConversationRepository( $wpdb );
		$configuration = $this->getConfigurationRows();
		$settings      = AdminSettings::get();
		$today         = current_time( 'Y-m-d 00:00:00' );
		$token_result  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(tokens), 0) FROM ' . $wpdb->prefix . 'wpia_message WHERE date_creation >= %s',
				$today
			)
		);
		$tokens        = '' === $wpdb->last_error ? (int) $token_result : null;
		$threshold     = (int) ceil( (int) $settings['daily_token_limit'] * ( (int) $settings['token_alert_threshold'] / 100 ) );
		$test_result   = get_option( 'wpia_api_test_result', array() );
		if ( ! is_array( $test_result ) ) {
			$test_result = array();
		}
		?>
		<div class="wrap wpia-admin">
			<h1><?php esc_html_e( 'Tableau de bord IA Chatbot', 'plugin-wordpress-ia' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Les compteurs reflètent uniquement les données enregistrées par les fonctionnalités actuellement disponibles.', 'plugin-wordpress-ia' ); ?></p></div>
			<div class="wpia-stat-grid">
				<div class="wpia-stat-card">
					<span class="wpia-stat-label"><?php esc_html_e( 'Statut du plugin', 'plugin-wordpress-ia' ); ?></span>
					<strong class="wpia-stat-value"><?php esc_html_e( 'Actif', 'plugin-wordpress-ia' ); ?></strong>
				</div>
				<div class="wpia-stat-card">
					<span class="wpia-stat-label"><?php esc_html_e( 'Fournisseur IA', 'plugin-wordpress-ia' ); ?></span>
					<strong class="wpia-stat-value"><?php echo esc_html( $configuration['provider']['designation'] ?? __( 'Non configuré', 'plugin-wordpress-ia' ) ); ?></strong>
				</div>
				<div class="wpia-stat-card">
					<span class="wpia-stat-label"><?php esc_html_e( 'Documents enregistrés', 'plugin-wordpress-ia' ); ?></span>
					<strong class="wpia-stat-value"><?php echo esc_html( (string) $documents->countAll() ); ?></strong>
				</div>
				<div class="wpia-stat-card">
					<span class="wpia-stat-label"><?php esc_html_e( 'Conversations enregistrées', 'plugin-wordpress-ia' ); ?></span>
					<strong class="wpia-stat-value"><?php echo esc_html( (string) $conversations->countAll() ); ?></strong>
				</div>
				<div class="wpia-stat-card">
					<span class="wpia-stat-label"><?php esc_html_e( 'Tokens enregistrés aujourd’hui', 'plugin-wordpress-ia' ); ?></span>
					<strong class="wpia-stat-value"><?php echo null === $tokens ? esc_html__( 'Indisponible', 'plugin-wordpress-ia' ) : esc_html( number_format_i18n( $tokens ) ); ?></strong>
				</div>
			</div>
			<section class="wpia-panel wpia-connection-panel">
				<div>
					<h2><?php esc_html_e( 'Connexion API', 'plugin-wordpress-ia' ); ?></h2>
					<p>
						<?php
						if ( ! empty( $test_result['success'] ) ) {
							esc_html_e( 'Test réussi', 'plugin-wordpress-ia' );
						} elseif ( isset( $test_result['success'] ) ) {
							esc_html_e( 'Dernier test échoué', 'plugin-wordpress-ia' );
						} elseif ( ! empty( $configuration['account']['api_key'] ) ) {
							esc_html_e( 'Configuration renseignée, non testée', 'plugin-wordpress-ia' );
						} else {
							esc_html_e( 'Non configurée', 'plugin-wordpress-ia' );
						}
						if ( ! empty( $test_result['date'] ) ) :
							?>
							<small><?php echo esc_html( (string) $test_result['date'] ); ?></small>
						<?php endif; ?>
					</p>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_AI ) ); ?>"><?php esc_html_e( 'Configurer et tester', 'plugin-wordpress-ia' ); ?></a>
			</section>
			<?php if ( null !== $tokens && $tokens >= $threshold ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					printf(
						/* translators: 1: number of tokens used, 2: daily token limit. */
						esc_html__( 'Avertissement : %1$s tokens enregistrés aujourd’hui, seuil configuré à %2$s.', 'plugin-wordpress-ia' ),
						esc_html( number_format_i18n( $tokens ) ),
						esc_html( number_format_i18n( (int) $settings['daily_token_limit'] ) )
					);
					?>
				</p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Les limites sont configurées pour les fonctionnalités futures ; leur application aux conversations n’est pas incluse dans cette version.', 'plugin-wordpress-ia' ); ?></p>
		</div>
		<?php
	}

	public function renderDocuments(): void {
		$this->authorize();
		global $wpdb;
		$repository = new DocumentRepository( $wpdb );
		$search     = sanitize_text_field( $this->requestString( $_GET, 's' ) );
		$status     = sanitize_key( $this->requestString( $_GET, 'status' ) );
		$page       = max( 1, absint( $this->requestString( $_GET, 'paged' ) ) );
		$per_page   = 20;
		$total      = $repository->countSearch( $search, $status );
		$page       = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
		$rows       = $repository->search( $search, $status, $per_page, ( $page - 1 ) * $per_page );
		$active     = $this->getConfigurationRows();
		$bot_id     = isset( $active['chatbot']['id'] ) ? (int) $active['chatbot']['id'] : 0;
		$ordered    = array();
		if ( $bot_id > 0 ) {
			$ordered = ( new ChatbotDocumentRepository( $wpdb ) )->getOrderedDocuments( $bot_id );
		}
		$order_map = array();
		foreach ( $ordered as $position => $row ) {
			$order_map[ (int) $row['document_id'] ] = $position + 1;
		}
		?>
		<div class="wrap wpia-admin">
			<h1><?php esc_html_e( 'Documents Markdown', 'plugin-wordpress-ia' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<p><?php esc_html_e( 'Les fichiers sont chiffrés sur le disque et ne sont pas servis comme des documents Markdown publics. Leur contenu est lu côté serveur uniquement.', 'plugin-wordpress-ia' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="wpia_upload_document" />
				<?php wp_nonce_field( 'wpia_upload_document' ); ?>
				<label for="wpia-markdown-file"><?php esc_html_e( 'Fichier Markdown (.md, 2 Mo maximum)', 'plugin-wordpress-ia' ); ?></label>
				<input id="wpia-markdown-file" type="file" name="wpia_markdown" accept=".md,text/markdown,text/plain" required />
				<?php submit_button( __( 'Téléverser', 'plugin-wordpress-ia' ), 'primary', 'submit', false ); ?>
			</form>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_DOCUMENTS ); ?>" />
				<label for="wpia-document-search"><?php esc_html_e( 'Rechercher', 'plugin-wordpress-ia' ); ?></label>
				<input id="wpia-document-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<select name="status">
					<option value=""><?php esc_html_e( 'Tous les statuts', 'plugin-wordpress-ia' ); ?></option>
					<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'En attente', 'plugin-wordpress-ia' ); ?></option>
					<option value="ready" <?php selected( $status, 'ready' ); ?>><?php esc_html_e( 'Prêt (texte)', 'plugin-wordpress-ia' ); ?></option>
					<option value="error" <?php selected( $status, 'error' ); ?>><?php esc_html_e( 'Erreur', 'plugin-wordpress-ia' ); ?></option>
				</select>
				<?php submit_button( __( 'Filtrer', 'plugin-wordpress-ia' ), 'secondary', '', false ); ?>
			</form>
			<?php if ( $bot_id > 0 ) : ?>
				<p><?php esc_html_e( 'Priorité du contexte pour le chatbot configuré : le document en première position est traité en premier. Les documents importés sont ajoutés en fin de liste.', 'plugin-wordpress-ia' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Configurez un chatbot avant de définir l’ordre des documents qui lui sont associés.', 'plugin-wordpress-ia' ); ?></p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Nom', 'plugin-wordpress-ia' ); ?></th>
					<th><?php esc_html_e( 'Ajouté le', 'plugin-wordpress-ia' ); ?></th>
					<th><?php esc_html_e( 'Taille', 'plugin-wordpress-ia' ); ?></th>
					<th><?php esc_html_e( 'Statut du traitement texte', 'plugin-wordpress-ia' ); ?></th>
					<th><?php esc_html_e( 'Priorité', 'plugin-wordpress-ia' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'plugin-wordpress-ia' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Aucun document trouvé.', 'plugin-wordpress-ia' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['designation'] ); ?></td>
							<td><?php echo empty( $row['date_ajout'] ) ? esc_html__( 'Inconnue', 'plugin-wordpress-ia' ) : esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $row['date_ajout'] ) ); ?></td>
							<td><?php echo esc_html( size_format( (int) $row['taille_octets'] ) ); ?></td>
							<td><?php echo esc_html( $this->documentStatusLabel( (string) $row['statut_indexation'] ) ); ?></td>
							<td><?php echo isset( $order_map[ (int) $row['id'] ] ) ? esc_html( (string) $order_map[ (int) $row['id'] ] ) : esc_html__( 'Non associé', 'plugin-wordpress-ia' ); ?></td>
							<td>
								<?php if ( $bot_id > 0 && isset( $order_map[ (int) $row['id'] ] ) ) : ?>
									<?php $this->renderMoveForm( (int) $row['id'], 'up' ); ?>
									<?php $this->renderMoveForm( (int) $row['id'], 'down' ); ?>
								<?php elseif ( $bot_id > 0 ) : ?>
									<?php $this->renderAssignForm( (int) $row['id'] ); ?>
								<?php endif; ?>
								<?php $this->renderDocumentAction( 'wpia_reindex_document', (int) $row['id'], __( 'Réindexer le texte', 'plugin-wordpress-ia' ) ); ?>
								<?php $this->renderDocumentAction( 'wpia_delete_document', (int) $row['id'], __( 'Supprimer', 'plugin-wordpress-ia' ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( array( 'page' => self::PAGE_DOCUMENTS, 's' => $search, 'status' => $status, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
						'format'    => '',
						'current'   => $page,
						'total'     => max( 1, (int) ceil( $total / $per_page ) ),
						'prev_text' => __( 'Précédent', 'plugin-wordpress-ia' ),
						'next_text' => __( 'Suivant', 'plugin-wordpress-ia' ),
					)
				)
			);
			?>
			<p><?php esc_html_e( 'La taille en tokens est une estimation (environ 4 caractères par token). Le statut « prêt » indique que le Markdown a été stocké et validé. Le découpage sémantique, la vectorisation et la recherche contextuelle ne sont pas inclus.', 'plugin-wordpress-ia' ); ?></p>
		</div>
		<?php
	}

	public function renderAIConfiguration(): void {
		$this->authorize();
		$configuration = $this->getConfigurationRows();
		$type          = $this->getProviderType();
		$catalog       = AIProviderCatalog::all();
		if ( ! isset( $catalog[ $type ] ) ) {
			$type = 'openai';
		}
		$provider = $configuration['provider'] ?? array();
		$chatbot  = $configuration['chatbot'] ?? array();
		$account  = $configuration['account'] ?? array();
		$url      = isset( $provider['url_api'] ) ? (string) $provider['url_api'] : $catalog[ $type ]['url'];
		$test     = get_option( 'wpia_api_test_result', array() );
		?>
		<div class="wrap wpia-admin">
			<h1><?php esc_html_e( 'Configuration IA', 'plugin-wordpress-ia' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<p><?php esc_html_e( 'Les paramètres initiaux limitent la sortie à 1 024 tokens et utilisent une température basse. Ils ne remplacent jamais une configuration déjà enregistrée.', 'plugin-wordpress-ia' ); ?></p>
			<?php settings_errors( 'wpia_ai_settings' ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'wpia_ai_settings_group' ); ?>
				<table class="form-table"><tbody>
					<tr><th><label for="wpia-provider"><?php esc_html_e( 'Fournisseur', 'plugin-wordpress-ia' ); ?></label></th>
						<td><select id="wpia-provider" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[provider_type]" required>
							<?php foreach ( $catalog as $key => $item ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" data-default-url="<?php echo esc_attr( $item['url'] ); ?>" data-default-model="<?php echo esc_attr( $item['model'] ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $item['label'] ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr><th><label for="wpia-provider-url"><?php esc_html_e( 'URL de base de l’API', 'plugin-wordpress-ia' ); ?></label></th>
						<td><input id="wpia-provider-url" class="regular-text" type="url" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[url_api]" value="<?php echo esc_attr( $url ); ?>" required /></td>
					</tr>
					<tr><th><label for="wpia-model"><?php esc_html_e( 'Modèle', 'plugin-wordpress-ia' ); ?></label></th>
						<td><input id="wpia-model" class="regular-text" type="text" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[model]" value="<?php echo esc_attr( $chatbot['modele'] ?? $catalog[ $type ]['model'] ); ?>" required /></td>
					</tr>
					<tr><th><label for="wpia-system-prompt"><?php esc_html_e( 'Instructions système', 'plugin-wordpress-ia' ); ?></label></th>
						<td><textarea id="wpia-system-prompt" class="large-text" rows="5" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[system_prompt]"><?php echo esc_textarea( $chatbot['system_prompt'] ?? '' ); ?></textarea></td>
					</tr>
					<tr><th><label for="wpia-max-tokens"><?php esc_html_e( 'Tokens maximum par réponse', 'plugin-wordpress-ia' ); ?></label></th>
						<td><input id="wpia-max-tokens" type="number" min="1" max="128000" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[max_tokens]" value="<?php echo esc_attr( (string) ( $chatbot['max_tokens'] ?? 1024 ) ); ?>" /></td>
					</tr>
					<tr><th><label for="wpia-temperature"><?php esc_html_e( 'Température', 'plugin-wordpress-ia' ); ?></label></th>
						<td><input id="wpia-temperature" type="number" min="0" max="2" step="0.01" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[temperature]" value="<?php echo esc_attr( (string) ( $chatbot['temperature'] ?? '0.3' ) ); ?>" /></td>
					</tr>
					<tr><th><label for="wpia-api-key"><?php esc_html_e( 'Clé API', 'plugin-wordpress-ia' ); ?></label></th>
						<td>
							<input id="wpia-api-key" class="regular-text" type="password" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[api_key]" value="" autocomplete="new-password" />
							<p class="description"><?php echo ! empty( $account['api_key'] ) ? esc_html__( 'Une clé est enregistrée et chiffrée. Laissez ce champ vide pour la conserver.', 'plugin-wordpress-ia' ) : esc_html__( 'Aucune clé enregistrée.', 'plugin-wordpress-ia' ); ?></p>
							<label><input type="checkbox" name="<?php echo esc_attr( self::CONFIG_OPTION ); ?>[clear_api_key]" value="1" /> <?php esc_html_e( 'Supprimer la clé enregistrée', 'plugin-wordpress-ia' ); ?></label>
						</td>
					</tr>
				</tbody></table>
				<button type="button" class="button" id="wpia-load-provider-defaults"><?php esc_html_e( 'Charger explicitement les valeurs par défaut du fournisseur', 'plugin-wordpress-ia' ); ?></button>
				<?php submit_button( __( 'Enregistrer la configuration', 'plugin-wordpress-ia' ) ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpia_test_ai" />
				<?php wp_nonce_field( 'wpia_test_ai' ); ?>
				<?php submit_button( __( 'Tester la connexion (requête de modèles uniquement)', 'plugin-wordpress-ia' ), 'secondary' ); ?>
			</form>
			<?php if ( is_array( $test ) && isset( $test['message'] ) ) : ?>
				<p><?php echo esc_html( (string) $test['message'] ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'Le test contacte le point de terminaison de liste des modèles du fournisseur ; il ne génère pas de réponse IA.', 'plugin-wordpress-ia' ); ?></p>
		</div>
		<?php
	}

	public function renderSecurity(): void {
		$this->authorize();
		$cipher        = new SecretCipher();
		$configuration = $this->getConfigurationRows();
		$stored_key    = isset( $configuration['account']['api_key'] ) && '' !== $configuration['account']['api_key'];
		$encrypted     = $stored_key && $cipher->isEncrypted( (string) $configuration['account']['api_key'] );
		?>
		<div class="wrap wpia-admin">
			<h1><?php esc_html_e( 'Sécurité', 'plugin-wordpress-ia' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<table class="widefat striped"><tbody>
				<tr><th><?php esc_html_e( 'Chiffrement des clés API', 'plugin-wordpress-ia' ); ?></th><td><?php echo $cipher->isAvailable() ? esc_html__( 'Disponible (AES-256-GCM)', 'plugin-wordpress-ia' ) : esc_html__( 'Indisponible : OpenSSL ou clé WordPress absente', 'plugin-wordpress-ia' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Clé enregistrée', 'plugin-wordpress-ia' ); ?></th><td><?php echo ! $stored_key ? esc_html__( 'Aucune', 'plugin-wordpress-ia' ) : ( $encrypted ? esc_html__( 'Chiffrée', 'plugin-wordpress-ia' ) : esc_html__( 'Ancienne valeur non chiffrée — enregistrez la configuration pour la migrer', 'plugin-wordpress-ia' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Documents', 'plugin-wordpress-ia' ); ?></th><td><?php esc_html_e( 'Chiffrés sur disque et servis uniquement après lecture côté serveur.', 'plugin-wordpress-ia' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Accès aux réglages', 'plugin-wordpress-ia' ); ?></th><td><?php esc_html_e( 'Restreint aux utilisateurs disposant de la capacité manage_options.', 'plugin-wordpress-ia' ); ?></td></tr>
			</tbody></table>
			<p><?php esc_html_e( 'La clé de chiffrement est dérivée des salts AUTH_KEY et SECURE_AUTH_KEY. Leur rotation rend les secrets et les documents déjà chiffrés illisibles ; sauvegardez vos clés WordPress et prévoyez une migration avant toute rotation.', 'plugin-wordpress-ia' ); ?></p>
			<p><?php esc_html_e( 'Le chiffrement ne peut pas être désactivé depuis cette interface afin d’éviter le stockage accidentel de clés en clair.', 'plugin-wordpress-ia' ); ?></p>
		</div>
		<?php
	}

	public function renderLogs(): void {
		$this->authorize();
		global $wpdb;
		$repository = new LogRepository( $wpdb );
		$category   = sanitize_key( $this->requestString( $_GET, 'category' ) );
		if ( ! in_array( $category, array( '', 'api', 'security', 'document', 'application' ), true ) ) {
			$category = '';
		}
		$page     = max( 1, absint( $this->requestString( $_GET, 'paged' ) ) );
		$per_page = 50;
		$total    = $repository->count( $category );
		$page     = min( $page, max( 1, (int) ceil( $total / $per_page ) ) );
		$rows     = $repository->findAll( $per_page, ( $page - 1 ) * $per_page, $category );
		?>
		<div class="wrap wpia-admin">
			<h1><?php esc_html_e( 'Journalisation', 'plugin-wordpress-ia' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<p><?php esc_html_e( 'Les journaux ne contiennent pas les clés API. La conservation configurée s’applique à la purge automatique.', 'plugin-wordpress-ia' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_LOGS ); ?>" />
				<label for="wpia-log-category"><?php esc_html_e( 'Catégorie', 'plugin-wordpress-ia' ); ?></label>
				<select id="wpia-log-category" name="category">
					<option value=""><?php esc_html_e( 'Toutes', 'plugin-wordpress-ia' ); ?></option>
					<option value="api" <?php selected( $category, 'api' ); ?>>API</option>
					<option value="security" <?php selected( $category, 'security' ); ?>><?php esc_html_e( 'Sécurité', 'plugin-wordpress-ia' ); ?></option>
					<option value="document" <?php selected( $category, 'document' ); ?>><?php esc_html_e( 'Documents', 'plugin-wordpress-ia' ); ?></option>
					<option value="application" <?php selected( $category, 'application' ); ?>><?php esc_html_e( 'Application', 'plugin-wordpress-ia' ); ?></option>
				</select>
				<?php submit_button( __( 'Filtrer', 'plugin-wordpress-ia' ), 'secondary', '', false ); ?>
			</form>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Date UTC', 'plugin-wordpress-ia' ); ?></th><th><?php esc_html_e( 'Niveau', 'plugin-wordpress-ia' ); ?></th><th><?php esc_html_e( 'Catégorie', 'plugin-wordpress-ia' ); ?></th><th><?php esc_html_e( 'Message', 'plugin-wordpress-ia' ); ?></th><th><?php esc_html_e( 'Action', 'plugin-wordpress-ia' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'Aucun journal.', 'plugin-wordpress-ia' ); ?></td></tr><?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['date_creation'] ); ?></td>
							<td><?php echo esc_html( (string) $row['niveau'] ); ?></td>
							<td><?php echo esc_html( (string) $row['categorie'] ); ?></td>
							<td><?php echo esc_html( (string) $row['message'] ); ?></td>
							<td><?php $this->renderLogDeleteForm( (int) $row['id'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( array( 'page' => self::PAGE_LOGS, 'category' => $category, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
						'format'  => '',
						'current' => $page,
						'total'   => max( 1, (int) ceil( $total / $per_page ) ),
					)
				)
			);
			?>
		</div>
		<?php
	}

	public function renderAlerts(): void {
		$this->authorize();
		?>
		<div class="wrap wpia-admin">
			<h1><?php esc_html_e( 'Limites et alerte token', 'plugin-wordpress-ia' ); ?></h1>
			<?php $this->renderNotice(); ?>
			<?php settings_errors( AdminSettings::OPTION_NAME ); ?>
			<p><?php esc_html_e( 'Les valeurs par défaut sont prudentes et les valeurs déjà enregistrées sont conservées. L’avertissement du tableau de bord compare les tokens enregistrés aujourd’hui à la limite journalière.', 'plugin-wordpress-ia' ); ?></p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wpia_admin_settings_group' );
				do_settings_sections( 'wpia_alert_settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param mixed $input
	 * @return array<string, int|string>
	 */
	public function sanitizeAIConfiguration( $input ): array {
		$current = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $input ) ) {
			add_settings_error( 'wpia_ai_settings', 'wpia_invalid_submission', __( 'La configuration reçue est invalide.', 'plugin-wordpress-ia' ), 'error' );
			return $this->configurationOption( $current );
		}

		$catalog = AIProviderCatalog::all();
		$type    = sanitize_key( $this->arrayString( $input, 'provider_type' ) );
		if ( '' === $type ) {
			$type = $this->getProviderType();
		}
		if ( ! isset( $catalog[ $type ] ) ) {
			add_settings_error( 'wpia_ai_settings', 'wpia_invalid_provider', __( 'Le fournisseur sélectionné est invalide.', 'plugin-wordpress-ia' ), 'error' );
			return $this->configurationOption( $current );
		}

		$current_rows = $this->getConfigurationRows();
		$same_provider = $type === $this->getProviderType();
		$url_input    = $this->arrayString( $input, 'url_api' );
		$url          = '' !== trim( $url_input )
			? esc_url_raw( $url_input )
			: ( $same_provider && ! empty( $current_rows['provider']['url_api'] ) ? (string) $current_rows['provider']['url_api'] : $catalog[ $type ]['url'] );
		$model        = sanitize_text_field( $this->arrayString( $input, 'model' ) );
		if ( '' === $model ) {
			$model = $same_provider && ! empty( $current_rows['chatbot']['modele'] )
				? (string) $current_rows['chatbot']['modele']
				: $catalog[ $type ]['model'];
		}
		$prompt     = array_key_exists( 'system_prompt', $input )
			? sanitize_textarea_field( $this->arrayString( $input, 'system_prompt' ) )
			: ( $same_provider && ! empty( $current_rows['chatbot']['system_prompt'] ) ? (string) $current_rows['chatbot']['system_prompt'] : '' );
		$max_input  = $this->arrayString( $input, 'max_tokens' );
		$temp_input = $this->arrayString( $input, 'temperature' );
		$max        = '' !== $max_input ? filter_var( $max_input, FILTER_VALIDATE_INT ) : ( $same_provider && ! empty( $current_rows['chatbot']['max_tokens'] ) ? (int) $current_rows['chatbot']['max_tokens'] : 1024 );
		$temp       = '' !== $temp_input ? filter_var( $temp_input, FILTER_VALIDATE_FLOAT ) : ( $same_provider && isset( $current_rows['chatbot']['temperature'] ) ? (float) $current_rows['chatbot']['temperature'] : 0.3 );
		$new_key    = trim( $this->arrayString( $input, 'api_key' ) );
		$clear_key  = '1' === $this->arrayString( $input, 'clear_api_key' );

		if ( strlen( $url ) > 2048 || strlen( $model ) > 191 || strlen( $prompt ) > 50000 || strlen( $new_key ) > 4096 || ! $this->validProviderUrl( $type, $url ) || '' === $model || false === $max || $max < 1 || $max > 128000 || false === $temp || $temp < 0 || $temp > 2 ) {
			add_settings_error( 'wpia_ai_settings', 'wpia_invalid_ai_settings', __( 'Vérifiez l’URL, le modèle, les limites et la température. Le modèle et les instructions doivent respecter les longueurs maximales.', 'plugin-wordpress-ia' ), 'error' );
			return $this->configurationOption( $current );
		}

		global $wpdb;
		$providers   = new FournisseurIARepository( $wpdb );
		$accounts    = new CompteApiRepository( $wpdb );
		$chatbots    = new ChatbotRepository( $wpdb );
		$ids         = $this->getConfigurationIds();
		$old_account = ! empty( $ids['account_id'] ) ? $accounts->getById( (int) $ids['account_id'] ) : null;
		$cipher      = new SecretCipher();

		if ( ( '' !== $new_key || ( ! $clear_key && is_array( $old_account ) && ! empty( $old_account['api_key'] ) && ! $cipher->isEncrypted( (string) $old_account['api_key'] ) ) ) && ! $cipher->isAvailable() ) {
			add_settings_error( 'wpia_ai_settings', 'wpia_encryption_unavailable', __( 'Le chiffrement n’est pas disponible ; la clé API n’a pas été enregistrée.', 'plugin-wordpress-ia' ), 'error' );
			return $this->configurationOption( $current );
		}

		try {
			$stored_key = '';
			if ( ! $clear_key ) {
				if ( '' !== $new_key ) {
					$stored_key = $cipher->encrypt( $new_key );
				} elseif ( $type === $this->getProviderType() && is_array( $old_account ) && ! empty( $old_account['api_key'] ) ) {
					$existing  = (string) $old_account['api_key'];
					$stored_key = $cipher->isEncrypted( $existing ) ? $existing : $cipher->encrypt( $cipher->decrypt( $existing ) );
				}
			}

			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'Unable to start the AI configuration transaction.' );
			}

			try {
				$provider_data = array(
					'designation' => $catalog[ $type ]['label'],
					'url_api'     => $url,
					'actif'       => 1,
				);
				$provider_id = $this->saveEntity( $providers, (int) ( $ids['provider_id'] ?? 0 ), $provider_data );

				$account_data = array(
					'fournisseur_id' => $provider_id,
					'api_key'        => $stored_key,
					'commentaire'    => '',
					'actif'          => 1,
				);
				$account_id = $this->saveEntity( $accounts, (int) ( $ids['account_id'] ?? 0 ), $account_data );

				$chatbot_data = array(
					'designation'   => __( 'Chatbot principal', 'plugin-wordpress-ia' ),
					'description'   => '',
					'compte_api_id' => $account_id,
					'modele'        => $model,
					'system_prompt' => $prompt,
					'max_tokens'    => (int) $max,
					'temperature'   => (float) $temp,
					'actif'         => 1,
				);
				$chatbot_id = $this->saveEntity( $chatbots, (int) ( $ids['chatbot_id'] ?? 0 ), $chatbot_data );

				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'Unable to commit the AI configuration.' );
				}
			} catch ( RuntimeException $exception ) {
				$wpdb->query( 'ROLLBACK' );
				throw $exception;
			}
		} catch ( RuntimeException $exception ) {
			$this->log( 'error', 'application', $exception->getMessage() );
			add_settings_error( 'wpia_ai_settings', 'wpia_save_failed', __( 'La configuration n’a pas pu être enregistrée. Consultez les journaux.', 'plugin-wordpress-ia' ), 'error' );
			return $this->configurationOption( $current );
		}

		delete_option( 'wpia_api_test_result' );
		add_settings_error( 'wpia_ai_settings', 'wpia_settings_saved', __( 'Configuration enregistrée.', 'plugin-wordpress-ia' ), 'success' );

		return array(
			'provider_type' => $type,
			'provider_id'   => $provider_id,
			'account_id'    => $account_id,
			'chatbot_id'    => $chatbot_id,
		);
	}

	public function testAIConnection(): void {
		$this->authorize();
		check_admin_referer( 'wpia_test_ai' );
		$rows = $this->getConfigurationRows();

		if ( empty( $rows['provider']['url_api'] ) || empty( $rows['chatbot']['modele'] ) || ! is_array( $rows['account'] ) || ( 'ollama' !== $this->getProviderType() && empty( $rows['account']['api_key'] ) ) ) {
			$this->saveTestResult( false, __( 'Configuration incomplète : fournisseur, modèle et clé API sont requis.', 'plugin-wordpress-ia' ) );
			$this->redirect( self::PAGE_AI, 'test_failed' );
		}

		$type = $this->getProviderType();
		try {
			$key      = ( new SecretCipher() )->decrypt( (string) $rows['account']['api_key'] );
			$settings = AdminSettings::get();
			$result   = $this->performConnectionTest( $type, (string) $rows['provider']['url_api'], $key, (int) $settings['api_timeout'] );
			if ( is_wp_error( $result ) ) {
				$message = __( 'Le fournisseur n’a pas répondu au test. Vérifiez l’URL, la clé et les journaux.', 'plugin-wordpress-ia' );
				$this->saveTestResult( false, $message );
				$this->log( 'error', 'api', $message . ' Error code: ' . sanitize_key( $result->get_error_code() ) );
				$this->redirect( self::PAGE_AI, 'test_failed' );
			}

			$status = (int) wp_remote_retrieve_response_code( $result );
			if ( $status < 200 || $status >= 300 ) {
				$message = sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Le test de connexion a échoué (HTTP %d).', 'plugin-wordpress-ia' ),
					$status
				);
				$this->saveTestResult( false, $message );
				$this->log( 'error', 'api', $message );
				$this->redirect( self::PAGE_AI, 'test_failed' );
			}

			$this->saveTestResult( true, __( 'Connexion testée avec succès.', 'plugin-wordpress-ia' ) );
			$this->log( 'info', 'api', __( 'Connection test succeeded.', 'plugin-wordpress-ia' ) );
			$this->redirect( self::PAGE_AI, 'test_succeeded' );
		} catch ( RuntimeException $exception ) {
			$this->saveTestResult( false, __( 'Impossible de déchiffrer la clé ou d’exécuter le test. Vérifiez les salts WordPress.', 'plugin-wordpress-ia' ) );
			$this->log( 'error', 'security', $exception->getMessage() );
			$this->redirect( self::PAGE_AI, 'test_failed' );
		}
	}

	public function uploadDocument(): void {
		$this->authorize();
		check_admin_referer( 'wpia_upload_document' );

		try {
			$file = isset( $_FILES['wpia_markdown'] ) && is_array( $_FILES['wpia_markdown'] ) ? $_FILES['wpia_markdown'] : array();
			if ( ! isset( $file['name'], $file['tmp_name'], $file['size'], $file['error'] ) || ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_numeric( $file['size'] ) || ! is_numeric( $file['error'] ) ) {
				throw new RuntimeException( 'The uploaded document is invalid.' );
			}
			$store = new DocumentStorage( new SecretCipher() );
			$data  = $store->storeUpload( $file );
			global $wpdb;
			$repository = new DocumentRepository( $wpdb );
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				$store->delete( $data['path'] );
				throw new RuntimeException( 'Unable to start the document registration transaction.' );
			}
			try {
				$designation = sanitize_text_field( pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_FILENAME ) );
				if ( '' === $designation ) {
					$designation = __( 'Document Markdown', 'plugin-wordpress-ia' );
				}
				$id = $repository->create(
					array(
						'designation'       => $designation,
						'description'       => '',
						'fichier'           => $data['path'],
						'hash'              => $data['hash'],
						'taille_tokens'     => (int) ceil( strlen( $data['content'] ) / 4 ),
						'taille_octets'     => $data['size'],
						'date_ajout'        => current_time( 'mysql', true ),
						'statut_indexation' => 'ready',
						'actif'             => 1,
					)
				);

				$ids = $this->getConfigurationIds();
				if ( ! empty( $ids['chatbot_id'] ) ) {
					( new ChatbotDocumentRepository( $wpdb ) )->append( (int) $ids['chatbot_id'], $id );
				}

				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'Unable to commit the document registration.' );
				}
			} catch ( RuntimeException $exception ) {
				$wpdb->query( 'ROLLBACK' );
				$store->delete( $data['path'] );
				throw $exception;
			}

			$this->log( 'info', 'document', 'Markdown document uploaded and stored encrypted.' );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_uploaded' );
		} catch ( RuntimeException $exception ) {
			$this->log( 'error', 'document', $exception->getMessage() );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_failed' );
		}
	}

	public function deleteDocument(): void {
		$this->authorize();
		$id = absint( $this->requestString( $_POST, 'document_id' ) );
		check_admin_referer( 'wpia_delete_document_' . $id );
		if ( $id < 1 ) {
			$this->redirect( self::PAGE_DOCUMENTS, 'document_not_found' );
		}
		global $wpdb;

		try {
			$repository = new DocumentRepository( $wpdb );
			$row        = $repository->getById( $id );
			if ( ! is_array( $row ) ) {
				$this->redirect( self::PAGE_DOCUMENTS, 'document_not_found' );
			}
			( new DocumentStorage( new SecretCipher() ) )->delete( (string) $row['fichier'] );
			if ( false === $wpdb->delete( $wpdb->prefix . 'wpia_chatbot_document', array( 'document_id' => $id ), array( '%d' ) ) ) {
				throw new RuntimeException( 'Unable to remove document priority links.' );
			}
			$repository->delete( $id );
			$this->log( 'info', 'document', 'Markdown document deleted.' );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_deleted' );
		} catch ( RuntimeException $exception ) {
			$this->log( 'error', 'document', $exception->getMessage() );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_failed' );
		}
	}

	public function reindexDocument(): void {
		$this->authorize();
		$id = absint( $this->requestString( $_POST, 'document_id' ) );
		check_admin_referer( 'wpia_reindex_document_' . $id );
		if ( $id < 1 ) {
			$this->redirect( self::PAGE_DOCUMENTS, 'document_not_found' );
		}
		global $wpdb;

		try {
			$repository = new DocumentRepository( $wpdb );
			$row        = $repository->getById( $id );
			if ( ! is_array( $row ) ) {
				$this->redirect( self::PAGE_DOCUMENTS, 'document_not_found' );
			}
			$content = ( new DocumentStorage( new SecretCipher() ) )->read( (string) $row['fichier'] );
			$repository->update(
				$id,
				array(
					'hash'              => hash( 'sha256', $content ),
					'taille_tokens'     => (int) ceil( strlen( $content ) / 4 ),
					'taille_octets'     => strlen( $content ),
					'statut_indexation' => 'ready',
				)
			);
			$this->log( 'info', 'document', 'Markdown text validation completed.' );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_reindexed' );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			$this->log( 'error', 'document', $exception->getMessage() );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_failed' );
		}
	}

	public function moveDocument(): void {
		$this->authorize();
		$id        = absint( $this->requestString( $_POST, 'document_id' ) );
		$direction = sanitize_key( $this->requestString( $_POST, 'direction' ) );
		check_admin_referer( 'wpia_move_document_' . $id );
		if ( ! in_array( $direction, array( 'up', 'down' ), true ) || $id < 1 ) {
			$this->redirect( self::PAGE_DOCUMENTS, 'document_failed' );
		}

		$ids = $this->getConfigurationIds();
		if ( empty( $ids['chatbot_id'] ) ) {
			$this->redirect( self::PAGE_DOCUMENTS, 'no_chatbot' );
		}

		try {
			global $wpdb;
			( new ChatbotDocumentRepository( $wpdb ) )->move( (int) $ids['chatbot_id'], $id, $direction );
			$this->redirect( self::PAGE_DOCUMENTS, 'priority_saved' );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			$this->log( 'error', 'document', $exception->getMessage() );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_failed' );
		}
	}

	public function assignDocument(): void {
		$this->authorize();
		$id = absint( $this->requestString( $_POST, 'document_id' ) );
		check_admin_referer( 'wpia_assign_document_' . $id );
		$ids = $this->getConfigurationIds();
		if ( $id < 1 || empty( $ids['chatbot_id'] ) ) {
			$this->redirect( self::PAGE_DOCUMENTS, 'no_chatbot' );
		}

		try {
			global $wpdb;
			$document = ( new DocumentRepository( $wpdb ) )->getById( $id );
			if ( ! is_array( $document ) || 1 !== (int) $document['actif'] ) {
				$this->redirect( self::PAGE_DOCUMENTS, 'document_not_found' );
			}

			( new ChatbotDocumentRepository( $wpdb ) )->append( (int) $ids['chatbot_id'], $id );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_assigned' );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			$this->log( 'error', 'document', $exception->getMessage() );
			$this->redirect( self::PAGE_DOCUMENTS, 'document_failed' );
		}
	}

	public function deleteLog(): void {
		$this->authorize();
		$id = absint( $this->requestString( $_POST, 'log_id' ) );
		check_admin_referer( 'wpia_delete_log_' . $id );
		if ( $id < 1 ) {
			$this->redirect( self::PAGE_LOGS, 'log_not_found' );
		}

		global $wpdb;
		try {
			if ( 0 === ( new LogRepository( $wpdb ) )->delete( $id ) ) {
				$this->redirect( self::PAGE_LOGS, 'log_not_found' );
			}
		} catch ( RuntimeException $exception ) {
			$this->log( 'error', 'application', $exception->getMessage() );
			$this->redirect( self::PAGE_LOGS, 'log_delete_failed' );
		}
		$this->redirect( self::PAGE_LOGS, 'log_deleted' );
	}

	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->log( 'warning', 'security', 'An administration request was denied because the user lacks the required capability.' );
			wp_die( esc_html__( 'Vous n’avez pas les droits nécessaires pour accéder à cette page.', 'plugin-wordpress-ia' ) );
		}
	}

	/**
	 * @return array<string, int>
	 */
	private function getConfigurationIds(): array {
		$ids = get_option( self::CONFIG_OPTION, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array(
			'provider_id' => isset( $ids['provider_id'] ) ? absint( $ids['provider_id'] ) : 0,
			'account_id'  => isset( $ids['account_id'] ) ? absint( $ids['account_id'] ) : 0,
			'chatbot_id'  => isset( $ids['chatbot_id'] ) ? absint( $ids['chatbot_id'] ) : 0,
		);
	}

	private function getProviderType(): string {
		$configuration = get_option( self::CONFIG_OPTION, array() );
		$type          = is_array( $configuration ) && isset( $configuration['provider_type'] ) && is_string( $configuration['provider_type'] )
			? sanitize_key( $configuration['provider_type'] )
			: sanitize_key( (string) get_option( 'wpia_provider_type', 'openai' ) );

		return isset( AIProviderCatalog::all()[ $type ] ) ? $type : 'openai';
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private function arrayString( array $input, string $key ): string {
		if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $input[ $key ] );
	}

	/**
	 * @param array<string, mixed> $current
	 * @return array<string, int|string>
	 */
	private function configurationOption( array $current ): array {
		return array(
			'provider_type' => $this->getProviderType(),
			'provider_id'   => isset( $current['provider_id'] ) ? absint( $current['provider_id'] ) : 0,
			'account_id'    => isset( $current['account_id'] ) ? absint( $current['account_id'] ) : 0,
			'chatbot_id'    => isset( $current['chatbot_id'] ) ? absint( $current['chatbot_id'] ) : 0,
		);
	}

	/**
	 * @return array<string, array<string, mixed>|null>
	 */
	private function getConfigurationRows(): array {
		global $wpdb;
		$ids       = $this->getConfigurationIds();
		$providers = new FournisseurIARepository( $wpdb );
		$accounts  = new CompteApiRepository( $wpdb );
		$chatbots  = new ChatbotRepository( $wpdb );

		return array(
			'provider' => ! empty( $ids['provider_id'] ) ? $providers->getById( $ids['provider_id'] ) : null,
			'account'  => ! empty( $ids['account_id'] ) ? $accounts->getById( $ids['account_id'] ) : null,
			'chatbot'  => ! empty( $ids['chatbot_id'] ) ? $chatbots->getById( $ids['chatbot_id'] ) : null,
		);
	}

	/**
	 * @param array<string, scalar> $data
	 */
	private function saveEntity( object $repository, int $id, array $data ): int {
		if ( $id > 0 && null !== $repository->getById( $id ) ) {
			$repository->update( $id, $data );
			return $id;
		}

		return $repository->create( $data );
	}

	private function validProviderUrl( string $type, string $url ): bool {
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

	/**
	 * @return array|\WP_Error
	 */
	private function performConnectionTest( string $type, string $base_url, string $api_key, int $timeout ) {
		$endpoint = 'ollama' === $type ? '/api/tags' : '/models';
		$url      = untrailingslashit( $base_url ) . $endpoint;
		$headers  = array( 'Accept' => 'application/json' );

		if ( 'anthropic' === $type ) {
			$headers['x-api-key']         = $api_key;
			$headers['anthropic-version'] = '2023-06-01';
		} elseif ( 'ollama' !== $type ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$args = array(
			'timeout'             => max( 1, min( 120, $timeout ) ),
			'redirection'         => 0,
			'headers'             => $headers,
			'limit_response_size' => 65536,
		);

		return 'ollama' === $type
			? wp_remote_get( $url, $args )
			: wp_safe_remote_get( $url, $args );
	}

	private function saveTestResult( bool $success, string $message ): void {
		update_option(
			'wpia_api_test_result',
			array(
				'success' => $success,
				'message' => sanitize_text_field( $message ),
				'date'    => current_time( 'mysql' ),
			),
			false
		);
	}

	private function log( string $level, string $category, string $message ): void {
		global $wpdb;
		( new LogRepository( $wpdb ) )->create( $level, $category, $message );
	}

	private function redirect( string $page, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'wpia_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function renderNotice(): void {
		$notice = sanitize_key( $this->requestString( $_GET, 'wpia_notice' ) );
		$messages = array(
			'saved'                    => array( 'success', __( 'Configuration enregistrée.', 'plugin-wordpress-ia' ) ),
			'test_succeeded'           => array( 'success', __( 'Connexion testée avec succès.', 'plugin-wordpress-ia' ) ),
			'test_failed'              => array( 'error', __( 'Le test de connexion a échoué. Consultez le message de statut et les journaux.', 'plugin-wordpress-ia' ) ),
			'invalid_provider'         => array( 'error', __( 'Le fournisseur sélectionné est invalide.', 'plugin-wordpress-ia' ) ),
			'invalid_ai_settings'      => array( 'error', __( 'Vérifiez l’URL HTTPS, le modèle, le nombre de tokens et la température.', 'plugin-wordpress-ia' ) ),
			'encryption_unavailable'   => array( 'error', __( 'La configuration de chiffrement n’est pas disponible ; la clé n’a pas été enregistrée.', 'plugin-wordpress-ia' ) ),
			'save_failed'              => array( 'error', __( 'La configuration n’a pas pu être enregistrée. Consultez les journaux.', 'plugin-wordpress-ia' ) ),
			'document_uploaded'        => array( 'success', __( 'Document téléversé et protégé.', 'plugin-wordpress-ia' ) ),
			'document_deleted'         => array( 'success', __( 'Document supprimé.', 'plugin-wordpress-ia' ) ),
			'document_reindexed'       => array( 'success', __( 'Le contenu Markdown a été relu et validé.', 'plugin-wordpress-ia' ) ),
			'document_failed'          => array( 'error', __( 'L’opération documentaire a échoué. Consultez les journaux.', 'plugin-wordpress-ia' ) ),
			'document_not_found'       => array( 'error', __( 'Document introuvable.', 'plugin-wordpress-ia' ) ),
			'priority_saved'           => array( 'success', __( 'Priorité documentaire mise à jour.', 'plugin-wordpress-ia' ) ),
			'document_assigned'        => array( 'success', __( 'Document associé au chatbot.', 'plugin-wordpress-ia' ) ),
			'no_chatbot'               => array( 'error', __( 'Configurez un chatbot avant de modifier les priorités.', 'plugin-wordpress-ia' ) ),
			'log_deleted'              => array( 'success', __( 'Journal supprimé.', 'plugin-wordpress-ia' ) ),
			'log_not_found'            => array( 'error', __( 'Journal introuvable.', 'plugin-wordpress-ia' ) ),
			'log_delete_failed'        => array( 'error', __( 'Le journal n’a pas pu être supprimé.', 'plugin-wordpress-ia' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $notice ][0] ),
				esc_html( $messages[ $notice ][1] )
			);
		}
	}

	private function renderDocumentAction( string $action, int $id, string $label ): void {
		?>
		<form class="wpia-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( $action . '_' . $id ); ?>
			<button class="button button-small" type="submit">
				<?php echo esc_html( $label ); ?>
			</button>
		</form>
		<?php
	}

	private function renderMoveForm( int $id, string $direction ): void {
		$label = 'up' === $direction ? __( 'Monter', 'plugin-wordpress-ia' ) : __( 'Descendre', 'plugin-wordpress-ia' );
		?>
		<form class="wpia-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpia_move_document" />
			<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="direction" value="<?php echo esc_attr( $direction ); ?>" />
			<?php wp_nonce_field( 'wpia_move_document_' . $id ); ?>
			<button class="button button-small" type="submit"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function renderAssignForm( int $id ): void {
		?>
		<form class="wpia-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpia_assign_document" />
			<input type="hidden" name="document_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'wpia_assign_document_' . $id ); ?>
			<button class="button button-small" type="submit"><?php esc_html_e( 'Associer au chatbot', 'plugin-wordpress-ia' ); ?></button>
		</form>
		<?php
	}

	private function renderLogDeleteForm( int $id ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wpia_delete_log" />
			<input type="hidden" name="log_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( 'wpia_delete_log_' . $id ); ?>
			<button class="button button-small" type="submit"><?php esc_html_e( 'Supprimer', 'plugin-wordpress-ia' ); ?></button>
		</form>
		<?php
	}

	private function documentStatusLabel( string $status ): string {
		$labels = array(
			'pending' => __( 'En attente', 'plugin-wordpress-ia' ),
			'ready'   => __( 'Prêt (texte validé)', 'plugin-wordpress-ia' ),
			'error'   => __( 'Erreur', 'plugin-wordpress-ia' ),
		);

		return $labels[ $status ] ?? __( 'Inconnu', 'plugin-wordpress-ia' );
	}

	/**
	 * @param array<string, mixed> $source
	 */
	private function requestString( array $source, string $key ): string {
		if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) {
			return '';
		}

		return wp_unslash( $source[ $key ] );
	}
}
