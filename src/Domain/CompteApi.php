<?php

namespace WPIA\Domain;

class CompteApi extends Entity {
	private int $fournisseurId = 0;
	private string $apiKey = '';
	private string $commentaire = '';
	private bool $actif = true;

	public function getFournisseurId(): int {
		return $this->fournisseurId;
	}

	public function setFournisseurId( int $fournisseur_id ): void {
		$this->assertPositive( $fournisseur_id, 'fournisseur_id' );
		$this->fournisseurId = $fournisseur_id;
	}

	public function getApiKey(): string {
		return $this->apiKey;
	}

	public function setApiKey( string $api_key ): void {
		$this->apiKey = $api_key;
	}

	public function getCommentaire(): string {
		return $this->commentaire;
	}

	public function setCommentaire( string $commentaire ): void {
		$this->commentaire = $commentaire;
	}

	public function isActif(): bool {
		return $this->actif;
	}

	public function setActif( bool $actif ): void {
		$this->actif = $actif;
	}
}
