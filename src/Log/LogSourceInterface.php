<?php
/**
 * Abstraction over a byte-addressable log source (typically a file).
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * Minimal contract the log reader, parser, and tail mode all sit on top of.
 *
 * Implementations are responsible only for byte-level access; severity
 * detection, timestamp parsing, and grouping happen in higher layers.
 * Keeping this interface stream-shaped means a future SSE / in-memory
 * test double can replace `FileLogSource` without touching parsers.
 */
interface LogSourceInterface {

	/**
	 * Returns the source's raw resolved path (or other identifying handle).
	 * Used for error messages and for parsing the originating plugin/theme
	 * out of stack-trace frame paths.
	 *
	 * @return string
	 */
	public function path(): string;

	/**
	 * Whether the underlying source currently exists and is readable.
	 *
	 * Implementations must not throw when the source is missing; missing
	 * is a normal state (a fresh WordPress install with no errors yet).
	 *
	 * @return bool
	 */
	public function exists(): bool;

	/**
	 * Total size in bytes, or 0 when the source does not exist.
	 *
	 * @return int
	 */
	public function size(): int;

	/**
	 * Reads up to `$max_bytes` from the source starting at `$from_byte`.
	 *
	 * Returns an empty string when the source is missing, when the offset
	 * is past EOF, or when the bounds are non-positive. Partial reads near
	 * EOF return whatever bytes are available.
	 *
	 * @param int $from_byte Absolute byte offset, zero-based.
	 * @param int $max_bytes Upper bound on the returned chunk length.
	 * @return string
	 */
	public function read_chunk( int $from_byte, int $max_bytes ): string;
}
