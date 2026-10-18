<?php

namespace WPIA\Repository;

use WPIA\Service\SecretCipher;

class CompteApiRepository extends AbstractRepository {
	protected string $table_suffix = 'compte_api';

	protected array $columns = array(
		'fournisseur_id' => '%d',
		'api_key'        => '%s',
		'commentaire'    => '%s',
		'actif'          => '%d',
	);

	public function create( array $data ): int {
		return parent::create( $this->encryptApiKey( $data ) );
	}

	public function update( int $id, array $data ): int {
		return parent::update( $id, $this->encryptApiKey( $data ) );
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function encryptApiKey( array $data ): array {
		if ( isset( $data['api_key'] ) && is_string( $data['api_key'] ) ) {
			$cipher = new SecretCipher();
			if ( '' !== $data['api_key'] && ! $cipher->isEncrypted( $data['api_key'] ) ) {
				$data['api_key'] = $cipher->encrypt( $data['api_key'] );
			}
		}

		return $data;
	}
}
