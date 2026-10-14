<?php

namespace WPIA\Domain;

use InvalidArgumentException;

class Message extends Entity {
	private int $conversationId = 0;
	private string $role = 'user';
	private string $contenu = '';
	private int $tokens = 0;
	private string $dateCreation = '';

	public function getConversationId(): int {
		return $this->conversationId;
	}

	public function setConversationId( int $conversation_id ): void {
		$this->assertPositive( $conversation_id, 'conversation_id' );
		$this->conversationId = $conversation_id;
	}

	public function getRole(): string {
		return $this->role;
	}

	public function setRole( string $role ): void {
		if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) ) {
			throw new InvalidArgumentException( 'The message role is invalid.' );
		}
		$this->role = $role;
	}

	public function getContenu(): string {
		return $this->contenu;
	}

	public function setContenu( string $contenu ): void {
		$this->contenu = $contenu;
	}

	public function getTokens(): int {
		return $this->tokens;
	}

	public function setTokens( int $tokens ): void {
		$this->assertNonNegative( $tokens, 'tokens' );
		$this->tokens = $tokens;
	}

	public function getDateCreation(): string {
		return $this->dateCreation;
	}

	public function setDateCreation( string $date_creation ): void {
		$this->dateCreation = $date_creation;
	}
}
