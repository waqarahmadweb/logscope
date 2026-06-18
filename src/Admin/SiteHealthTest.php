<?php
/**
 * Site Health "recent fatal errors" test.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Admin;

defined( 'ABSPATH' ) || exit;

use DateTimeImmutable;
use DateTimeZone;
use Logscope\Log\LogStats;
use Logscope\Log\Severity;
use Throwable;

/**
 * Surfaces Logscope health into the wp-admin **Tools → Site Health**
 * Status screen. Red when fatals or parse errors occurred in the last
 * 24 hours, amber when only warnings did, green when the window is
 * clean. Reuses {@see LogStats::summarize()} so the same trailing-byte
 * budget, the same parser, and the same 60s transient back the test —
 * Site Health calls run as part of an asynchronous AJAX poll on Status
 * load, and re-parsing 50 MiB on every visit would be wasteful.
 *
 * The action link in each result deep-links to the Logs tab with the
 * relevant severity + 24h window pre-selected via the URL query
 * params `useUrlQuerySync` reads on mount.
 */
final class SiteHealthTest {

	/**
	 * Test slug. Doubles as the `tests['direct'][...]` key Site Health
	 * uses to render the row, and as the value the AJAX call sends on
	 * the `test` key when the user clicks "Run again".
	 */
	public const TEST_ID = 'logscope_recent_fatals';

	/**
	 * Stats service the test reads from.
	 *
	 * @var LogStats
	 */
	private LogStats $stats;

	/**
	 * Constructor.
	 *
	 * @param LogStats $stats Configured stats service.
	 */
	public function __construct( LogStats $stats ) {
		$this->stats = $stats;
	}

	/**
	 * `site_status_tests` filter callback. Registers the test in the
	 * `direct` group so Site Health's first paint shows the result
	 * inline rather than queueing a second async fetch.
	 *
	 * @param array<string, array<string, mixed>>|mixed $tests Existing tests.
	 * @return array<string, array<string, mixed>>
	 */
	public function register( $tests ): array {
		if ( ! is_array( $tests ) ) {
			$tests = array();
		}
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct'][ self::TEST_ID ] = array(
			'label' => __( 'Logscope: recent PHP errors', 'logscope' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Test callback. Pulls the last-24h totals out of {@see LogStats}
	 * and shapes the result into Site Health's expected envelope.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		try {
			$summary = $this->stats->summarize( '24h', 'hour' );
		} catch ( Throwable $e ) {
			return $this->result_unknown( $e->getMessage() );
		}

		$totals = isset( $summary['totals'] ) && is_array( $summary['totals'] )
			? $summary['totals']
			: array();

		$fatals   = (int) ( $totals[ Severity::FATAL ] ?? 0 ) + (int) ( $totals[ Severity::PARSE ] ?? 0 );
		$warnings = (int) ( $totals[ Severity::WARNING ] ?? 0 );

		if ( $fatals > 0 ) {
			return $this->result_critical( $fatals );
		}

		if ( $warnings > 0 ) {
			return $this->result_recommended( $warnings );
		}

		return $this->result_good();
	}

