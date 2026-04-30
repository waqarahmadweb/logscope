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
import { useCallback, useEffect, useRef } from '@wordpress/element';
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
	const { items, isLoading, error, viewMode, filters, isTailing } = useSelect(
		( select ) => {
			const store = select( STORE_KEY );
			return {
				items: store.getLogs(),
				isLoading: store.isLoadingLogs(),
				error: store.getLogsError(),
				viewMode: store.getViewMode(),
				filters: store.getFilters(),
				isTailing: store.isTailActive(),
			};
		},
		[]
	);
	const { fetchLogs, fetchDiagnostics, setViewMode, setTailActive } =
		useDispatch( STORE_KEY );

	useUrlQuerySync( viewMode, filters );

	useEffect( () => {
		fetchLogs( buildQueryParams( filters, viewMode ) );
	}, [ fetchLogs, viewMode, filters ] );

	// Diagnostics powers the onboarding banner and the upcoming
	// reason-aware empty state. Fetched once on mount — the snapshot
	// is cheap server-side and the host's debug-flag state does not
	// change without a wp-config edit + a tab reload.
	useEffect( () => {
		fetchDiagnostics();
	}, [ fetchDiagnostics ] );

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

			<div
				className="logscope-mode-toggle"
				role="tablist"
				aria-label={ __( 'View mode', 'logscope' ) }
			>
				<Button
					variant={ viewMode === 'list' ? 'primary' : 'secondary' }
					role="tab"
					aria-selected={ viewMode === 'list' }
					onClick={ () => handleSetMode( 'list' ) }
				>
					{ __( 'List', 'logscope' ) }
				</Button>
				<Button
					variant={ viewMode === 'grouped' ? 'primary' : 'secondary' }
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

			<ViewerBody
				items={ items }
				isLoading={ isLoading }
				error={ error }
				viewMode={ viewMode }
				filters={ filters }
			/>
		</div>
	);
}

function ViewerBody( { items, isLoading, error, viewMode, filters } ) {
	if ( isLoading && items.length === 0 ) {
		return <ListSkeleton />;
	}

	if ( error && items.length === 0 ) {
		return <EmptyState error={ error } />;
	}

	if ( items.length === 0 ) {
		// `filtersActive` flips the copy from "log is empty" to "your
		// filters excluded everything" so a user staring at an empty list
		// after a typo in the regex sees an actionable hint.
		const filtersActive = !! (
			filters?.q ||
			filters?.from ||
			filters?.to ||
			filters?.source ||
			( filters?.severity && filters.severity.length > 0 )
		);
		return <EmptyState filtersActive={ filtersActive } />;
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
