/**
 * Small-multiple grid: one sparkline per severity, rendered only for
 * severities that have at least one entry in the current window. The
 * `unknown` bucket is dropped from the grid even when populated — it
 * adds noise without analytical value, since "we couldn't classify
 * this line" is not a severity an admin reasons about over time.
 *
 * Each chart wrapper carries an aria-label spelling out peak / mean /
 * total for the range; the SVG itself is `aria-hidden` (see
 * `Sparkline.jsx`) so AT users get the same information density a
 * sighted user gets from glancing at the bars without hearing every
 * `<rect>` announced.
 */
import { __, sprintf } from '@wordpress/i18n';

import { severityLabel, severityTone } from '../../utils/severity';
import Sparkline from './Sparkline';

const SEVERITY_ORDER = [
	'fatal',
	'parse',
	'warning',
	'notice',
	'deprecated',
	'strict',
];

function valuesFor( buckets, severity ) {
	return buckets.map( ( b ) => Number( b?.[ severity ] || 0 ) );
}

function summarise( values ) {
	let total = 0;
	let peak = 0;
	for ( const v of values ) {
		total += v;
		if ( v > peak ) {
			peak = v;
		}
	}
	const mean = values.length > 0 ? total / values.length : 0;
	return { total, peak, mean };
}

export default function SparklineGrid( { buckets, totals } ) {
	if ( ! Array.isArray( buckets ) || buckets.length === 0 ) {
		return null;
	}

	const visible = SEVERITY_ORDER.filter(
		( severity ) => Number( totals?.[ severity ] || 0 ) > 0
	);

	if ( visible.length === 0 ) {
		return null;
	}

	return (
		<div className="logscope-stats__sparkline-grid">
			{ visible.map( ( severity ) => {
				const values = valuesFor( buckets, severity );
				const { total, peak, mean } = summarise( values );
				const label = severityLabel( severity );
				const description = sprintf(
					// translators: 1: severity label, 2: total entries, 3: peak per bucket, 4: mean per bucket (rounded to one decimal).
					__(
						'%1$s: %2$d total, peak %3$d, mean %4$s per bucket',
						'logscope'
					),
					label,
					total,
					peak,
					mean.toFixed( 1 )
				);

				return (
					<figure
						key={ severity }
						className="logscope-stats__sparkline-cell"
						aria-label={ description }
					>
						<figcaption className="logscope-stats__sparkline-caption">
							<span className="logscope-stats__sparkline-name">
								{ label }
							</span>
							<span className="logscope-stats__sparkline-total">
								{ total }
							</span>
						</figcaption>
						<Sparkline
							values={ values }
							tone={ severityTone( severity ) }
						/>
					</figure>
				);
			} ) }
		</div>
	);
}
