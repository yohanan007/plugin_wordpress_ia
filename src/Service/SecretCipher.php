<?php

namespace WPIA\Service;

use RuntimeException;

class SecretCipher {
	private const PREFIX = 'wpia:v1:';

	public function isAvailable(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'hash_hkdf' )
			&& defined( 'AUTH_KEY' )
			&& defined( 'SECURE_AUTH_KEY' )
			&& strlen( AUTH_KEY ) >= 32
			&& strlen( SECURE_AUTH_KEY ) >= 32
			&& 'put your unique phrase here' !== AUTH_KEY
			&& 'put your unique phrase here' !== SECURE_AUTH_KEY;
	}

	public function isEncrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	public function encrypt( string $plaintext ): string {
		if ( ! $this->isAvailable() ) {
			throw new RuntimeException( 'OpenSSL or the WordPress authentication key is unavailable.' );
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $exception ) {
			throw new RuntimeException( 'Unable to generate a secure encryption nonce.', 0, $exception );
		}

		$tag  = '';
		$data = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$this->getKey(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $data || 16 !== strlen( $tag ) ) {
			throw new RuntimeException( 'Unable to encrypt the secret.' );
		}

		return self::PREFIX . base64_encode( $iv . $tag . $data );
	}

	public function decrypt( string $value ): string {
		if ( ! $this->isEncrypted( $value ) ) {
			return $value;
		}

		if ( ! $this->isAvailable() ) {
			throw new RuntimeException( 'OpenSSL or the WordPress authentication key is unavailable.' );
		}

		$payload = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $payload || strlen( $payload ) < 28 ) {
			throw new RuntimeException( 'The stored secret is invalid.' );
		}

		$plaintext = openssl_decrypt(
			substr( $payload, 28 ),
			'aes-256-gcm',
			$this->getKey(),
			OPENSSL_RAW_DATA,
			substr( $payload, 0, 12 ),
			substr( $payload, 12, 16 )
		);

		if ( false === $plaintext ) {
			throw new RuntimeException( 'The stored secret cannot be decrypted.' );
		}

		return $plaintext;
	}

	private function getKey(): string {
		$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		$key      = hash_hkdf( 'sha256', $material, 32, 'wpia-api-secret-v1' );

		if ( false === $key || 32 !== strlen( $key ) ) {
			throw new RuntimeException( 'Unable to derive the encryption key.' );
		}

		return $key;
	}
}
