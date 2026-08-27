/**
 * Severity mix panel: one row per severity sorted by count desc, each
 * with a colored thin bar, count, and percentage. Replaces the older
 * stacked horizontal bar so the panel reads cleanly when stacked next
 * to the volume chart on the right side of the dashboard.
 */
import { __ } from '@wordpress/i18n';

import {
	SEVERITY_TOKENS,
	severityLabel,
	severityTone,
} from '../../utils/severity';

// Canonical 7-token list (incl. `unknown`) — a local fork here once
// dropped `unknown` and hid its rows from the mix.
const SEVERITY_ORDER = SEVERITY_TOKENS;

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

	// No role="img" on the container: it collapsed the subtree for AT,
	// hiding the heading and per-row text. The rows are real text — the
	// list reads fine on its own.
	return (
		<div className="logscope-breakdown">
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

