<?php
/**
 * Paginated result wrapper for log queries.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Log;

/**
 * Carries one page of items plus the totals needed to build
 * `X-WP-Total` / `X-WP-TotalPages` REST headers and to render
 * pagination controls. `items` is `Entry[]` for ungrouped queries
 * and `Group[]` for grouped queries.
 *
 * Public properties because PHP 8.0 lacks `readonly`.
 */
final class PagedResult {

	/**
	 * One page of items.
	 *
	 * @var Entry[]|Group[]
	 */
	public array $items;

	/**
	 * Total matching items before pagination was applied.
	 *
	 * @var int
	 */
	public int $total;

	/**
	 * 1-based page index that produced `items`.
	 *
	 * @var int
	 */
	public int $page;

	/**
	 * Items per page used for the slice.
	 *
	 * @var int
	 */
	public int $per_page;

	/**
	 * Total page count given `total` and `per_page`. Always at least 1
	 * so consumers don't divide-by-zero when totals are empty.
	 *
	 * @var int
	 */
	public int $total_pages;

	/**
	 * Byte offset at which the underlying source ended when this result
	 * was produced. The tail-mode client passes the previous response's
	 * `last_byte` back as `since` to fetch only newly-appended lines.
	 *
	 * @var int
	 */
	public int $last_byte;

	/**
	 * True when the source was detected as rotated/cleared between two
	 * tail polls — i.e. the caller's `since_byte` pointed past the
	 * current EOF, meaning the file shrunk. The client treats this as a
	 * baseline reset rather than a delta append.
	 *
	 * @var bool
	 */
	public bool $rotated;

	/**
	 * Builds a result page.
	 *
	 * @param Entry[]|Group[] $items       The page slice.
	 * @param int             $total       Pre-pagination total.
	 * @param int             $page        Page index.
	 * @param int             $per_page    Items per page.
	 * @param int             $total_pages Total pages.
	 * @param int             $last_byte   Source size at read time.
	 * @param bool            $rotated     Source detected rotated since last poll.
	 */
	public function __construct(
		array $items,
		int $total,
		int $page,
		int $per_page,
		int $total_pages,
		int $last_byte = 0,
		bool $rotated = false
	) {
		$this->items       = $items;
		$this->total       = $total;
		$this->page        = $page;
		$this->per_page    = $per_page;
		$this->total_pages = $total_pages;
		$this->last_byte   = $last_byte;
		$this->rotated     = $rotated;
	}
}
