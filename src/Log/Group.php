<?php
/**
 * Value object for a group of log entries that share a signature.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * One row in the grouped view: the signature, the representative
 * fields shared across the group, and the running count / first-seen
 * / last-seen window. The actual member entries are not held on the
 * group — callers that need them can re-filter the underlying entry
 * list by signature, which keeps grouped responses cheap to serialise.
 *
 * Public properties because PHP 8.0 lacks `readonly` and the type
 * exists to flow through to REST payloads.
 */
final class Group {

	/**
	 * Stable identity hash for the group; safe to use as a React key.
	 *
	 * @var string
	 */
	public string $signature;

	/**
	 * Severity constant from {@see Severity}. Always identical across
	 * the group; severity participates in the signature.
	 *
	 * @var string
	 */
	public string $severity;

	/**
	 * Source file, or `null` when the underlying entries had none.
	 *
	 * @var string|null
	 */
	public ?string $file;

	/**
	 * Source line, or `null` when the underlying entries had none.
	 *
	 * @var int|null
	 */
	public ?int $line;

	/**
	 * Verbatim message from the first entry observed in this group,
	 * preserved for display. The normalised form is internal to the
	 * signature and is not exposed here.
	 *
	 * @var string
	 */
	public string $sample_message;

	/**
	 * Number of entries that fell into this group.
	 *
	 * @var int
	 */
	public int $count;

	/**
	 * Earliest parseable timestamp seen in the group (raw string form,
	 * e.g. `27-Apr-2026 12:34:56`), or `null` when no member entry had
	 * a parseable timestamp.
	 *
	 * @var string|null
	 */
	public ?string $first_seen;

	/**
	 * Latest parseable timestamp seen in the group (raw string form),
	 * or `null` when no member entry had a parseable timestamp.
	 *
	 * @var string|null
	 */
	public ?string $last_seen;

	/**
	 * Builds a new Group. Mutation is allowed only because PHP 8.0
	 * lacks `readonly`.
	 *
	 * @param string      $signature      Identity hash.
	 * @param string      $severity       Severity constant.
	 * @param string|null $file           Source file.
	 * @param int|null    $line           Source line.
	 * @param string      $sample_message Representative message text.
	 * @param int         $count          Member count.
	 * @param string|null $first_seen     Earliest timestamp.
	 * @param string|null $last_seen      Latest timestamp.
	 */
	public function __construct(
		string $signature,
		string $severity,
		?string $file,
		?int $line,
		string $sample_message,
		int $count,
		?string $first_seen,
		?string $last_seen
	) {
		$this->signature      = $signature;
		$this->severity       = $severity;
		$this->file           = $file;
		$this->line           = $line;
		$this->sample_message = $sample_message;
		$this->count          = $count;
		$this->first_seen     = $first_seen;
		$this->last_seen      = $last_seen;
	}
}
