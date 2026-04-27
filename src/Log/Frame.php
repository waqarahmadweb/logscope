<?php
/**
 * Value object for a single stack-trace frame.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * One frame parsed from a PHP stack trace. Special frames are
 * represented by null patterns rather than dedicated subtypes:
 *
 *   - `{main}` → every nullable field is null; `raw` holds the line.
 *   - `[internal function]: ...` → `file` and `line` are null; the
 *     class/method/args carry the call.
 *   - regular frame → all populated; `class` is null for a bare
 *     function call (no `Class::method` or `Class->method` prefix).
 *
 * `args` is intentionally a raw string copy of what PHP wrote into the
 * trace. It is never evaluated, deserialised, or otherwise interpreted
 * — stack-trace arg dumps routinely contain truncated literals,
 * `Object(Foo\Bar)` placeholders, and other non-PHP shapes.
 */
final class Frame {

	/**
	 * Source file path for this frame, or `null` for `{main}` and
	 * `[internal function]` frames.
	 *
	 * @var string|null
	 */
	public ?string $file;

	/**
	 * 1-based source line number, or `null` for `{main}` and
	 * `[internal function]` frames.
	 *
	 * @var int|null
	 */
	public ?int $line;

	/**
	 * Fully-qualified class name (with backslashes for namespaces),
	 * or `null` for a bare function call or `{main}`.
	 *
	 * @var string|null
	 */
	public ?string $class;

	/**
	 * Function or method name, or `null` for `{main}`.
	 *
	 * @var string|null
	 */
	public ?string $method;

	/**
	 * Raw argument list as PHP wrote it, never evaluated. Empty string
	 * when the call had no arguments; `null` for `{main}`.
	 *
	 * @var string|null
	 */
	public ?string $args;

	/**
	 * Original frame line as it appeared in the trace.
	 *
	 * @var string
	 */
	public string $raw;

	/**
	 * Builds a new Frame. Mutation is allowed only because PHP 8.0
	 * lacks `readonly`; callers should treat instances as immutable.
	 *
	 * @param string|null $file   Source file path.
	 * @param int|null    $line   Source line.
	 * @param string|null $class  Class name.
	 * @param string|null $method Method or function name.
	 * @param string|null $args   Raw args string.
	 * @param string      $raw    Original frame line.
	 */
	public function __construct(
		?string $file,
		?int $line,
		?string $class, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
		?string $method,
		?string $args,
		string $raw
	) {
		$this->file   = $file;
		$this->line   = $line;
		$this->class  = $class;
		$this->method = $method;
		$this->args   = $args;
		$this->raw    = $raw;
	}
}
