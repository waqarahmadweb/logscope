<?php
/**
 * Exception thrown for invalid LogQuery parameters.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

use InvalidArgumentException;

/**
 * Thrown when a LogQuery is constructed with invalid parameters
 * (regex too long, malformed pattern, out-of-range pagination).
 * REST controllers translate this into a 400 response.
 */
final class LogQueryException extends InvalidArgumentException {
}
