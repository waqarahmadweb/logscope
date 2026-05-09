/**
 * KPI tiles row for the Stats tab. One dark "Total" tile plus one
 * per-severity tile (only severities with at least one entry render,
 * so a quiet site doesn't show empty parse/strict cards). Each per-
 * severity tile carries a tiny inline polyline sparkline computed
 * from the same `buckets` array the volume chart uses, normalised
 * per-tile so the shape — not the magnitude — is what the eye picks
 * up.
 */
import { __ } from '@wordpress/i18n';

import { severityLabel, severityTone } from '../../utils/severity';

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

function MiniSparkline( { values, tone } ) {
	const safe = Array.isArray( values ) ? values : [];
	if ( safe.length === 0 ) {
		return null;
	}
	const peak = safe.reduce( ( m, v ) => ( v > m ? v : m ), 0 );
	const W = 80;
	const H = 22;
	const step = safe.length > 1 ? W / ( safe.length - 1 ) : W;
	const points = safe
		.map( ( v, i ) => {
			const x = i * step;
			const y = peak > 0 ? H - ( v / peak ) * ( H - 2 ) - 1 : H - 1;
			return `${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }`;
		} )
		.join( ' ' );
	return (
		<svg
			className={ `logscope-kpi__spark logscope-kpi__spark--${ tone }` }
			viewBox={ `0 0 ${ W } ${ H }` }
			preserveAspectRatio="none"
			aria-hidden="true"
			focusable="false"
		>
			<polyline points={ points } fill="none" strokeWidth="1.5" />
		</svg>
	);
}

export default function KpiGrid( { totals, buckets } ) {
	const safeBuckets = Array.isArray( buckets ) ? buckets : [];
	const grandTotal = SEVERITY_ORDER.reduce(
		( sum, sev ) => sum + Number( totals?.[ sev ] || 0 ),
		0
	);
	const visible = SEVERITY_ORDER.filter(
		( sev ) => Number( totals?.[ sev ] || 0 ) > 0
	);

	return (
		<div className="logscope-kpi-grid">
			<div className="logscope-kpi logscope-kpi--total">
				<div className="logscope-kpi__label">
					{ __( 'Total entries', 'logscope' ) }
				</div>
				<div className="logscope-kpi__value">
					{ grandTotal.toLocaleString() }
				</div>
				<div className="logscope-kpi__meta">
					{ __( 'in selected range', 'logscope' ) }
				</div>
			</div>
			{ visible.map( ( severity ) => {
				const tone = severityTone( severity );
				const values = valuesFor( safeBuckets, severity );
				return (
					<div
						key={ severity }
						className={ `logscope-kpi logscope-kpi--${ tone }` }
					>
						<div className="logscope-kpi__label">
							<span
								className={ `logscope-kpi__dot logscope-kpi__dot--${ tone }` }
								aria-hidden="true"
							/>
							{ severityLabel( severity ) }
						</div>
						<div className="logscope-kpi__value">
							{ Number(
								totals?.[ severity ] || 0
							).toLocaleString() }
						</div>
						<MiniSparkline values={ values } tone={ tone } />
					</div>
				);
			} ) }
		</div>
	);
}
