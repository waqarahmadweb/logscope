<?php
/**
 * Renders the "Recent Logscope errors" widget on the WordPress dashboard.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Logscope\Log\Entry;
use Logscope\Log\LogRepository;
use Logscope\Support\Capabilities;
use Throwable;

/**
 * A small "latest activity" peek for users with `logscope_manage`,
 * surfaced on the wp-admin Dashboard so an admin sees recent fatals
 * without opening Tools → Logscope. Reuses
 * {@see LogRepository::get_recent()} so the read budget and parser
 * shape match the rest of the plugin — no parallel reader.
 *
 * The widget renders inline CSS because the React stylesheet only
 * enqueues on the Logscope page; pulling it onto every dashboard
 * paint just for ~80 lines of pill / row styling would be wasteful.
 */
final class DashboardWidget {

	/**
	 * Widget id. Doubles as the dashboard meta-box id WordPress uses
	 * when the user reorders or hides widgets, so changing it would
	 * reset everyone's dashboard layout.
	 */
	public const WIDGET_ID = 'logscope_recent_errors';

	/**
	 * Number of entries to surface. Hardcoded — a longer list bloats
	 * the dashboard and the "View all" link covers the deeper-history
	 * use case.
	 */
	public const LIMIT = 5;

	/**
	 * Maximum characters of the entry message rendered in the row before
	 * truncation. Picked to keep one row at a comfortable single-line
	 * height inside the default dashboard column.
	 */
	public const MESSAGE_TRUNCATE_AT = 140;

