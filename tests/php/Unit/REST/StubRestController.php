<?php
/**
 * Concrete subclass of RestController used to exercise the abstract base.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\REST;

use Logscope\REST\RestController;
use WP_Error;

final class StubRestController extends RestController {

	public function register_routes(): void {
		// No-op; the base class behaviour is what we exercise here.
	}

	public function expose_error( string $code, string $message, int $status, array $extra ): WP_Error {
		return $this->error( $code, $message, $status, $extra );
	}
}
