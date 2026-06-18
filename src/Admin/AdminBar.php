<?php
/**
 * Renders the Logscope status indicator in the WordPress admin bar.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Admin;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Logscope\Log\LogParser;
use Logscope\Log\LogSourceInterface;
use Logscope\Settings\Settings;
use Logscope\Support\Capabilities;
use Throwable;
use WP_Admin_Bar;

/**
 * Adds a top-bar node on every wp-admin screen so the admin can see at
 * a glance whether `WP_DEBUG_LOG` is on and how much has hit the log
 * today, without opening the Logscope page.
 *
 * Two visible states drive the leading dot:
 *
 *   - green: `WP_DEBUG_LOG` is defined and truthy — the file is being
 *     written, so the count badge is meaningful.
 *   - grey:  `WP_DEBUG_LOG` is off, undefined, or the source is missing —
 *     the count badge is suppressed because the underlying number is
 *     either zero or unknowable.
 *
 * Today's entry count is cached for {@see self::CACHE_TTL_SECONDS} in a
 * transient keyed by today's site-tz date so a calendar-day roll mints
 * a fresh slot, and so a rapid succession of admin-bar paints across
 * different screens shares the same parsed result. Cap-gated through
 * {@see Capabilities::has_manage_cap()}, suppressed entirely when the
 * `admin_bar_enabled` setting is off.
 */
final class AdminBar {

	/**
	 * Admin-bar node id. Matches the slug WordPress hangs `<li id="wp-admin-bar-…">`
	 * around, so CSS targeting and removal-by-id work.
	 */
	public const NODE_ID = 'logscope-status';

	/**
	 * Transient prefix. Combined with today's site-tz date so a calendar
	 * roll invalidates the count without us writing a `delete_transient`.
	 */
	public const TRANSIENT_PREFIX = 'logscope_admin_bar_today_';

	/**
	 * Transient TTL. Short enough that a fresh fatal during active
	 * admin-bar use catches up in under a minute; long enough that
	 * scrolling through wp-admin tabs doesn't re-parse the log per
	 * paint.
	 */
	public const CACHE_TTL_SECONDS = 60;

	/**
	 * Trailing-byte budget for the "today's count" parse. The full
	 * `LogStats` budget (50 MiB) is overkill — today's entries on any
	 * realistic install fit in the last few hundred KiB. Capping at
	 * 16 MiB keeps the admin-bar cost bounded on a pathological log.
	 */
	public const MAX_BYTES = 16 * 1024 * 1024;

	/**
	 * Hook priority. 90 sits late enough that core's "Site Name" /
	 * "Updates" nodes are already on the bar (so a same-priority site
	 * hasn't shifted us off the right edge in a surprising way) and
	 * early enough that other plugins running at 100+ can still relocate
	 * the node if they want to.
	 */
	public const HOOK_PRIORITY = 90;

	/**
	 * Source the today-count parse reads from.
	 *
	 * @var LogSourceInterface
	 */
	private LogSourceInterface $source;

	/**
	 * Settings facade for the `admin_bar_enabled` toggle lookup.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param LogSourceInterface $source   Validated log source.
	 * @param Settings           $settings Settings facade.
	 */
	public function __construct( LogSourceInterface $source, Settings $settings ) {
		$this->source   = $source;
		$this->settings = $settings;
	}

