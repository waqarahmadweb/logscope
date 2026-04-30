/**
 * Logs-tab body. Owns the filter bar, the list/grouped/tail toggles,
 * and the data-fetch effect that ties them together.
 *
 * react-window v2's `<List>` exposes the scroll container via the
 * imperative `element` field on `useListRef`; we capture `scrollTop`
 * on each scroll and restore it across mode toggles (Phase 7.2 AC) and
 * use the same handle for the "scroll to top" affordance on the tail
 * "N new" pill (Phase 7.4). Row heights are dynamic — collapsed rows
 * are 48px and expanded rows grow to fit their stack-trace panel — and
 * the rowHeight function closes over the store's `expandedTraces` map
 * so toggling expansion forces a measure pass.
 */
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { List, useListRef } from 'react-window';

import { STORE_KEY } from '../../store';
import EntryRow, { entryKey, rowHeightFor, ROW_HEIGHT_BASE } from '../EntryRow';
import EmptyState from '../EmptyState';
import FilterBar from '../FilterBar';
import GroupedView from '../GroupedView';
import OnboardingBanner from '../OnboardingBanner';
import { ListSkeleton } from '../Skeleton';
import { SHORTCUT, SHORTCUT_EVENT } from '../../shortcuts';
import useUrlQuerySync from '../../hooks/useUrlQuerySync';
import useTailPolling from '../../hooks/useTailPolling';
import buildFilterParams from '../../utils/filterParams';

const LIST_HEIGHT = 600;

function buildQueryParams( filters, viewMode ) {
	const params = { page: 1, ...buildFilterParams( filters ) };
	if ( viewMode === 'grouped' ) {
		params.grouped = true;
	}
	return params;
}

