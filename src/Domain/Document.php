<?php

namespace WPIA\Domain;

use InvalidArgumentException;

class Document extends Entity {
	private string $designation = '';
	private string $description = '';
	private string $fichier = '';
	private string $hash = '';
	private int $tailleTokens = 0;
	private int $tailleOctets = 0;
	private string $dateAjout = '';
	private string $statutIndexation = 'pending';
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

	public function getFichier(): string {
		return $this->fichier;
	}

	public function setFichier( string $fichier ): void {
		$this->fichier = $fichier;
	}

	public function getHash(): string {
		return $this->hash;
	}

	public function setHash( string $hash ): void {
		$this->hash = $hash;
	}

	public function getTailleTokens(): int {
		return $this->tailleTokens;
	}

	public function setTailleTokens( int $taille_tokens ): void {
		$this->assertNonNegative( $taille_tokens, 'taille_tokens' );
		$this->tailleTokens = $taille_tokens;
	}

	public function getTailleOctets(): int {
		return $this->tailleOctets;
	}

	public function setTailleOctets( int $taille_octets ): void {
		$this->assertNonNegative( $taille_octets, 'taille_octets' );
		$this->tailleOctets = $taille_octets;
	}

	public function getDateAjout(): string {
		return $this->dateAjout;
	}

	public function setDateAjout( string $date_ajout ): void {
		$this->dateAjout = $date_ajout;
	}

	public function getStatutIndexation(): string {
		return $this->statutIndexation;
	}

	public function setStatutIndexation( string $statut_indexation ): void {
		if ( ! in_array( $statut_indexation, array( 'pending', 'ready', 'error' ), true ) ) {
			throw new InvalidArgumentException( 'The document processing status is invalid.' );
		}
		$this->statutIndexation = $statut_indexation;
	}

	public function isActif(): bool {
		return $this->actif;
	}

	public function setActif( bool $actif ): void {
		$this->actif = $actif;
	}
}
