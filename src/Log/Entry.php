<?php
/**
 * Value object for a parsed log entry.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * One parsed entry from a debug log. A single entry can span multiple
 * lines: the `raw` field captures the full text including stack-trace
 * continuation rows, while the structured fields reflect just the
 * leading `[timestamp] severity: message` line.
 *
 * Plain public properties rather than getters because PHP 8.0 lacks
 * `readonly` and the entry is a transport DTO between layers — not
 * an entity with invariants to defend.
 */
final class Entry {

	/**
	 * Severity constant from {@see Severity}.
	 *
	 * @var string
	 */
	public string $severity;

	/**
	 * Raw timestamp text as it appeared between the brackets, e.g.
	 * `27-Apr-2026 12:34:56`. `null` when the leading line did not
	 * carry a parseable timestamp.
	 *
	 * @var string|null
	 */
	public ?string $timestamp;

	/**
	 * Timezone token (typically `UTC`) when present, otherwise `null`.
	 *
	 * @var string|null
	 */
	public ?string $timezone;

	/**
	 * Message body following the severity prefix.
	 *
	 * @var string
	 */
	public string $message;

	/**
	 * File path extracted from the message line, when present. Stack
	 * trace frames are parsed separately by `StackTraceParser`.
	 *
	 * @var string|null
	 */
	public ?string $file;

	/**
	 * 1-based source line number extracted from the message line.
	 *
	 * @var int|null
	 */
	public ?int $line;

	/**
	 * Full original text of the entry, including continuation rows.
	 * Useful for raw-view rendering and as the basis for the grouper's
	 * normalised signature.
	 *
	 * @var string
	 */
	public string $raw;

	/**
	 * Builds a new Entry. All fields are immutable in practice; mutation
	 * is allowed only because PHP 8.0 lacks `readonly`.
	 *
	 * @param string      $severity  Severity constant.
	 * @param string|null $timestamp Raw timestamp text.
	 * @param string|null $timezone  Timezone token, if any.
	 * @param string      $message   Message body.
	 * @param string|null $file      Source file path.
	 * @param int|null    $line      Source line number.
	 * @param string      $raw       Original entry text.
	 */
	public function __construct(
		string $severity,
		?string $timestamp,
		?string $timezone,
		string $message,
		?string $file,
		?int $line,
		string $raw
	) {
		$this->severity  = $severity;
		$this->timestamp = $timestamp;
		$this->timezone  = $timezone;
		$this->message   = $message;
		$this->file      = $file;
		$this->line      = $line;
		$this->raw       = $raw;
	}
}
