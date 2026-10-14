<?php

namespace WPIA\Domain;

use InvalidArgumentException;

abstract class Entity {
	private ?int $id = null;

	public function getId(): ?int {
		return $this->id;
	}

	public function setId( ?int $id ): void {
		if ( null !== $id && $id < 1 ) {
			throw new InvalidArgumentException( 'The identifier must be positive.' );
		}

		$this->id = $id;
	}

	protected function assertPositive( int $value, string $field ): void {
		if ( $value < 1 ) {
			throw new InvalidArgumentException( $field . ' must be positive.' );
		}
	}

	protected function assertNonNegative( int $value, string $field ): void {
		if ( $value < 0 ) {
			throw new InvalidArgumentException( $field . ' cannot be negative.' );
		}
	}
}
