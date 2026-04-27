<?php
/**
 * Minimal WP_Error stand-in for unit tests.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting

/**
 * Trimmed copy of WordPress's WP_Error surface — only what Logscope's
 * REST controllers actually call. Real WP loads its own class first
 * when the suite eventually runs in an integration harness, in which
 * case this file is skipped via the `class_exists` guard in bootstrap.
 */
class WP_Error {

	/**
	 * Stored error messages keyed by error code.
	 *
	 * @var array<string, string[]>
	 */
	private array $errors = array();

	/**
	 * Stored error data keyed by error code.
	 *
	 * @var array<string, mixed>
	 */
	private array $error_data = array();

	public function __construct( $code = '', string $message = '', $data = '' ) {
		if ( '' === $code ) {
			return;
		}

		$this->errors[ $code ][] = $message;

		if ( '' !== $data && array() !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}

	public function get_error_code(): string {
		$codes = array_keys( $this->errors );

		return $codes[0] ?? '';
	}

	public function get_error_message( string $code = '' ): string {
		$code = '' === $code ? $this->get_error_code() : $code;

		if ( ! isset( $this->errors[ $code ] ) ) {
			return '';
		}

		return $this->errors[ $code ][0] ?? '';
	}

	public function get_error_data( string $code = '' ) {
		$code = '' === $code ? $this->get_error_code() : $code;

		return $this->error_data[ $code ] ?? null;
	}
}
