<?php
/**
 * Minimal WP_REST_Request stand-in for unit tests.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting

/**
 * Tiny replica of the slice of WP_REST_Request that Logscope handlers
 * touch — `get_params()`. Real WP class loads first when integration
 * suites run and this stub is bypassed via the class_exists guard.
 */
class WP_REST_Request {

	/**
	 * Stored request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params;

	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_params(): array {
		return $this->params;
	}

	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}

	public function set_param( string $key, $value ): void {
		$this->params[ $key ] = $value;
	}
}
