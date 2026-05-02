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
import { useState } from '@wordpress/element';

import { STORE_KEY } from '../../store';
import { severityLabel, severityTone } from '../../utils/severity';
import entryKey from '../../utils/entryKey';
import StackTracePanel from '../StackTracePanel';
import RowActionsMenu from './RowActionsMenu';

export { entryKey };

export const ROW_HEIGHT_BASE = 62;
export const ROW_HEIGHT_FRAME = 26;
export const ROW_HEIGHT_FRAME_PADDING = 24;
export const ROW_HEIGHT_DETAILS = 90;

export function rowHeightFor( entry, isExpanded ) {
	if ( ! entry || ! isExpanded ) {
		return ROW_HEIGHT_BASE;
	}
	const frames = Array.isArray( entry.frames ) ? entry.frames : [];
	if ( frames.length === 0 ) {
		return ROW_HEIGHT_BASE + ROW_HEIGHT_DETAILS;
	}
	return (
		ROW_HEIGHT_BASE +
		ROW_HEIGHT_DETAILS +
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
	const { isExpanded, isSelected } = useSelect(
		( select ) => {
			const store = select( STORE_KEY );
			return {
				isExpanded: store.isTraceExpanded( key ),
				isSelected: store.isEntrySelected( key ),
			};
		},
		[ key ]
	);
	const { toggleTraceExpanded, toggleEntrySelected } =
		useDispatch( STORE_KEY );
	const [ menuPosition, setMenuPosition ] = useState( null );

	if ( ! entry ) {
		return <div style={ style } aria-hidden="true" />;
	}

	const tone = severityTone( entry.severity );
	const label = severityLabel( entry.severity );
	const frames = Array.isArray( entry.frames ) ? entry.frames : [];
	const hasTrace = frames.length > 0;
	const path = pathLabel( entry );

	const onRowClick = ( e ) => {
		// Ignore clicks on interactive children (checkbox, toggle button, links).
		const t = e.target;
		if (
			t.closest(
				'.logscope-entry__checkbox, .logscope-entry__toggle, .logscope-entry__more, .logscope-row-menu, a, button, input'
			)
		) {
			return;
		}
		toggleTraceExpanded( key );
	};

	const onRowContextMenu = ( e ) => {
		// Open the same menu the ⋮ trigger opens, anchored at the
		// cursor. Suppressing the native menu is the cost of giving
		// users the in-app actions; if power users want the browser
		// menu they can shift-right-click in most browsers.
		e.preventDefault();
		setMenuPosition( { x: e.clientX, y: e.clientY } );
	};

	const onMoreClick = ( e ) => {
		e.stopPropagation();
		const rect = e.currentTarget.getBoundingClientRect();
		setMenuPosition( { x: rect.left, y: rect.bottom + 4 } );
	};

	return (
		<div
			className={ `logscope-entry logscope-entry--${ tone }${
				isExpanded ? ' logscope-entry--expanded' : ''
			}${ isSelected ? ' logscope-entry--selected' : '' }` }
			style={ style }
			role="listitem"
			onClick={ onRowClick }
			onContextMenu={ onRowContextMenu }
		>
			<div className="logscope-entry__head">
				<input
					type="checkbox"
					className="logscope-entry__checkbox"
					checked={ isSelected }
					onChange={ () => toggleEntrySelected( key ) }
					onClick={ ( e ) => e.stopPropagation() }
					aria-label={ __( 'Select this log entry', 'logscope' ) }
				/>
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
				<button
					type="button"
					className="logscope-entry__more"
					aria-haspopup="menu"
					aria-expanded={ menuPosition !== null }
					aria-label={ __( 'Row actions', 'logscope' ) }
					title={ __( 'Row actions (right-click row)', 'logscope' ) }
					onClick={ onMoreClick }
				>
					⋮
				</button>
			</div>
			{ isExpanded && (
				<div className="logscope-entry__details">
					<div className="logscope-entry__details-message">
						{ entry.message || '' }
					</div>
					{ path && (
						<div className="logscope-entry__details-path">
							{ path }
						</div>
					) }
				</div>
			) }
			{ isExpanded && hasTrace && <StackTracePanel frames={ frames } /> }
			{ menuPosition && (
				<RowActionsMenu
					entry={ entry }
					position={ menuPosition }
					onClose={ () => setMenuPosition( null ) }
				/>
			) }
		</div>
	);
}
