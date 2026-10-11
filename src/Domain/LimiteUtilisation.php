<?php

namespace WPIA\Domain;

class LimiteUtilisation extends Entity {
	private int $chatbotId = 0;
	private string $sessionId = '';
	private string $ipHash = '';
	private int $nbMessagesJour = 0;
	private int $nbTokensJour = 0;
	private string $dateMaj = '';

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

	public function getNbMessagesJour(): int {
		return $this->nbMessagesJour;
	}

	public function setNbMessagesJour( int $nb_messages_jour ): void {
		$this->assertNonNegative( $nb_messages_jour, 'nb_messages_jour' );
		$this->nbMessagesJour = $nb_messages_jour;
	}

	public function getNbTokensJour(): int {
		return $this->nbTokensJour;
	}

	public function setNbTokensJour( int $nb_tokens_jour ): void {
		$this->assertNonNegative( $nb_tokens_jour, 'nb_tokens_jour' );
		$this->nbTokensJour = $nb_tokens_jour;
	}

	public function getDateMaj(): string {
		return $this->dateMaj;
	}

	public function setDateMaj( string $date_maj ): void {
		$this->dateMaj = $date_maj;
	}
}
