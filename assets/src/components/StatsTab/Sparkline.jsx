/**
 * Hand-rolled SVG sparkline. Pure presentational — no interactivity, no
 * animation, no chart-library overhead.
 *
 * The SVG itself is `aria-hidden` because the bar shapes carry no
 * semantic content a screen reader could usefully voice; the parent
 * wrapper owns the aria-label and announces peak / mean / total over
 * the range, which is the information density a sighted user gets
 * from glancing at the bars.
 */
const VIEW_W = 120;
const VIEW_H = 32;
const BAR_GAP = 1;
const MIN_BAR_HEIGHT = 1; // Keep a non-zero rectangle when the value is positive but rounds to 0px.

export default function Sparkline( { values, tone } ) {
	const safe = Array.isArray( values ) ? values : [];
	const peak = safe.reduce( ( m, v ) => ( v > m ? v : m ), 0 );
	const barCount = Math.max( safe.length, 1 );
	const barWidth = ( VIEW_W - BAR_GAP * ( barCount - 1 ) ) / barCount;

	return (
		<svg
			className={ `logscope-sparkline logscope-sparkline--${
				tone || 'unknown'
			}` }
			viewBox={ `0 0 ${ VIEW_W } ${ VIEW_H }` }
			preserveAspectRatio="none"
			role="presentation"
			aria-hidden="true"
			focusable="false"
		>
			{ safe.map( ( value, index ) => {
				const ratio = peak > 0 ? value / peak : 0;
				const h =
					value > 0 ? Math.max( MIN_BAR_HEIGHT, ratio * VIEW_H ) : 0;
				const x = index * ( barWidth + BAR_GAP );
				const y = VIEW_H - h;
				return (
					<rect
						// eslint-disable-next-line react/no-array-index-key
						key={ index }
						x={ x }
						y={ y }
						width={ barWidth }
						height={ h }
					/>
				);
			} ) }
		</svg>
	);
}
