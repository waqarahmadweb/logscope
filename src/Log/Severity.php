<?php
/**
 * Stringly-typed severity tokens for parsed log entries.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * String constants for entry severities. Implemented as a class of
 * constants rather than a PHP 8.1 enum because the project minimum is
 * PHP 8.0; the values are stable and intended to flow through to REST
 * payloads and the React filter UI without further mapping.
 */
final class Severity {

	public const FATAL      = 'fatal';
	public const PARSE      = 'parse';
	public const WARNING    = 'warning';
	public const NOTICE     = 'notice';
	public const DEPRECATED = 'deprecated';
	public const STRICT     = 'strict';
	public const UNKNOWN    = 'unknown';

	/**
	 * Returns every defined severity in display order (most-severe first).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::FATAL,
			self::PARSE,
			self::WARNING,
			self::NOTICE,
			self::DEPRECATED,
			self::STRICT,
			self::UNKNOWN,
		);
	}
}
