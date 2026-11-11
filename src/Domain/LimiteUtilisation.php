<?php

namespace WPIA\Domain;

class LimiteUtilisation extends Entity {
	private int $chatbotId = 0;
	private string $portee = '';
	private string $identifiant = '';
	private string $jour = '';
	private int $nbMessages = 0;
	private int $nbTokens = 0;
	private bool $alerteEnvoyee = false;
	private string $dateMaj = '';

	public function getChatbotId(): int {
		return $this->chatbotId;
	}

	public function setChatbotId( int $chatbot_id ): void {
		$this->assertNonNegative( $chatbot_id, 'chatbot_id' );
		$this->chatbotId = $chatbot_id;
	}

	public function getPortee(): string {
		return $this->portee;
	}

	public function setPortee( string $portee ): void {
		$this->portee = $portee;
	}

	public function getIdentifiant(): string {
		return $this->identifiant;
	}

	public function setIdentifiant( string $identifiant ): void {
		$this->identifiant = $identifiant;
	}

	public function getJour(): string {
		return $this->jour;
	}

	public function setJour( string $jour ): void {
		$this->jour = $jour;
	}

	public function getNbMessages(): int {
		return $this->nbMessages;
	}

	public function setNbMessages( int $nb_messages ): void {
		$this->assertNonNegative( $nb_messages, 'nb_messages' );
		$this->nbMessages = $nb_messages;
	}

	public function getNbTokens(): int {
		return $this->nbTokens;
	}

	public function setNbTokens( int $nb_tokens ): void {
		$this->assertNonNegative( $nb_tokens, 'nb_tokens' );
		$this->nbTokens = $nb_tokens;
	}

	public function isAlerteEnvoyee(): bool {
		return $this->alerteEnvoyee;
	}

	public function setAlerteEnvoyee( bool $alerte_envoyee ): void {
		$this->alerteEnvoyee = $alerte_envoyee;
	}

	public function getDateMaj(): string {
		return $this->dateMaj;
	}

	public function setDateMaj( string $date_maj ): void {
		$this->dateMaj = $date_maj;
	}
}
