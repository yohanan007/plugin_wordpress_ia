<?php

namespace WPIA\Domain;

use InvalidArgumentException;

class Chatbot extends Entity {
	private string $designation = '';
	private string $description = '';
	private int $compteApiId = 0;
	private string $modele = '';
	private string $systemPrompt = '';
	private int $maxTokens = 1024;
	private float $temperature = 0.7;
	private bool $actif = true;

	public function getDesignation(): string {
		return $this->designation;
	}

	public function setDesignation( string $designation ): void {
		$designation = trim( $designation );
		if ( '' === $designation ) {
			throw new InvalidArgumentException( 'The designation cannot be empty.' );
		}
		$this->designation = $designation;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription( string $description ): void {
		$this->description = $description;
	}

	public function getCompteApiId(): int {
		return $this->compteApiId;
	}

	public function setCompteApiId( int $compte_api_id ): void {
		$this->assertPositive( $compte_api_id, 'compte_api_id' );
		$this->compteApiId = $compte_api_id;
	}

	public function getModele(): string {
		return $this->modele;
	}

	public function setModele( string $modele ): void {
		$this->modele = trim( $modele );
	}

	public function getSystemPrompt(): string {
		return $this->systemPrompt;
	}

	public function setSystemPrompt( string $system_prompt ): void {
		$this->systemPrompt = $system_prompt;
	}

	public function getMaxTokens(): int {
		return $this->maxTokens;
	}

	public function setMaxTokens( int $max_tokens ): void {
		$this->assertPositive( $max_tokens, 'max_tokens' );
		$this->maxTokens = $max_tokens;
	}

	public function getTemperature(): float {
		return $this->temperature;
	}

	public function setTemperature( float $temperature ): void {
		if ( $temperature < 0 || $temperature > 2 ) {
			throw new InvalidArgumentException( 'Temperature must be between 0 and 2.' );
		}
		$this->temperature = $temperature;
	}

	public function isActif(): bool {
		return $this->actif;
	}

	public function setActif( bool $actif ): void {
		$this->actif = $actif;
	}
}
