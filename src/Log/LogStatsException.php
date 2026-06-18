<?php
/**
 * Exception thrown for invalid stats query inputs.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Internal exception raised by {@see LogStats::summarize()} when the
 * caller passes an unknown range or bucket token. The REST controller
 * maps this to a 400 response with a sanitised message.
 */
final class LogStatsException extends RuntimeException {
}
