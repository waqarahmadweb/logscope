/**
 * Single horizontal stacked bar showing the proportion of each severity
 * over the selected range. Where the sparkline grid answers "how does
 * each severity move over time," this answers "what is the mix right
 * now" — comparing magnitudes across severities, which the per-chart-
 * peak normalisation in `Sparkline` deliberately does not do.
 *
 * Uses `flex-grow: <count>` rather than computing `width` percentages
 * so a re-render with one more entry redistributes proportionally
 * without precision drift, and the segment widths sum to 100% by
 * construction.
 */
import { __, sprintf } from '@wordpress/i18n';

import { severityLabel, severityTone } from '../../utils/severity';

const SEVERITY_ORDER = [
	'fatal',
	'parse',
	'warning',
	'notice',
	'deprecated',
	'strict',
];

export default function BreakdownBar( { totals } ) {
	const segments = SEVERITY_ORDER.map( ( severity ) => ( {
		severity,
		count: Number( totals?.[ severity ] || 0 ),
	} ) ).filter( ( s ) => s.count > 0 );

	if ( segments.length === 0 ) {
		return null;
	}

	const grandTotal = segments.reduce( ( sum, s ) => sum + s.count, 0 );

	return (
		<div className="logscope-stats__breakdown">
			<div
				className="logscope-stats__breakdown-bar"
				role="img"
				aria-label={ buildAriaLabel( segments, grandTotal ) }
			>
				{ segments.map( ( s ) => (
					<span
						key={ s.severity }
						className={
							'logscope-stats__breakdown-segment logscope-stats__breakdown-segment--' +
							severityTone( s.severity )
						}
						style={ { flexGrow: s.count } }
					/>
				) ) }
			</div>
			<ul className="logscope-stats__breakdown-legend">
				{ segments.map( ( s ) => {
					const pct = ( ( s.count / grandTotal ) * 100 ).toFixed( 1 );
					return (
						<li
							key={ s.severity }
							className="logscope-stats__breakdown-legend-item"
						>
							<span
								className={
									'logscope-stats__breakdown-swatch logscope-stats__breakdown-swatch--' +
									severityTone( s.severity )
								}
								aria-hidden="true"
							/>
							{ severityLabel( s.severity ) }
							<span className="logscope-stats__breakdown-legend-count">
								{ sprintf(
									// translators: 1: severity count, 2: percentage of total (one decimal place).
									__( '%1$d (%2$s%%)', 'logscope' ),
									s.count,
									pct
								) }
							</span>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

function buildAriaLabel( segments, grandTotal ) {
	const parts = segments.map( ( s ) => {
		const pct = ( ( s.count / grandTotal ) * 100 ).toFixed( 0 );
		return sprintf(
			// translators: 1: severity label, 2: percentage of total.
			__( '%1$s %2$s%%', 'logscope' ),
			severityLabel( s.severity ),
			pct
		);
	} );
	return sprintf(
		// translators: %s is a comma-separated list of "Severity NN%" pairs.
		__( 'Severity breakdown over the range: %s', 'logscope' ),
		parts.join( ', ' )
	);
}