export default function LogViewer() {
	const {
		items,
		isLoading,
		error,
		viewMode,
		filters,
		isTailing,
		diagnostics,
		muteCount,
		selectedKeys,
		selectedCount,
	} = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			items: store.getLogs(),
			isLoading: store.isLoadingLogs(),
			error: store.getLogsError(),
			viewMode: store.getViewMode(),
			filters: store.getFilters(),
			isTailing: store.isTailActive(),
			diagnostics: store.getDiagnostics(),
			muteCount: store.getMutes().length,
			selectedKeys: store.getSelectedEntryKeys(),
			selectedCount: store.getSelectedEntryCount(),
		};
	}, [] );
	const {
		fetchLogs,
		fetchDiagnostics,
		fetchMutes,
		setViewMode,
		setTailActive,
		clearEntrySelection,
		pushToast,
	} = useDispatch( STORE_KEY );

	// Per-entry bulk actions. Mute-by-entry is intentionally out of
	// scope — the mute domain is per-signature (grouped view), not
	// per-entry. Copy paths grabs `file:line` strings from the
	// selected rows and writes them newline-joined to the clipboard.
	// Export builds a CSV mirroring the grouped-view export shape so
	// downstream tooling does not have to special-case list output.
	const selectedEntries = useMemo( () => {
		if ( selectedCount === 0 ) {
			return [];
		}
		const set = new Set( selectedKeys );
		return items.filter( ( entry ) => set.has( entryKey( entry ) ) );
	}, [ items, selectedKeys, selectedCount ] );

	const onCopyPaths = async () => {
		if ( selectedCount === 0 || ! navigator?.clipboard ) {
			return;
		}
		const lines = selectedEntries
			.map( ( e ) =>
				e.file && e.line ? `${ e.file }:${ e.line }` : e.file || ''
			)
			.filter( Boolean );
		try {
			await navigator.clipboard.writeText( lines.join( '\n' ) );
			pushToast( {
				message: sprintf(
					/* translators: %d is the number of file paths copied. */
					_n(
						'Copied %d path to clipboard.',
						'Copied %d paths to clipboard.',
						lines.length,
						'logscope'
					),
					lines.length
				),
				status: 'success',
			} );
		} catch ( e ) {
			pushToast( {
				message: __( 'Could not copy to clipboard.', 'logscope' ),
				status: 'error',
			} );
		}
	};

	const onExportSelected = () => {
		if ( selectedCount === 0 ) {
			return;
		}
		downloadEntriesCsv( selectedEntries );
	};

	useUrlQuerySync( viewMode, filters );

	useEffect( () => {
		fetchLogs( buildQueryParams( filters, viewMode ) );
	}, [ fetchLogs, viewMode, filters ] );

	// Diagnostics powers the onboarding banner and the reason-aware
	// empty state. Fetched once on mount — the snapshot is cheap
	// server-side and the host's debug-flag state does not change
	// without a wp-config edit + a tab reload.
	useEffect( () => {
		fetchDiagnostics();
	}, [ fetchDiagnostics ] );

	// Mute list feeds the "all recent entries are muted" branch of
	// EmptyState. Settings-tab `MutedSignaturesPanel` also fetches it,
	// but a fresh tab load on Logs needs the count too — and the
	// thunk is idempotent enough that calling it from both places is
	// fine (the panel will just see the already-populated list).
	useEffect( () => {
		fetchMutes();
	}, [ fetchMutes ] );

	// Subscribe to global keyboard shortcut events from App. Toggle handlers
	// live in this component because they own the state setters; the focus-
	// search event is handled inside FilterBar where the input ref lives.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return undefined;
		}
		const handler = ( event ) => {
			if ( event.detail === SHORTCUT.TOGGLE_GROUPED ) {
				setViewMode( viewMode === 'grouped' ? 'list' : 'grouped' );
				return;
			}
			if ( event.detail === SHORTCUT.TOGGLE_TAIL ) {
				if ( ! isTailing && viewMode === 'grouped' ) {
					setViewMode( 'list' );
				}
				setTailActive( ! isTailing );
			}
		};
		window.addEventListener( SHORTCUT_EVENT, handler );
		return () => window.removeEventListener( SHORTCUT_EVENT, handler );
	}, [ viewMode, isTailing, setViewMode, setTailActive ] );

	const handleSetMode = ( mode ) => {
		if ( mode !== viewMode ) {
			setViewMode( mode );
		}
	};

	const handleToggleTail = () => {
		// Tail polling appends raw entries — switch out of grouped
		// mode on activation so the appended rows have somewhere to
		// land. The reverse case (mode flipped to grouped while tail
		// is active) is handled in the SET_VIEW_MODE reducer, which
		// auto-stops tail rather than leaving the loop running
		// invisibly.
		if ( ! isTailing && viewMode === 'grouped' ) {
			setViewMode( 'list' );
		}
		setTailActive( ! isTailing );
	};

	return (
		<div className="logscope-logs">
			<OnboardingBanner />
			<FilterBar />

			<div className="logscope-toolbar">
				<div
					className="logscope-mode-toggle"
					role="tablist"
					aria-label={ __( 'View mode', 'logscope' ) }
				>
					<Button
						variant={
							viewMode === 'list' ? 'primary' : 'secondary'
						}
						role="tab"
						aria-selected={ viewMode === 'list' }
						onClick={ () => handleSetMode( 'list' ) }
					>
						{ __( 'List', 'logscope' ) }
					</Button>
					<Button
						variant={
							viewMode === 'grouped' ? 'primary' : 'secondary'
						}
						role="tab"
						aria-selected={ viewMode === 'grouped' }
						onClick={ () => handleSetMode( 'grouped' ) }
					>
						{ __( 'Grouped', 'logscope' ) }
					</Button>
					<Button
						variant={ isTailing ? 'primary' : 'tertiary' }
						onClick={ handleToggleTail }
						aria-pressed={ isTailing }
					>
						{ isTailing
							? __( 'Stop tail', 'logscope' )
							: __( 'Tail', 'logscope' ) }
					</Button>
				</div>
				<div className="logscope-toolbar__bulk">
					<button
						type="button"
						className="logscope-toolbar__bulk-btn"
						disabled={ selectedCount === 0 }
						title={ __(
							'Mute applies per signature — switch to Grouped view to mute by signature',
							'logscope'
						) }
						onClick={ () => {
							if ( selectedCount === 0 ) {
								return;
							}
							setViewMode( 'grouped' );
							pushToast( {
								message: __(
									'Switched to Grouped view — select the matching signature(s) and use the Mute action there.',
									'logscope'
								),
								status: 'info',
							} );
						} }
					>
						{ selectedCount > 0
							? sprintf(
									/* translators: %d is the number of selected entries. */
									_n(
										'Mute (%d)',
										'Mute (%d)',
										selectedCount,
										'logscope'
									),
									selectedCount
							  )
							: __( 'Mute selected', 'logscope' ) }
					</button>
					<button
						type="button"
						className="logscope-toolbar__bulk-btn logscope-toolbar__bulk-btn--primary"
						disabled={ selectedCount === 0 }
						onClick={ onExportSelected }
					>
						{ selectedCount > 0
							? sprintf(
									/* translators: %d is the number of selected entries. */
									_n(
										'Export (%d)',
										'Export (%d)',
										selectedCount,
										'logscope'
									),
									selectedCount
							  )
							: __( 'Export selected', 'logscope' ) }
					</button>
				</div>
			</div>

			{ selectedCount > 0 && (
				<div
					className="logscope-bulk-bar"
					role="region"
					aria-label={ __( 'Bulk actions', 'logscope' ) }
				>
					<span className="logscope-bulk-bar__count">
						<span
							className="logscope-bulk-bar__check"
							aria-hidden="true"
						/>
						<strong>{ selectedCount }</strong>{ ' ' }
						{ _n(
							'selected',
							'selected',
							selectedCount,
							'logscope'
						) }
					</span>
					<span className="logscope-bulk-bar__sep" aria-hidden="true">
						·
					</span>
					<button
						type="button"
						className="logscope-bulk-bar__btn"
						onClick={ onCopyPaths }
					>
						📋 { __( 'Copy paths', 'logscope' ) }
					</button>
					<button
						type="button"
						className="logscope-bulk-bar__btn"
						onClick={ onExportSelected }
					>
						⤓ { __( 'Export', 'logscope' ) }
					</button>
					<span style={ { flex: 1 } } />
					<button
						type="button"
						className="logscope-bulk-bar__cancel"
						onClick={ () => clearEntrySelection() }
					>
						{ __( 'Clear selection', 'logscope' ) }
					</button>
				</div>
			) }

			<ViewerBody
				items={ items }
				isLoading={ isLoading }
				error={ error }
				viewMode={ viewMode }
				filters={ filters }
				diagnostics={ diagnostics }
				muteCount={ muteCount }
			/>
		</div>
	);
}

