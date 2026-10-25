<?php

namespace WPIA\Service;

use RuntimeException;

class DocumentStorage {
	private const MAX_SIZE = 2097152;

	private SecretCipher $cipher;

	public function __construct( SecretCipher $cipher ) {
		$this->cipher = $cipher;
	}

	/**
	 * @param array<string, mixed> $file
	 * @return array{path:string,content:string,size:int,hash:string}
	 */
	public function storeUpload( array $file ): array {
		if ( ! isset( $file['error'], $file['name'], $file['tmp_name'], $file['size'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			throw new RuntimeException( 'The uploaded document could not be received.' );
		}

		$name = sanitize_file_name( (string) $file['name'] );
		if ( 'md' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new RuntimeException( 'Only Markdown files are accepted.' );
		}

		$size = (int) $file['size'];
		if ( $size < 1 || $size > self::MAX_SIZE ) {
			throw new RuntimeException( 'The document must be smaller than 2 MB.' );
		}

		$tmp_name = (string) $file['tmp_name'];
		if ( ! is_uploaded_file( $tmp_name ) ) {
			throw new RuntimeException( 'The uploaded file is invalid.' );
		}

		$actual_size = filesize( $tmp_name );
		if ( false === $actual_size || $actual_size < 1 || $actual_size > self::MAX_SIZE || $actual_size !== $size ) {
			throw new RuntimeException( 'The uploaded document size is invalid.' );
		}

		$content = file_get_contents( $tmp_name );
		if ( false === $content || str_contains( $content, "\0" ) || ! preg_match( '//u', $content ) ) {
			throw new RuntimeException( 'The Markdown file must contain valid UTF-8 text.' );
		}

		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp_name ) : 'text/plain';
		if ( ! in_array( $mime, array( 'text/plain', 'text/markdown', 'text/x-markdown', 'application/octet-stream' ), true ) ) {
			throw new RuntimeException( 'The uploaded file is not recognized as plain text.' );
		}

		$directory = $this->getDirectory();
		$filename  = wp_generate_uuid4() . '.wpia';
		$path      = trailingslashit( $directory ) . $filename;
		$written   = file_put_contents( $path, $this->cipher->encrypt( $content ), LOCK_EX );
		if ( false === $written ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
			throw new RuntimeException( 'Unable to store the document securely.' );
		}
		if ( ! chmod( $path, 0600 ) ) {
			unlink( $path );
			throw new RuntimeException( 'Unable to restrict access to the stored document.' );
		}

		return array(
			'path'    => $filename,
			'content' => $content,
			'size'    => $size,
			'hash'    => hash( 'sha256', $content ),
		);
	}

	public function read( string $filename ): string {
		$path = $this->getFilePath( $filename );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'The stored document is missing or unreadable.' );
		}

		$data = file_get_contents( $path );
		if ( false === $data ) {
			throw new RuntimeException( 'Unable to read the stored document.' );
		}

		return $this->cipher->decrypt( $data );
	}

	public function delete( string $filename ): void {
		$path = $this->getFilePath( $filename );
		if ( file_exists( $path ) && ! unlink( $path ) ) {
			throw new RuntimeException( 'Unable to delete the stored document.' );
		}
	}

	private function getDirectory(): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new RuntimeException( 'The WordPress upload directory is unavailable.' );
		}

		$directory      = trailingslashit( $uploads['basedir'] ) . 'wpia-private';
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( 'Unable to create the private document directory.' );
		}
		if ( is_link( $directory ) ) {
			throw new RuntimeException( 'The private document directory cannot be a symbolic link.' );
		}

		$base_directory = realpath( $uploads['basedir'] );
		$real_directory = realpath( $directory );
		if ( false === $base_directory || false === $real_directory || 0 !== strpos( $real_directory, trailingslashit( $base_directory ) ) ) {
			throw new RuntimeException( 'The private document directory resolves outside the WordPress upload directory.' );
		}
		if ( ! chmod( $directory, 0700 ) ) {
			throw new RuntimeException( 'Unable to restrict access to the private document directory.' );
		}

		$this->protectDirectory( $directory );

		return $directory;
	}

	private function getFilePath( string $filename ): string {
		if ( ! preg_match( '/^[a-f0-9-]{36}\.wpia$/', $filename ) ) {
			throw new RuntimeException( 'The stored document path is invalid.' );
		}

		$path = trailingslashit( $this->getDirectory() ) . $filename;
		if ( is_link( $path ) ) {
			throw new RuntimeException( 'The stored document path is a symbolic link.' );
		}

		return $path;
	}

	private function protectDirectory( string $directory ): void {
		$files = array(
			'index.php' => "<?php\nexit;\n",
			'.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
		);

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;
			if ( is_link( $path ) ) {
				throw new RuntimeException( 'The private document protection file is a symbolic link.' );
			}
			if ( ! file_exists( $path ) && false === file_put_contents( $path, $contents, LOCK_EX ) ) {
				throw new RuntimeException( 'Unable to protect the document directory.' );
			}
			$existing = file_get_contents( $path );
			if ( false === $existing || $contents !== $existing ) {
				if ( false === file_put_contents( $path, $contents, LOCK_EX ) ) {
					throw new RuntimeException( 'Unable to secure the document directory.' );
				}
			}
		}
	}
}
