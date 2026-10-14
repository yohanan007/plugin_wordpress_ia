<?php

namespace WPIA\Domain;

class Conversation extends Entity {
	private int $chatbotId = 0;
	private string $sessionId = '';
	private string $ipHash = '';
	private int $nbMessages = 0;
	private string $dateCreation = '';
	private string $dateDerniereActivite = '';

	public function getChatbotId(): int {
		return $this->chatbotId;
	}

	public function setChatbotId( int $chatbot_id ): void {
		$this->assertPositive( $chatbot_id, 'chatbot_id' );
		$this->chatbotId = $chatbot_id;
	}

	public function getSessionId(): string {
		return $this->sessionId;
	}

	public function setSessionId( string $session_id ): void {
		$this->sessionId = $session_id;
	}

	public function getIpHash(): string {
		return $this->ipHash;
	}

	public function setIpHash( string $ip_hash ): void {
		$this->ipHash = $ip_hash;
	}

	public function getNbMessages(): int {
		return $this->nbMessages;
	}

	public function setNbMessages( int $nb_messages ): void {
		$this->assertNonNegative( $nb_messages, 'nb_messages' );
		$this->nbMessages = $nb_messages;
	}

	public function getDateCreation(): string {
		return $this->dateCreation;
	}

	public function setDateCreation( string $date_creation ): void {
		$this->dateCreation = $date_creation;
	}

	public function getDateDerniereActivite(): string {
		return $this->dateDerniereActivite;
	}

	public function setDateDerniereActivite( string $date_derniere_activite ): void {
		$this->dateDerniereActivite = $date_derniere_activite;
	}
}
