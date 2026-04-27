<?php
/**
 * Exception thrown when a path fails PathGuard validation.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Support;

use InvalidArgumentException;

/**
 * Thrown by PathGuard when a candidate path is rejected. Callers translate
 * the message at the REST or settings boundary; the exception itself stays
 * untranslated to keep PathGuard pure and WP-independent for unit tests.
 */
final class InvalidPathException extends InvalidArgumentException {
}
