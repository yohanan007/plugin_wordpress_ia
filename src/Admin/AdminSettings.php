<?php

namespace WPIA\Admin;

class AdminSettings {
	public const OPTION_NAME = 'wpia_admin_settings';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'max_tokens_request'     => 1024,
			'max_tokens_conversation' => 4096,
			'max_messages_user'      => 20,
			'daily_token_limit'      => 10000,
			'token_alert_threshold'  => 80,
			'log_retention'          => 'weekly',
			'chatbot_enabled'        => 0,
			'chatbot_template'       => '',
			'api_timeout'            => 30,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::defaults(), $saved );
	}

	/**
	 * @param mixed $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::get();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$numeric_ranges = array(
			'max_tokens_request'      => array( 1, 128000 ),
			'max_tokens_conversation' => array( 1, 500000 ),
			'max_messages_user'       => array( 1, 10000 ),
			'daily_token_limit'       => array( 1, 10000000 ),
			'token_alert_threshold'   => array( 1, 100 ),
			'api_timeout'             => array( 1, 120 ),
		);

		foreach ( $numeric_ranges as $key => $range ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = filter_var( $input[ $key ], FILTER_VALIDATE_INT );
			if ( false !== $value ) {
				$current[ $key ] = min( $range[1], max( $range[0], $value ) );
			} else {
				add_settings_error( self::OPTION_NAME, 'wpia_invalid_' . $key, __( 'Une valeur numérique invalide a été ignorée.', 'plugin-wordpress-ia' ), 'error' );
			}
		}

		if ( isset( $input['log_retention'] ) && in_array( $input['log_retention'], array( 'weekly', 'monthly', 'never' ), true ) ) {
			$current['log_retention'] = $input['log_retention'];
		} elseif ( array_key_exists( 'log_retention', $input ) ) {
			add_settings_error( self::OPTION_NAME, 'wpia_invalid_log_retention', __( 'La durée de conservation sélectionnée est invalide.', 'plugin-wordpress-ia' ), 'error' );
		}

		$enabled = isset( $input['chatbot_enabled'] ) && is_string( $input['chatbot_enabled'] )
			? wp_unslash( $input['chatbot_enabled'] )
			: '';
		if ( '' === $enabled || '1' === $enabled ) {
			$current['chatbot_enabled'] = '1' === $enabled ? 1 : 0;
		} else {
			add_settings_error( self::OPTION_NAME, 'wpia_invalid_chatbot_enabled', __( 'Le réglage d’affichage du chatbot est invalide.', 'plugin-wordpress-ia' ), 'error' );
		}

		if ( isset( $input['chatbot_template'] ) && is_string( $input['chatbot_template'] ) ) {
			$template = sanitize_text_field( wp_unslash( $input['chatbot_template'] ) );
			$templates = wp_get_theme()->get_page_templates();
			if ( '' === $template || in_array( $template, array_keys( $templates ), true ) ) {
				$current['chatbot_template'] = $template;
			} else {
				add_settings_error( self::OPTION_NAME, 'wpia_invalid_template', __( 'Le template sélectionné est invalide.', 'plugin-wordpress-ia' ), 'error' );
			}
		} elseif ( array_key_exists( 'chatbot_template', $input ) ) {
			add_settings_error( self::OPTION_NAME, 'wpia_invalid_template', __( 'Le template sélectionné est invalide.', 'plugin-wordpress-ia' ), 'error' );
		}

		return $current;
	}

	public function register(): void {
		register_setting(
			'wpia_admin_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'wpia_usage_settings',
			__( 'Limites et affichage', 'plugin-wordpress-ia' ),
			'__return_false',
			'wpia_alert_settings'
		);

		$fields = array(
			'max_tokens_request'      => __( 'Tokens maximum par requête', 'plugin-wordpress-ia' ),
			'max_tokens_conversation' => __( 'Tokens maximum par conversation', 'plugin-wordpress-ia' ),
			'max_messages_user'       => __( 'Messages maximum par utilisateur et par jour', 'plugin-wordpress-ia' ),
			'daily_token_limit'       => __( 'Limite de tokens journalière', 'plugin-wordpress-ia' ),
			'token_alert_threshold'   => __( 'Seuil d’avertissement (pourcentage)', 'plugin-wordpress-ia' ),
			'api_timeout'             => __( 'Délai maximal du test API (secondes)', 'plugin-wordpress-ia' ),
		);

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'wpia_' . $key,
				$label,
				array( $this, 'renderNumberField' ),
				'wpia_alert_settings',
				'wpia_usage_settings',
				array( 'key' => $key )
			);
		}

		add_settings_field(
			'wpia_log_retention',
			__( 'Conservation des journaux', 'plugin-wordpress-ia' ),
			array( $this, 'renderRetentionField' ),
			'wpia_alert_settings',
			'wpia_usage_settings'
		);

		add_settings_field(
			'wpia_chatbot_enabled',
			__( 'Afficher le chatbot', 'plugin-wordpress-ia' ),
			array( $this, 'renderEnabledField' ),
			'wpia_alert_settings',
			'wpia_usage_settings'
		);

		add_settings_field(
			'wpia_chatbot_template',
			__( 'Template associé', 'plugin-wordpress-ia' ),
			array( $this, 'renderTemplateField' ),
			'wpia_alert_settings',
			'wpia_usage_settings'
		);
	}

	/**
	 * @param array<string, string> $args
	 */
	public function renderNumberField( array $args ): void {
		$settings = self::get();
		$key      = $args['key'];
		printf(
			'<input type="number" min="1" name="%1$s[%2$s]" value="%3$d" class="small-text" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			(int) $settings[ $key ]
		);
	}

	public function renderRetentionField(): void {
		$settings = self::get();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[log_retention]">
			<option value="weekly" <?php selected( $settings['log_retention'], 'weekly' ); ?>><?php esc_html_e( 'Une semaine', 'plugin-wordpress-ia' ); ?></option>
			<option value="monthly" <?php selected( $settings['log_retention'], 'monthly' ); ?>><?php esc_html_e( 'Un mois', 'plugin-wordpress-ia' ); ?></option>
			<option value="never" <?php selected( $settings['log_retention'], 'never' ); ?>><?php esc_html_e( 'Aucune suppression automatique', 'plugin-wordpress-ia' ); ?></option>
		</select>
		<?php
	}

	public function renderEnabledField(): void {
		$settings = self::get();
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[chatbot_enabled]" value="1" <?php checked( 1, (int) $settings['chatbot_enabled'] ); ?> />
			<?php esc_html_e( 'Activer l’affichage (le rendu public sera fourni par une fonctionnalité ultérieure).', 'plugin-wordpress-ia' ); ?>
		</label>
		<?php
	}

	public function renderTemplateField(): void {
		$settings  = self::get();
		$templates = wp_get_theme()->get_page_templates();
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[chatbot_template]">
			<option value=""><?php esc_html_e( 'Aucun template', 'plugin-wordpress-ia' ); ?></option>
			<?php foreach ( $templates as $template => $label ) : ?>
				<option value="<?php echo esc_attr( $template ); ?>" <?php selected( $settings['chatbot_template'], $template ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
