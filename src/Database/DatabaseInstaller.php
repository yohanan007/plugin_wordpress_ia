<?php

namespace WPIA\Database;

use RuntimeException;

class DatabaseInstaller {
	public function install(): void {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			throw new RuntimeException( 'The WordPress database is not available.' );
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = $this->getTableDefinitions( $wpdb->prefix, $charset_collate );

		foreach ( $tables as $table_sql ) {
			dbDelta( $table_sql );
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Unable to install plugin tables: ' . $wpdb->last_error );
			}
		}

		update_option( 'wpia_db_version', WPIA_DB_VERSION );
	}

	/**
	 * @return array<string, string>
	 */
	private function getTableDefinitions( string $prefix, string $charset_collate ): array {
		return array(
			'fournisseur_ia'      => "CREATE TABLE {$prefix}wpia_fournisseur_ia (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				designation varchar(191) NOT NULL,
				url_api text NOT NULL,
				actif tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id)
			) {$charset_collate};",
			'compte_api'          => "CREATE TABLE {$prefix}wpia_compte_api (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fournisseur_id bigint(20) unsigned NOT NULL,
				api_key longtext NOT NULL,
				commentaire text NOT NULL,
				actif tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY fournisseur_id (fournisseur_id)
			) {$charset_collate};",
			'chatbot'             => "CREATE TABLE {$prefix}wpia_chatbot (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				designation varchar(191) NOT NULL,
				description text NOT NULL,
				compte_api_id bigint(20) unsigned NOT NULL,
				modele varchar(191) NOT NULL,
				system_prompt longtext NOT NULL,
				max_tokens int(10) unsigned NOT NULL DEFAULT 1024,
				temperature decimal(5,2) NOT NULL DEFAULT 0.30,
				actif tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY compte_api_id (compte_api_id)
			) {$charset_collate};",
			'document'            => "CREATE TABLE {$prefix}wpia_document (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				designation varchar(191) NOT NULL,
				description text NOT NULL,
				fichier text NOT NULL,
				hash char(64) NOT NULL,
				taille_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				taille_octets bigint(20) unsigned NOT NULL DEFAULT 0,
				date_ajout datetime NULL,
				statut_indexation varchar(20) NOT NULL DEFAULT 'pending',
				actif tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id)
			) {$charset_collate};",
			'chatbot_document'    => "CREATE TABLE {$prefix}wpia_chatbot_document (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chatbot_id bigint(20) unsigned NOT NULL,
				document_id bigint(20) unsigned NOT NULL,
				ordre int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY chatbot_document (chatbot_id,document_id),
				KEY document_id (document_id)
			) {$charset_collate};",
			'conversation'        => "CREATE TABLE {$prefix}wpia_conversation (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chatbot_id bigint(20) unsigned NOT NULL,
				session_id varchar(64) NOT NULL,
				ip_hash char(64) NOT NULL,
				nb_messages int(10) unsigned NOT NULL DEFAULT 0,
				date_creation datetime NOT NULL,
				date_derniere_activite datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY chatbot_id (chatbot_id),
				KEY session_id (session_id)
			) {$charset_collate};",
			'message'             => "CREATE TABLE {$prefix}wpia_message (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				conversation_id bigint(20) unsigned NOT NULL,
				role varchar(20) NOT NULL,
				contenu longtext NOT NULL,
				tokens int(10) unsigned NOT NULL DEFAULT 0,
				date_creation datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id)
			) {$charset_collate};",
			'limite_utilisation'  => "CREATE TABLE {$prefix}wpia_limite_utilisation (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				chatbot_id bigint(20) unsigned NOT NULL,
				session_id varchar(64) NOT NULL,
				ip_hash char(64) NOT NULL,
				nb_messages_jour int(10) unsigned NOT NULL DEFAULT 0,
				nb_tokens_jour bigint(20) unsigned NOT NULL DEFAULT 0,
				date_maj datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY chatbot_session (chatbot_id,session_id),
				KEY ip_hash (ip_hash)
			) {$charset_collate};",
			'log'                 => "CREATE TABLE {$prefix}wpia_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				niveau varchar(20) NOT NULL,
				categorie varchar(40) NOT NULL,
				message text NOT NULL,
				date_creation datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY categorie_date (categorie,date_creation),
				KEY date_creation (date_creation)
			) {$charset_collate};",
		);
	}
}
