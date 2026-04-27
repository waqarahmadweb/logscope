<?php
/**
 * Minimal WP_REST_Response stand-in for unit tests.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting

/**
 * Stripped-down stand-in carrying the body, status, and header surface
 * Logscope handlers touch. Real WP class loads first when integration
 * suites run and this stub is bypassed via the class_exists guard.
 */
class WP_REST_Response {

	/**
	 * Response body.
	 *
	 * @var mixed
	 */
	private $data;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Headers keyed by name.
	 *
	 * @var array<string, string>
	 */
	private array $headers = array();

	public function __construct( $data = null, int $status = 200, array $headers = array() ) {
		$this->data    = $data;
		$this->status  = $status;
		$this->headers = $headers;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}

	public function get_headers(): array {
		return $this->headers;
	}

	public function header( string $name, string $value ): void {
		$this->headers[ $name ] = $value;
	}
}