	/**
	 * `admin_bar_menu` callback. Receives the bar instance from WP and
	 * appends the Logscope node.
	 *
	 * @param WP_Admin_Bar $bar Admin bar instance.
	 * @return void
	 */
	public function register( WP_Admin_Bar $bar ): void {
		if ( ! Capabilities::has_manage_cap() ) {
			return;
		}

		if ( 1 !== (int) $this->settings->get( 'admin_bar_enabled' ) ) {
			return;
		}

		$debug_log_on = defined( 'WP_DEBUG_LOG' ) && (bool) constant( 'WP_DEBUG_LOG' );
		$count        = $debug_log_on ? $this->today_count() : 0;

		$bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => $this->title_html( $debug_log_on, $count ),
				'href'  => admin_url( 'tools.php?page=' . Menu::PAGE_SLUG ),
				'meta'  => array(
					'title' => $this->tooltip_text( $debug_log_on, $count ),
				),
			)
		);
	}

	/**
	 * `admin_print_styles` / `wp_print_styles` callback. Emits the few
	 * inline rules the bar node needs. Inline rather than enqueued
	 * because the node renders on every wp-admin and front-end-when-
	 * logged-in screen — adding a stylesheet handle for ~200 bytes of
	 * CSS is a worse trade than the inline cost.
	 *
	 * @return void
	 */
	public function print_styles(): void {
		if ( ! Capabilities::has_manage_cap() ) {
			return;
		}
		if ( 1 !== (int) $this->settings->get( 'admin_bar_enabled' ) ) {
			return;
		}

		echo '<style id="logscope-admin-bar-css">'
			. '#wpadminbar #wp-admin-bar-' . esc_attr( self::NODE_ID ) . ' .logscope-ab-dot{'
			. 'display:inline-block;width:8px;height:8px;border-radius:50%;'
			. 'margin-right:6px;vertical-align:middle;'
			. '}'
			. '#wpadminbar #wp-admin-bar-' . esc_attr( self::NODE_ID ) . ' .logscope-ab-dot--on{background:#46b450;}'
			. '#wpadminbar #wp-admin-bar-' . esc_attr( self::NODE_ID ) . ' .logscope-ab-dot--off{background:#999;}'
			. '#wpadminbar #wp-admin-bar-' . esc_attr( self::NODE_ID ) . ' .logscope-ab-count{'
			. 'display:inline-block;margin-left:6px;padding:0 6px;border-radius:9px;'
			. 'background:#d33545;color:#fff;font-size:11px;line-height:16px;font-weight:600;'
			. '}'
			. '</style>';
	}

	/**
	 * Builds the node title HTML. Returns an inline-styled span so
	 * stylesheets in unusual admin themes do not have to be defeated.
	 *
	 * @param bool $debug_log_on Whether `WP_DEBUG_LOG` is truthy.
	 * @param int  $count        Today's entry count.
	 * @return string
	 */
	private function title_html( bool $debug_log_on, int $count ): string {
		$dot = sprintf(
			'<span class="logscope-ab-dot logscope-ab-dot--%s" aria-hidden="true"></span>',
			$debug_log_on ? 'on' : 'off'
		);

		$label = '<span class="logscope-ab-label">' . esc_html__( 'Logscope', 'logscope' ) . '</span>';

		$badge = '';
		if ( $debug_log_on && $count > 0 ) {
			$badge = sprintf(
				'<span class="logscope-ab-count">%s</span>',
				esc_html( (string) $count )
			);
		}

		return $dot . $label . $badge;
	}

	/**
	 * Builds the hover tooltip ("title" attribute on the bar's <a>).
	 *
	 * @param bool $debug_log_on Whether `WP_DEBUG_LOG` is truthy.
	 * @param int  $count        Today's entry count.
	 * @return string
	 */
	private function tooltip_text( bool $debug_log_on, int $count ): string {
		if ( ! $debug_log_on ) {
			return __( 'Logscope: WP_DEBUG_LOG is off — the log file is not being written.', 'logscope' );
		}

		return sprintf(
			/* translators: %d is today's log entry count. */
			_n(
				'Logscope: %d log entry today.',
				'Logscope: %d log entries today.',
				$count,
				'logscope'
			),
			$count
		);
	}

	/**
	 * Returns today's entry count, served from a 60s transient when one
	 * exists. The transient key embeds today's site-tz date so a roll
	 * past midnight implicitly invalidates the previous day's count.
	 *
	 * @return int
	 */
	private function today_count(): int {
		$today_local = wp_date( 'Y-m-d' );
		if ( ! is_string( $today_local ) || '' === $today_local ) {
			$today_local = gmdate( 'Y-m-d' );
		}

		$cache_key = self::TRANSIENT_PREFIX . $today_local;
		$cached    = get_transient( $cache_key );
		if ( is_int( $cached ) ) {
			return $cached;
		}

		try {
			$count = $this->compute_today_count();
		} catch ( Throwable $e ) {
			// A misconfigured log path or a parser hiccup must not break
			// the admin bar — every other plugin's nodes share this hook.
			// Cache zero so we don't re-throw on every paint within the
			// TTL window.
			$count = 0;
		}

		set_transient( $cache_key, $count, self::CACHE_TTL_SECONDS );
		return $count;
	}

	/**
	 * Reads the trailing slice of the log, parses it, and counts entries
	 * whose timestamp falls inside today's site-local day. WordPress
	 * writes timestamps as UTC; we convert each entry into the site
	 * timezone before comparing so the count matches what the admin
	 * sees on the Logs tab in their preferred display.
	 *
	 * @return int
	 */
	private function compute_today_count(): int {
		if ( ! $this->source->exists() ) {
			return 0;
		}
		$size = $this->source->size();
		if ( 0 === $size ) {
			return 0;
		}

		$offset  = $size > self::MAX_BYTES ? $size - self::MAX_BYTES : 0;
		$max     = $size - $offset;
		$chunk   = $this->source->read_chunk( $offset, $max );
		$entries = LogParser::parse( $chunk );
		if ( array() === $entries ) {
			return 0;
		}

		$site_tz     = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$today_local = ( new DateTimeImmutable( 'now', $site_tz ) )->format( 'Y-m-d' );

		$count = 0;
		foreach ( $entries as $entry ) {
			if ( null === $entry->timestamp ) {
				continue;
			}
			$parsed = DateTimeImmutable::createFromFormat(
				'd-M-Y H:i:s',
				$entry->timestamp,
				new DateTimeZone( 'UTC' )
			);
			if ( false === $parsed ) {
				continue;
			}
			if ( $parsed->setTimezone( $site_tz )->format( 'Y-m-d' ) === $today_local ) {
				++$count;
			}
		}

		return $count;
	}
}
