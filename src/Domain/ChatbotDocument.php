<?php

namespace WPIA\Domain;

class ChatbotDocument extends Entity {
	private int $chatbotId = 0;
	private int $documentId = 0;
	private int $ordre = 0;

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
}
