<?php

namespace WPIA\Domain;

use InvalidArgumentException;

class FournisseurIA extends Entity {
	private string $designation = '';
	private string $urlApi = '';
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

	public function getUrlApi(): string {
		return $this->urlApi;
	}

	public function setUrlApi( string $url_api ): void {
		$this->urlApi = trim( $url_api );
	}

	public function isActif(): bool {
		return $this->actif;
	}

	public function setActif( bool $actif ): void {
		$this->actif = $actif;
	}
}
