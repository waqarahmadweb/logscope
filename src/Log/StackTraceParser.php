<?php
/**
 * Parses PHP stack-trace text into Frame objects.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * Pure parser for the `#N ...` frame lines that PHP writes after
 * `Stack trace:` headers. Input is typically `Entry::$raw` for a fatal
 * error; non-frame lines (the `Stack trace:` header itself, the
 * trailing `thrown in ...` row, blank separators) are silently skipped
 * so the parser can be handed an entire entry without pre-filtering.
 *
 * Argument lists in frames are captured as raw strings and never
 * evaluated — PHP's trace output for arguments routinely contains
 * truncated literals, `Object(Foo\Bar)` placeholders, and `Array`
 * sentinels that are not valid PHP source.
 */
final class StackTraceParser {

	/**
	 * Splits stack-trace text into ordered Frame objects. Returns an
	 * empty array when no frame lines are present.
	 *
	 * @param string $text Raw text containing one or more frame lines.
	 * @return Frame[]
	 */
	public static function parse( string $text ): array {
		if ( '' === $text ) {
			return array();
		}

		$lines = preg_split( '/\R/', $text );
		if ( false === $lines ) {
			return array();
		}

		$frames = array();

		foreach ( $lines as $line ) {
			$frame = self::parse_frame_line( $line );
			if ( null !== $frame ) {
				$frames[] = $frame;
			}
		}

		return $frames;
	}

	/**
	 * Attempts to parse a single line as a frame. Returns `null` when
	 * the line does not start with `#N` — non-frame lines are skipped
	 * so the parser tolerates being fed an entire entry.
	 *
	 * @param string $line One line of input.
	 * @return Frame|null
	 */
	private static function parse_frame_line( string $line ): ?Frame {
		if ( 1 !== preg_match( '/^#(?P<index>\d+)\s+(?P<body>.*)$/', $line, $matches ) ) {
			return null;
		}

		$body = $matches['body'];

		if ( '{main}' === rtrim( $body ) ) {
			return new Frame( null, null, null, null, null, $line );
		}

		if ( 1 === preg_match( '/^\[internal function\]:\s*(?P<call>.+)$/', $body, $internal_match ) ) {
			$call = self::parse_call( $internal_match['call'] );
			return new Frame(
				null,
				null,
				$call['class'],
				$call['method'],
				$call['args'],
				$line
			);
		}

		// Greedy match on the file portion: the rightmost `(<digits>):`
		// is the file/line/call boundary, which keeps Windows-style
		// paths like `C:\Program Files\foo.php` intact even though they
		// contain other parens earlier in the path.
		if ( 1 !== preg_match( '/^(?P<file>.+)\((?P<line>\d+)\):\s*(?P<call>.+)$/', $body, $body_match ) ) {
			return null;
		}

		$call = self::parse_call( $body_match['call'] );

		return new Frame(
			$body_match['file'],
			(int) $body_match['line'],
			$call['class'],
			$call['method'],
			$call['args'],
			$line
		);
	}

	/**
	 * Decomposes a call expression into class / method / args. The
	 * class portion accepts backslashes so namespaced names like
	 * `Foo\Bar\Baz` parse correctly; static (`::`) and instance (`->`)
	 * separators are both accepted. Returns nulls for `class` and
	 * empty string for `args` when the call is a bare function with
	 * no arguments.
	 *
	 * @param string $call Call expression, e.g. `Foo::bar('x')`.
	 * @return array{class: ?string, method: ?string, args: string}
	 */
	private static function parse_call( string $call ): array {
		$pattern = '/^(?:(?P<class>[\\\\A-Za-z_][\\\\A-Za-z0-9_]*)(?:::|->))?(?P<method>[A-Za-z_][A-Za-z0-9_]*)\((?P<args>.*)\)$/';

		if ( 1 !== preg_match( $pattern, trim( $call ), $matches ) ) {
			return array(
				'class'  => null,
				'method' => null,
				'args'   => '',
			);
		}

		$class = isset( $matches['class'] ) && '' !== $matches['class'] ? $matches['class'] : null;

		return array(
			'class'  => $class,
			'method' => $matches['method'],
			'args'   => $matches['args'],
		);
	}
}
