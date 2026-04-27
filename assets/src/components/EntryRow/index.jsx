/**
 * Single row in the virtualized log viewer. The row receives the index
 * + an inline style from react-window (which absolutely-positions the
 * row with a fixed height) plus the shared `items` array via rowProps.
 *
 * Expansion state lives in the store (`expandedTraces[entryKey]`) so it
 * survives react-window's row recycling on scroll. The row reads its
 * own expansion state directly from the store rather than via rowProps,
 * which keeps rowProps shallow-stable and lets react-window skip
 * unrelated re-renders.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch, useSelect } from '@wordpress/data';

import { STORE_KEY } from '../../store';
import { severityLabel, severityTone } from '../../utils/severity';
import entryKey from '../../utils/entryKey';
import StackTracePanel from '../StackTracePanel';

export { entryKey };

export const ROW_HEIGHT_BASE = 48;
export const ROW_HEIGHT_FRAME = 28;
export const ROW_HEIGHT_FRAME_PADDING = 16;

export function rowHeightFor( entry, isExpanded ) {
	if ( ! entry ) {
		return ROW_HEIGHT_BASE;
	}
	const frames = Array.isArray( entry.frames ) ? entry.frames : [];
	if ( ! isExpanded || frames.length === 0 ) {
		return ROW_HEIGHT_BASE;
	}
	return (
		ROW_HEIGHT_BASE +
		frames.length * ROW_HEIGHT_FRAME +
		ROW_HEIGHT_FRAME_PADDING
	);
}

export default function EntryRow( { index, style, items } ) {
	const entry = items[ index ];
	const key = entryKey( entry );
	const isExpanded = useSelect(
		( select ) => select( STORE_KEY ).isTraceExpanded( key ),
		[ key ]
	);
	const { toggleTraceExpanded } = useDispatch( STORE_KEY );

	if ( ! entry ) {
		return <div style={ style } aria-hidden="true" />;
	}

	const tone = severityTone( entry.severity );
	const label = severityLabel( entry.severity );
	const frames = Array.isArray( entry.frames ) ? entry.frames : [];
	const hasTrace = frames.length > 0;

	return (
		<div
			className={ `logscope-entry logscope-entry--${ tone }${
				isExpanded ? ' logscope-entry--expanded' : ''
			}` }
			style={ style }
			role="listitem"
		>
			<div className="logscope-entry__head">
				<span
					className={ `logscope-pill logscope-pill--${ tone }` }
					aria-label={ label }
				>
					{ label }
				</span>
				<time
					className="logscope-entry__timestamp"
					dateTime={ entry.timestamp || '' }
				>
					{ entry.timestamp || '' }
				</time>
				<span className="logscope-entry__message">
					{ entry.message || '' }
				</span>
				{ hasTrace && (
					<button
						type="button"
						className="logscope-entry__toggle"
						aria-expanded={ isExpanded }
						aria-label={
							isExpanded
								? __( 'Hide stack trace', 'logscope' )
								: __( 'Show stack trace', 'logscope' )
						}
						onClick={ () => toggleTraceExpanded( key ) }
					>
						{ isExpanded ? '▾' : '⋯' }
					</button>
				) }
			</div>
			{ isExpanded && hasTrace && <StackTracePanel frames={ frames } /> }
		</div>
	);
}
