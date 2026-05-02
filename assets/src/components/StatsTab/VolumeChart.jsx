/**
 * Multi-series line chart over the same `buckets` array the KPI tiles
 * use. Each present severity is rendered as a polyline; fatal and
 * notice (the two extremes — high-stakes vs high-volume) get a soft
 * gradient area underneath so the eye can group "is the dangerous line
 * climbing" and "what's the baseline of noise doing" without reading
 * the legend. All series share the same y-scale (peak across every
 * severity) so a tall fatal line genuinely is taller than a tall
 * notice line — comparison is meaningful.
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

const SEVERITY_STROKE = {
	fatal: '#e5484d',
	parse: '#f76b15',
	warning: '#f5a524',
	notice: '#3e63dd',
	deprecated: '#8347b9',
	strict: '#29a383',
};

const W = 700;
const H = 220;
const PAD_TOP = 8;
const PAD_BOTTOM = 4;

function formatTick( bucket, granularity ) {
	const ts = Number( bucket?.ts || 0 );
	if ( ! ts ) {
		return '';
	}
	const d = new Date( ts * 1000 );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	if ( granularity === 'hour' ) {
		return d.toLocaleTimeString( undefined, {
			hour: 'numeric',
			hour12: true,
		} );
	}
	return d.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
}

export default function VolumeChart( { buckets, totals, granularity } ) {
	const safe = Array.isArray( buckets ) ? buckets : [];
	if ( safe.length === 0 ) {
		return null;
	}

	const visible = SEVERITY_ORDER.filter(
		( sev ) => Number( totals?.[ sev ] || 0 ) > 0
	);
	if ( visible.length === 0 ) {
		return null;
	}

	let peak = 0;
	for ( const b of safe ) {
		for ( const sev of visible ) {
			const v = Number( b?.[ sev ] || 0 );
			if ( v > peak ) {
				peak = v;
			}
		}
	}

	const step = safe.length > 1 ? W / ( safe.length - 1 ) : W;
	const yFor = ( v ) => {
		if ( peak === 0 ) {
			return H - PAD_BOTTOM;
		}
		return H - PAD_BOTTOM - ( v / peak ) * ( H - PAD_TOP - PAD_BOTTOM );
	};

	const seriesPoints = ( severity ) =>
		safe
			.map( ( b, i ) => {
				const x = i * step;
				const y = yFor( Number( b?.[ severity ] || 0 ) );
				return `${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }`;
			} )
			.join( ' ' );

	const areaPath = ( severity ) => {
		const pts = safe.map( ( b, i ) => {
			const x = i * step;
			const y = yFor( Number( b?.[ severity ] || 0 ) );
			return `${ x.toFixed( 1 ) },${ y.toFixed( 1 ) }`;
		} );
		const last = ( safe.length - 1 ) * step;
		return `M0,${ H } L${ pts.join( ' L' ) } L${ last.toFixed(
			1
		) },${ H } Z`;
	};

	// X-axis ticks: pick up to 7 evenly-spaced bucket labels.
	const tickCount = Math.min( safe.length, 7 );
	const tickIndices = Array.from( { length: tickCount }, ( _, i ) =>
		Math.round( ( i * ( safe.length - 1 ) ) / Math.max( tickCount - 1, 1 ) )
	);

	return (
		<div className="logscope-volume">
			<div className="logscope-volume__head">
				<h3 className="logscope-volume__title">
					{ __( 'Volume over time', 'logscope' ) }
				</h3>
				<ul className="logscope-volume__legend">
					{ visible.map( ( sev ) => {
						const tone = severityTone( sev );
						return (
							<li key={ sev }>
								<span
									className={ `logscope-kpi__dot logscope-kpi__dot--${ tone }` }
									aria-hidden="true"
								/>
								{ severityLabel( sev ) }
							</li>
						);
					} ) }
				</ul>
			</div>
			<div className="logscope-volume__chart">
				<svg
					width="100%"
					height="100%"
					viewBox={ `0 0 ${ W } ${ H }` }
					preserveAspectRatio="none"
					role="img"
					aria-label={ __(
						'Volume of each severity across the selected range',
						'logscope'
					) }
				>
					<defs>
						<linearGradient
							id="logscope-grad-fatal"
							x1="0"
							x2="0"
							y1="0"
							y2="1"
						>
							<stop
								offset="0%"
								stopColor={ SEVERITY_STROKE.fatal }
								stopOpacity="0.55"
							/>
							<stop
								offset="100%"
								stopColor={ SEVERITY_STROKE.fatal }
								stopOpacity="0"
							/>
						</linearGradient>
						<linearGradient
							id="logscope-grad-notice"
							x1="0"
							x2="0"
							y1="0"
							y2="1"
						>
							<stop
								offset="0%"
								stopColor={ SEVERITY_STROKE.notice }
								stopOpacity="0.4"
							/>
							<stop
								offset="100%"
								stopColor={ SEVERITY_STROKE.notice }
								stopOpacity="0"
							/>
						</linearGradient>
					</defs>
					{ /* Faint guide lines at thirds. */ }
					<line
						x1="0"
						y1={ H * 0.25 }
						x2={ W }
						y2={ H * 0.25 }
						stroke="var(--logscope-border-soft)"
						strokeDasharray="2 4"
					/>
					<line
						x1="0"
						y1={ H * 0.5 }
						x2={ W }
						y2={ H * 0.5 }
						stroke="var(--logscope-border-soft)"
						strokeDasharray="2 4"
					/>
					<line
						x1="0"
						y1={ H * 0.75 }
						x2={ W }
						y2={ H * 0.75 }
						stroke="var(--logscope-border-soft)"
						strokeDasharray="2 4"
					/>
					{ visible.includes( 'notice' ) && (
						<path
							d={ areaPath( 'notice' ) }
							fill="url(#logscope-grad-notice)"
						/>
					) }
					{ visible.includes( 'fatal' ) && (
						<path
							d={ areaPath( 'fatal' ) }
							fill="url(#logscope-grad-fatal)"
						/>
					) }
					{ visible.map( ( sev ) => (
						<polyline
							key={ sev }
							points={ seriesPoints( sev ) }
							fill="none"
							stroke={ SEVERITY_STROKE[ sev ] }
							strokeWidth={ sev === 'fatal' ? 2 : 1.6 }
						/>
					) ) }
				</svg>
			</div>
			<div className="logscope-volume__axis">
				{ tickIndices.map( ( idx ) => (
					<span key={ idx }>
						{ formatTick( safe[ idx ], granularity ) }
					</span>
				) ) }
			</div>
		</div>
	);
}