function ViewerBody( {
	items,
	isLoading,
	error,
	viewMode,
	filters,
	diagnostics,
	muteCount,
} ) {
	if ( isLoading && items.length === 0 ) {
		return <ListSkeleton />;
	}

	if ( error && items.length === 0 ) {
		return <EmptyState error={ error } />;
	}

	if ( items.length === 0 ) {
		// `filtersActive` flips the copy from "log is empty" to "your
		// filters excluded everything" so a user staring at an empty list
		// after a typo in the regex sees an actionable hint. The
		// diagnostics snapshot + mute count refine the no-filters branch
		// further (file missing, file empty, all-muted) — see EmptyState.
		const filtersActive = !! (
			filters?.q ||
			filters?.from ||
			filters?.to ||
			filters?.source ||
			( filters?.severity && filters.severity.length > 0 )
		);
		return (
			<EmptyState
				filtersActive={ filtersActive }
				diagnostics={ diagnostics }
				muteCount={ muteCount }
			/>
		);
	}

	if ( viewMode === 'grouped' ) {
		return <GroupedScrollPane />;
	}

	return <ListScrollPane items={ items } isLoading={ isLoading } />;
}

function ListScrollPane( { items, isLoading } ) {
	const listRef = useListRef( null );
	const scrollElementRef = useRef( null );
	const { savedOffset, expandedTraces, newCount } = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			savedOffset: store.getScrollOffset( 'list' ),
			expandedTraces: store.getExpandedTraces(),
			newCount: store.getTailNewCount(),
		};
	}, [] );
	const { setScrollOffset, clearTailNewCount } = useDispatch( STORE_KEY );

	useEffect( () => {
		const element = listRef.current?.element;
		if ( ! element ) {
			return undefined;
		}
		scrollElementRef.current = element;
		element.scrollTop = savedOffset;
		const onScroll = () => setScrollOffset( 'list', element.scrollTop );
		element.addEventListener( 'scroll', onScroll, { passive: true } );
		return () => {
			element.removeEventListener( 'scroll', onScroll );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useTailPolling( scrollElementRef );

	// rowHeight closes over expandedTraces; recreating the function on
	// every change of that map is what tells react-window to re-measure.
	const rowHeight = useCallback(
		( index ) => {
			const entry = items[ index ];
			if ( ! entry ) {
				return ROW_HEIGHT_BASE;
			}
			return rowHeightFor(
				entry,
				!! expandedTraces[ entryKey( entry ) ]
			);
		},
		[ items, expandedTraces ]
	);

	const handleNewPill = () => {
		const el = scrollElementRef.current;
		if ( el ) {
			el.scrollTop = 0;
		}
		clearTailNewCount();
	};

	return (
		<div
			className="logscope-viewer"
			role="list"
			aria-busy={ isLoading ? 'true' : 'false' }
		>
			{ newCount > 0 && (
				<button
					type="button"
					className="logscope-viewer__new-pill"
					onClick={ handleNewPill }
					aria-live="polite"
				>
					{ sprintf(
						/* translators: %d is the number of new log entries since the user scrolled away. */
						_n(
							'%d new entry',
							'%d new entries',
							newCount,
							'logscope'
						),
						newCount
					) }
				</button>
			) }
			<List
				className="logscope-viewer__list"
				listRef={ listRef }
				rowCount={ items.length }
				rowHeight={ rowHeight }
				rowComponent={ EntryRow }
				rowProps={ { items } }
				style={ { height: LIST_HEIGHT } }
			/>
		</div>
	);
}

function GroupedScrollPane() {
	const containerRef = useRef( null );
	const savedOffset = useSelect(
		( select ) => select( STORE_KEY ).getScrollOffset( 'grouped' ),
		[]
	);
	const { setScrollOffset } = useDispatch( STORE_KEY );

	useEffect( () => {
		const element = containerRef.current;
		if ( ! element ) {
			return undefined;
		}
		element.scrollTop = savedOffset;
		const onScroll = () => setScrollOffset( 'grouped', element.scrollTop );
		element.addEventListener( 'scroll', onScroll, { passive: true } );
		return () => {
			element.removeEventListener( 'scroll', onScroll );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<div
			ref={ containerRef }
			className="logscope-viewer logscope-viewer--grouped"
			style={ { height: LIST_HEIGHT, overflow: 'auto' } }
		>
			<GroupedView />
		</div>
	);
}

/**
 * Builds a CSV blob from selected log entries and triggers a browser
 * download. Mirrors the grouped-view export shape (severity, file,
 * line, message, raw, timestamp) so downstream tools that already
 * parse the grouped CSV need only minimal column-mapping changes.
 *
 * @param {Array<object>} entries Selected entry payloads.
 */
function downloadEntriesCsv( entries ) {
	const header = [
		'timestamp',
		'severity',
		'file',
		'line',
		'message',
		'raw',
	];
	const lines = [ header.join( ',' ) ];
	entries.forEach( ( entry ) => {
		lines.push(
			[
				csvCell( entry.timestamp ),
				csvCell( entry.severity ),
				csvCell( entry.file ),
				csvCell( entry.line ),
				csvCell( entry.message ),
				csvCell( entry.raw ),
			].join( ',' )
		);
	} );
	const csv = lines.join( '\r\n' ) + '\r\n';

	const blob = new Blob( [ '﻿', csv ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = `logscope-entries-${ timestampForFilename() }.csv`;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

function csvCell( value ) {
	if ( value === undefined || value === null ) {
		return '';
	}
	const str = String( value );
	if ( /[",\r\n]/.test( str ) ) {
		return '"' + str.replace( /"/g, '""' ) + '"';
	}
	return str;
}

function timestampForFilename() {
	const now = new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return (
		now.getFullYear() +
		pad( now.getMonth() + 1 ) +
		pad( now.getDate() ) +
		'-' +
		pad( now.getHours() ) +
		pad( now.getMinutes() ) +
		pad( now.getSeconds() )
	);
}
