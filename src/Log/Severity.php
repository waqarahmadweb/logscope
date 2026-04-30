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

	/**
	 * Returns the translated, display-form label for a severity token.
	 * Mirrors the JS `severityLabel()` helper in `assets/src/utils/severity.js`
	 * so PHP-side surfaces (alerts, future CLI output) read the same way
	 * as the React UI.
	 *
	 * @param string $severity Severity token.
	 * @return string
	 */
	public static function label( string $severity ): string {
		switch ( $severity ) {
			case self::FATAL:
				return __( 'Fatal error', 'logscope' );
			case self::PARSE:
				return __( 'Parse error', 'logscope' );
			case self::WARNING:
				return __( 'Warning', 'logscope' );
			case self::NOTICE:
				return __( 'Notice', 'logscope' );
			case self::DEPRECATED:
				return __( 'Deprecated', 'logscope' );
			case self::STRICT:
				return __( 'Strict standards', 'logscope' );
			default:
				return __( 'Unknown', 'logscope' );
		}
	}
}