	/**
	 * Builds the "everything's fine" result. Site Health renders this
	 * green; the description still names the window so the admin
	 * understands which 24 hours the test covered.
	 *
	 * @return array<string, mixed>
	 */
	private function result_good(): array {
		return array(
			'label'       => __( 'No fatal or parse errors in the last 24 hours', 'logscope' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Logscope', 'logscope' ),
				'color' => 'green',
			),
			'description' => '<p>'
				. esc_html__(
					'Logscope did not detect any PHP fatal or parse errors in the configured debug log over the last 24 hours.',
					'logscope'
				)
				. '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->logs_url( null ) ),
				esc_html__( 'Open Logscope', 'logscope' )
			),
			'test'        => self::TEST_ID,
		);
	}

	/**
	 * Builds the amber "warnings only" result. Site Health surfaces
	 * `recommended` as a soft prompt rather than a hard alert — the
	 * right tone for a site that is functioning but accumulating noise.
	 *
	 * @param int $warnings Warning count over the window.
	 * @return array<string, mixed>
	 */
	private function result_recommended( int $warnings ): array {
		$desc = sprintf(
			/* translators: %d is the warning count. */
			_n(
				'Logscope detected %d PHP warning in the configured debug log over the last 24 hours, but no fatal or parse errors. Warnings often hint at deprecations or misconfigurations worth a closer look.',
				'Logscope detected %d PHP warnings in the configured debug log over the last 24 hours, but no fatal or parse errors. Warnings often hint at deprecations or misconfigurations worth a closer look.',
				$warnings,
				'logscope'
			),
			$warnings
		);

		return array(
			'label'       => __( 'Recent PHP warnings on this site', 'logscope' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Logscope', 'logscope' ),
				'color' => 'orange',
			),
			'description' => '<p>' . esc_html( $desc ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->logs_url( array( Severity::WARNING ) ) ),
				esc_html__( 'Review warnings in Logscope', 'logscope' )
			),
			'test'        => self::TEST_ID,
		);
	}

	/**
	 * Builds the red "fatals present" result.
	 *
	 * @param int $fatals Combined fatal + parse count over the window.
	 * @return array<string, mixed>
	 */
	private function result_critical( int $fatals ): array {
		$desc = sprintf(
			/* translators: %d is the fatal-error count. */
			_n(
				'Logscope detected %d PHP fatal or parse error in the configured debug log over the last 24 hours. A fatal stops the request that triggered it — visitors will have seen a blank page, a PHP error, or an admin-ajax failure depending on where it landed.',
				'Logscope detected %d PHP fatal or parse errors in the configured debug log over the last 24 hours. A fatal stops the request that triggered it — visitors will have seen a blank page, a PHP error, or an admin-ajax failure depending on where it landed.',
				$fatals,
				'logscope'
			),
			$fatals
		);

		return array(
			'label'       => __( 'Recent PHP fatals on this site', 'logscope' ),
			'status'      => 'critical',
			'badge'       => array(
				'label' => __( 'Logscope', 'logscope' ),
				'color' => 'red',
			),
			'description' => '<p>' . esc_html( $desc ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->logs_url( array( Severity::FATAL, Severity::PARSE ) ) ),
				esc_html__( 'Investigate fatals in Logscope', 'logscope' )
			),
			'test'        => self::TEST_ID,
		);
	}

	/**
	 * Builds the "couldn't compute" fallback. Site Health does not
	 * accept a "none" status from a `direct` test, so we surface the
	 * problem as `recommended` (amber) — a stronger signal than green
	 * but not as alarming as the red used for actual fatals.
	 *
	 * @param string $error Underlying error message; included verbatim
	 *                      in the description so an operator can debug.
	 * @return array<string, mixed>
	 */
	private function result_unknown( string $error ): array {
		return array(
			'label'       => __( 'Logscope could not check the debug log', 'logscope' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Logscope', 'logscope' ),
				'color' => 'gray',
			),
			'description' => '<p>'
				. esc_html__(
					'Logscope was unable to read or parse the configured debug log. The log path may be missing, unreadable, or pointing outside the WordPress install.',
					'logscope'
				)
				. ' <code>' . esc_html( $error ) . '</code></p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $this->logs_url( null ) ),
				esc_html__( 'Open Logscope settings', 'logscope' )
			),
			'test'        => self::TEST_ID,
		);
	}

	/**
	 * Builds the deep-link URL into the Logs tab. When `$severities`
	 * is non-null the URL also embeds a 24-hour `from` window so the
	 * admin lands on the same slice this test reasoned over.
	 *
	 * @param string[]|null $severities Severity tokens to pre-select, or null
	 *                                  to open Logscope with no filter set.
	 * @return string
	 */
	private function logs_url( ?array $severities ): string {
		$base  = admin_url( 'tools.php?page=' . Menu::PAGE_SLUG );
		$query = array();
		if ( null !== $severities && array() !== $severities ) {
			$query['severity'] = implode( ',', $severities );
			$query['from']     = $this->iso_24h_ago();
		}
		if ( array() === $query ) {
			return $base;
		}
		return add_query_arg( $query, $base );
	}

	/**
	 * Returns the ISO date 24 hours before now, in site time. Site time
	 * matches the `Display: Site time / UTC` setting's default and how
	 * the Logs tab renders timestamps, so the deep-link's `from` window
	 * lines up with what the admin sees on arrival.
	 *
	 * @return string
	 */
	private function iso_24h_ago(): string {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return ( new DateTimeImmutable( '-24 hours', $tz ) )->format( 'Y-m-d' );
	}
}