	/**
	 * Repository the render reads from.
	 *
	 * @var LogRepository
	 */
	private LogRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param LogRepository $repository Configured repository.
	 */
	public function __construct( LogRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * `wp_dashboard_setup` callback. Cap-gated; subscribers / editors
	 * never see the widget, even though `wp_add_dashboard_widget()`
	 * itself does not enforce caps.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Capabilities::has_manage_cap() ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Logscope · Recent errors', 'logscope' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the widget body. Called by core when the dashboard
	 * paints. Wrapped in `try/catch` so a misconfigured log path
	 * cannot break the dashboard for everything else.
	 *
	 * @return void
	 */
	public function render(): void {
		try {
			$entries = $this->repository->get_recent( self::LIMIT );
		} catch ( Throwable $e ) {
			$entries = array();
		}

		echo $this->styles_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- styles_html() emits a fixed, escaped <style> block.

		if ( array() === $entries ) {
			$this->render_empty_state();
			$this->render_footer();
			return;
		}

		echo '<ul class="logscope-dashboard-widget__list">';
		foreach ( $entries as $entry ) {
			$this->render_row( $entry );
		}
		echo '</ul>';

		$this->render_footer();
	}

	/**
	 * Renders the empty-state message shown when the repository returns
	 * no entries. Mirrors the Logs tab's "no entries" tone — calmly
	 * informational rather than alarming.
	 *
	 * @return void
	 */
	private function render_empty_state(): void {
		echo '<p class="logscope-dashboard-widget__empty">'
			. esc_html__(
				'No recent log entries. The log file may be empty, missing, or WP_DEBUG_LOG may be off.',
				'logscope'
			)
			. '</p>';
	}

	/**
	 * Renders one entry row: severity pill, truncated message, relative
	 * time. Severities are rendered with their canonical Logscope label
	 * so the dashboard tone matches the Logs tab.
	 *
	 * @param Entry $entry Parsed entry to render.
	 * @return void
	 */
	private function render_row( Entry $entry ): void {
		$severity_class = $this->severity_class( $entry->severity );
		$severity_label = $this->severity_label( $entry->severity );
		$message        = $this->truncate( (string) $entry->message );
		$relative       = $this->relative_time( $entry->timestamp );

		echo '<li class="logscope-dashboard-widget__row">';
		echo '<span class="logscope-dashboard-widget__pill logscope-dashboard-widget__pill--' . esc_attr( $severity_class ) . '">'
			. esc_html( $severity_label )
			. '</span>';
		echo '<span class="logscope-dashboard-widget__msg" title="' . esc_attr( (string) $entry->message ) . '">'
			. esc_html( $message )
			. '</span>';
		if ( '' !== $relative ) {
			echo '<span class="logscope-dashboard-widget__time">' . esc_html( $relative ) . '</span>';
		}
		echo '</li>';
	}

	/**
	 * Renders the "View all" footer link.
	 *
	 * @return void
	 */
	private function render_footer(): void {
		echo '<p class="logscope-dashboard-widget__footer">'
			. '<a href="' . esc_url( admin_url( 'tools.php?page=' . Menu::PAGE_SLUG ) ) . '">'
			. esc_html__( 'View all log entries →', 'logscope' )
			. '</a>'
			. '</p>';
	}

	/**
	 * Maps a severity token into the CSS class suffix used by the inline
	 * stylesheet. Keeps unknown tokens on the neutral "unknown" pill so
	 * a parser change does not break the layout.
	 *
	 * @param string|null $severity Raw severity from the entry.
	 * @return string
	 */
	private function severity_class( ?string $severity ): string {
		$known = array( 'fatal', 'parse', 'warning', 'notice', 'deprecated', 'strict' );
		if ( null === $severity ) {
			return 'unknown';
		}
		$lower = strtolower( $severity );
		return in_array( $lower, $known, true ) ? $lower : 'unknown';
	}

	/**
	 * Returns the human-readable label for a severity token. Mirrors the
	 * canonical labels used elsewhere in the plugin (the Logs tab's
	 * pills) so the dashboard does not invent its own vocabulary.
	 *
	 * @param string|null $severity Raw severity from the entry.
	 * @return string
	 */
	private function severity_label( ?string $severity ): string {
		switch ( null === $severity ? '' : strtolower( $severity ) ) {
			case 'fatal':
				return __( 'Fatal', 'logscope' );
			case 'parse':
				return __( 'Parse', 'logscope' );
			case 'warning':
				return __( 'Warning', 'logscope' );
			case 'notice':
				return __( 'Notice', 'logscope' );
			case 'deprecated':
				return __( 'Deprecated', 'logscope' );
			case 'strict':
				return __( 'Strict', 'logscope' );
			default:
				return __( 'Unknown', 'logscope' );
		}
	}

	/**
	 * Truncates the message at {@see self::MESSAGE_TRUNCATE_AT} chars,
	 * appending an ellipsis. Uses `mb_substr` when available so multi-
	 * byte messages don't get sliced mid-character.
	 *
	 * @param string $message Raw entry message.
	 * @return string
	 */
	private function truncate( string $message ): string {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( $len <= self::MESSAGE_TRUNCATE_AT ) {
			return $message;
		}
		$slice = function_exists( 'mb_substr' )
			? mb_substr( $message, 0, self::MESSAGE_TRUNCATE_AT )
			: substr( $message, 0, self::MESSAGE_TRUNCATE_AT );
		return rtrim( $slice ) . '…';
	}

	/**
	 * Formats the entry's UTC timestamp as a "5 minutes ago"-style
	 * relative string via `human_time_diff()`. Returns an empty string
	 * when the timestamp cannot be parsed so the row layout stays
	 * intact.
	 *
	 * @param string|null $timestamp Raw WP-format timestamp.
	 * @return string
	 */
	private function relative_time( ?string $timestamp ): string {
		if ( null === $timestamp ) {
			return '';
		}
		$parsed = DateTimeImmutable::createFromFormat(
			'd-M-Y H:i:s',
			$timestamp,
			new DateTimeZone( 'UTC' )
		);
		if ( false === $parsed ) {
			return '';
		}
		$now = time();
		$ts  = $parsed->getTimestamp();
		if ( $ts > $now ) {
			// Clock-skew defence — `human_time_diff` happily renders
			// future timestamps but "in 3 hours" on a log row would
			// confuse rather than inform. Pin to "just now".
			return __( 'just now', 'logscope' );
		}
		return sprintf(
			/* translators: %s is a human-readable time difference like "5 mins". */
			__( '%s ago', 'logscope' ),
			human_time_diff( $ts, $now )
		);
	}

	/**
	 * Returns the inline `<style>` block scoped to the widget. Inline
	 * because the React stylesheet only enqueues on the Logscope page;
	 * pulling it onto every dashboard paint just for these rules would
	 * be wasteful.
	 *
	 * @return string
	 */
	private function styles_html(): string {
		// phpcs:disable Generic.Files.LineLength.TooLong -- inline CSS is more readable as one block.
		return '<style id="logscope-dashboard-widget-css">'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__list{margin:0;padding:0;list-style:none;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__row{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:baseline;padding:6px 0;border-top:1px solid #f0f0f0;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__row:first-child{border-top:0;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill{display:inline-block;padding:1px 8px;border-radius:9px;font-size:11px;font-weight:600;line-height:16px;background:#ece8e2;color:#5b5751;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill--fatal{background:#ffe1e3;color:#9b1c2a;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill--parse{background:#ffe1e3;color:#9b1c2a;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill--warning{background:#fff3c2;color:#785b00;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill--notice{background:#d8eaff;color:#0c4a8a;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill--deprecated{background:#e6e0ff;color:#43308f;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__pill--strict{background:#e0f5ec;color:#1f5b3f;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__msg{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:#1a1a1f;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__time{color:#6b6b78;font-size:11px;white-space:nowrap;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__empty{color:#6b6b78;margin:0 0 8px;}'
			. '#' . esc_attr( self::WIDGET_ID ) . ' .logscope-dashboard-widget__footer{margin:10px 0 0;text-align:right;}'
			. '</style>';
		// phpcs:enable
	}
}
