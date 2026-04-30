/**
 * Single row in the virtualized log viewer. The row receives the index
 * + an inline style from react-window (which absolutely-positions the
 * row with a fixed height) plus the shared `items` array via rowProps.
 *
 * Visually a row is a single-line table layout: severity badge,
 * timestamp (mono), message (mono, flex), file:line (mono, right-
 * aligned), and a hover-revealed `⋯` expand affordance. A 3px left
 * edge bar carries the severity color so the page can be skimmed by
 * peripheral vision without parsing each badge. When expanded the
 * StackTracePanel renders inline below the head row.
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

export const ROW_HEIGHT_BASE = 44;
export const ROW_HEIGHT_FRAME = 26;
export const ROW_HEIGHT_FRAME_PADDING = 24;

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

function pathLabel( entry ) {
	if ( ! entry?.file ) {
		return '';
	}
	return entry.line ? entry.file + ':' + entry.line : entry.file;
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
	const path = pathLabel( entry );

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
					<span
						className={
							'logscope-pill__dot logscope-pill__dot--' + tone
						}
						aria-hidden="true"
					/>
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
				{ path && (
					<span className="logscope-entry__path" title={ path }>
						{ path }
					</span>
				) }
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
