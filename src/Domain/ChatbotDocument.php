<?php

namespace WPIA\Domain;

class ChatbotDocument extends Entity {
	/** Document court posant le cadre : toujours envoyé en entier (issue 006). */
	public const ROLE_CONTEXTE = 'contexte';

	/** Base de connaissances détaillée : envoyée en entier ou par sections (issue 006). */
	public const ROLE_COMPLET = 'complet';

	public const ROLES = array( self::ROLE_CONTEXTE, self::ROLE_COMPLET );

	private int $chatbotId = 0;
	private int $documentId = 0;
	private int $ordre = 0;
	private string $role = self::ROLE_COMPLET;

	public static function isValidRole( $role ): bool {
		return is_string( $role ) && in_array( $role, self::ROLES, true );
	}

	public function getChatbotId(): int {
		return $this->chatbotId;
	}

	public function setChatbotId( int $chatbot_id ): void {
		$this->assertPositive( $chatbot_id, 'chatbot_id' );
		$this->chatbotId = $chatbot_id;
	}

	public function getDocumentId(): int {
		return $this->documentId;
	}

	public function setDocumentId( int $document_id ): void {
		$this->assertPositive( $document_id, 'document_id' );
		$this->documentId = $document_id;
	}

	public function getOrdre(): int {
		return $this->ordre;
	}

	public function setOrdre( int $ordre ): void {
		$this->assertNonNegative( $ordre, 'ordre' );
		$this->ordre = $ordre;
	}

	public function getRole(): string {
		return $this->role;
	}

	public function setRole( string $role ): void {
		if ( ! self::isValidRole( $role ) ) {
			throw new \InvalidArgumentException( 'The document role is invalid.' );
		}
		$this->role = $role;
	}
}
