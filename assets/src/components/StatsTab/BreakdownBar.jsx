/**
 * Severity mix panel: one row per severity sorted by count desc, each
 * with a colored thin bar, count, and percentage. Replaces the older
 * stacked horizontal bar so the panel reads cleanly when stacked next
 * to the volume chart on the right side of the dashboard.
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
	segments.sort( ( a, b ) => b.count - a.count );

	return (
		<div
			className="logscope-breakdown"
			role="img"
			aria-label={ buildAriaLabel( segments, grandTotal ) }
		>
			<h3 className="logscope-breakdown__title">
				{ __( 'Severity mix', 'logscope' ) }
			</h3>
			<ul className="logscope-breakdown__list">
				{ segments.map( ( s ) => {
					const pct = ( s.count / grandTotal ) * 100;
					const tone = severityTone( s.severity );
					return (
						<li
							key={ s.severity }
							className="logscope-breakdown__row"
						>
							<div className="logscope-breakdown__head">
								<span
									className={ `logscope-kpi__dot logscope-kpi__dot--${ tone }` }
									aria-hidden="true"
								/>
								<span className="logscope-breakdown__name">
									{ severityLabel( s.severity ) }
								</span>
								<span className="logscope-breakdown__count">
									{ s.count.toLocaleString() }
								</span>
								<span className="logscope-breakdown__pct">
									{ pct >= 10
										? `${ pct.toFixed( 0 ) }%`
										: `${ pct.toFixed( 1 ) }%` }
								</span>
							</div>
							<div className="logscope-breakdown__track">
								<div
									className={ `logscope-breakdown__fill logscope-breakdown__fill--${ tone }` }
									style={ { width: `${ pct }%` } }
								/>
							</div>
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
