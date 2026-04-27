/**
 * Logs-tab body. Owns the filter bar, the list/grouped mode toggle, and
 * the data-fetch effect that ties them together. The store is the single
 * source of truth for filters + view mode; this component only
 * translates store state into REST query params and back into a body
 * component.
 *
 * react-window v2's `<List>` has no public scroll-offset getter, but its
 * imperative API exposes the underlying scroll container as `element`.
 * We capture `scrollTop` on each render-frame's scroll event and restore
 * it on the next mount, so toggling list ↔ grouped preserves where the
 * user was reading (Phase 7.2 AC).
 */
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { List, useListRef } from 'react-window';

import { STORE_KEY } from '../../store';
import EntryRow from '../EntryRow';
import EmptyState from '../EmptyState';
import FilterBar from '../FilterBar';
import GroupedView from '../GroupedView';
import useUrlQuerySync from '../../hooks/useUrlQuerySync';

const ROW_HEIGHT = 48;
const LIST_HEIGHT = 600;

function buildQueryParams( filters, viewMode ) {
	const params = { page: 1 };
	if ( viewMode === 'grouped' ) {
		params.grouped = true;
	}
	if ( filters.severity.length > 0 ) {
		params.severity = filters.severity.join( ',' );
	}
	[ 'from', 'to', 'q', 'source' ].forEach( ( key ) => {
		if ( filters[ key ] ) {
			params[ key ] = filters[ key ];
		}
	} );
	return params;
}

export default function LogViewer() {
	const { items, isLoading, error, viewMode, filters } = useSelect(
		( select ) => {
			const store = select( STORE_KEY );
			return {
				items: store.getLogs(),
				isLoading: store.isLoadingLogs(),
				error: store.getLogsError(),
				viewMode: store.getViewMode(),
				filters: store.getFilters(),
			};
		},
		[]
	);
	const { fetchLogs, setViewMode } = useDispatch( STORE_KEY );

	useUrlQuerySync( viewMode, filters );

	useEffect( () => {
		fetchLogs( buildQueryParams( filters, viewMode ) );
	}, [ fetchLogs, viewMode, filters ] );

	const handleSetMode = ( mode ) => {
		if ( mode !== viewMode ) {
			setViewMode( mode );
		}
	};

	return (
		<div className="logscope-logs">
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
			</div>

			<ViewerBody
				items={ items }
				isLoading={ isLoading }
				error={ error }
				viewMode={ viewMode }
			/>
		</div>
	);
}

function ViewerBody( { items, isLoading, error, viewMode } ) {
	if ( isLoading && items.length === 0 ) {
		return (
			<div className="logscope-viewer logscope-viewer--loading">
				<Spinner />
			</div>
		);
	}

	if ( error && items.length === 0 ) {
		return <EmptyState error={ error } />;
	}

	if ( items.length === 0 ) {
		return <EmptyState />;
	}

	if ( viewMode === 'grouped' ) {
		return <GroupedScrollPane />;
	}

	return <ListScrollPane items={ items } isLoading={ isLoading } />;
}

function ListScrollPane( { items, isLoading } ) {
	const listRef = useListRef( null );
	const savedOffset = useSelect(
		( select ) => select( STORE_KEY ).getScrollOffset( 'list' ),
		[]
	);
	const { setScrollOffset } = useDispatch( STORE_KEY );

	useEffect( () => {
		const element = listRef.current?.element;
		if ( ! element ) {
			return undefined;
		}
		element.scrollTop = savedOffset;
		const onScroll = () => setScrollOffset( 'list', element.scrollTop );
		element.addEventListener( 'scroll', onScroll, { passive: true } );
		return () => {
			element.removeEventListener( 'scroll', onScroll );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<div
			className="logscope-viewer"
			role="list"
			aria-busy={ isLoading ? 'true' : 'false' }
		>
			<List
				className="logscope-viewer__list"
				listRef={ listRef }
				rowCount={ items.length }
				rowHeight={ ROW_HEIGHT }
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
