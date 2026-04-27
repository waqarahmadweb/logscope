/**
 * Logscope @wordpress/data store, registered as `logscope/core`.
 *
 * Slices:
 *   - activeTab: which top-level tab is rendered (Logs / Settings).
 *   - viewMode: 'list' or 'grouped' inside the Logs tab.
 *   - filters: severity[], from, to, q (regex), source — mirrored into
 *     the URL query string by useUrlQuerySync and forwarded to the REST
 *     query by LogViewer's fetch effect.
 *   - logs: paginated payload from `GET /logs`. The same slot holds
 *     entries (list mode) or groups (grouped mode); the consumer decides
 *     by reading viewMode, since the wire shape of an item differs.
 *   - expandedGroups: signature → bool, owned at the store level so it
 *     survives react-window row recycling.
 *   - scrollOffsets: per-mode scroll positions so toggling between list
 *     and grouped restores the previous offset (Phase 7.2 AC).
 */
import { createReduxStore, register } from '@wordpress/data';

import { client } from '../api/client';
import { readInitialQueryState } from '../hooks/useUrlQuerySync';

export const STORE_KEY = 'logscope/core';

const DEFAULT_FILTERS = {
	severity: [],
	from: '',
	to: '',
	q: '',
	source: '',
};

const initialQuery = readInitialQueryState();

const DEFAULT_STATE = {
	activeTab: 'logs',
	viewMode: initialQuery?.viewMode || 'list',
	filters: { ...DEFAULT_FILTERS, ...( initialQuery?.filters || {} ) },
	expandedGroups: {},
	scrollOffsets: { list: 0, grouped: 0 },
	logs: {
		items: [],
		total: 0,
		page: 1,
		perPage: 50,
		isLoading: false,
		error: null,
	},
};

const actions = {
	setActiveTab( tab ) {
		return { type: 'SET_ACTIVE_TAB', tab };
	},
	setViewMode( mode ) {
		return { type: 'SET_VIEW_MODE', mode };
	},
	setFilters( partial ) {
		return { type: 'SET_FILTERS', partial };
	},
	resetFilters() {
		return { type: 'RESET_FILTERS' };
	},
	toggleGroupExpanded( signature ) {
		return { type: 'TOGGLE_GROUP_EXPANDED', signature };
	},
	setScrollOffset( mode, offset ) {
		return { type: 'SET_SCROLL_OFFSET', mode, offset };
	},
	startLoadingLogs() {
		return { type: 'LOGS_LOADING' };
	},
	receiveLogs( payload ) {
		return { type: 'LOGS_RECEIVED', payload };
	},
	failLogs( error ) {
		return { type: 'LOGS_FAILED', error };
	},
	*fetchLogs( params = {} ) {
		yield actions.startLoadingLogs();
		try {
			const response = yield {
				type: 'API_FETCH_LOGS',
				params,
			};
			yield actions.receiveLogs( response );
		} catch ( error ) {
			yield actions.failLogs( error?.message || 'Unknown error' );
		}
	},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'SET_ACTIVE_TAB':
			return { ...state, activeTab: action.tab };
		case 'SET_VIEW_MODE':
			if ( state.viewMode === action.mode ) {
				return state;
			}
			return { ...state, viewMode: action.mode };
		case 'SET_FILTERS':
			return {
				...state,
				filters: { ...state.filters, ...action.partial },
			};
		case 'RESET_FILTERS':
			return { ...state, filters: { ...DEFAULT_FILTERS } };
		case 'TOGGLE_GROUP_EXPANDED': {
			const next = { ...state.expandedGroups };
			if ( next[ action.signature ] ) {
				delete next[ action.signature ];
			} else {
				next[ action.signature ] = true;
			}
			return { ...state, expandedGroups: next };
		}
		case 'SET_SCROLL_OFFSET':
			return {
				...state,
				scrollOffsets: {
					...state.scrollOffsets,
					[ action.mode ]: action.offset,
				},
			};
		case 'LOGS_LOADING':
			return {
				...state,
				logs: { ...state.logs, isLoading: true, error: null },
			};
		case 'LOGS_RECEIVED':
			return {
				...state,
				logs: {
					...state.logs,
					isLoading: false,
					items: action.payload.items || [],
					total: action.payload.total || 0,
					page: action.payload.page || 1,
					perPage: action.payload.per_page || state.logs.perPage,
				},
			};
		case 'LOGS_FAILED':
			return {
				...state,
				logs: { ...state.logs, isLoading: false, error: action.error },
			};
		default:
			return state;
	}
};

const selectors = {
	getActiveTab: ( state ) => state.activeTab,
	getViewMode: ( state ) => state.viewMode,
	getFilters: ( state ) => state.filters,
	isGroupExpanded: ( state, signature ) =>
		Boolean( state.expandedGroups[ signature ] ),
	getScrollOffset: ( state, mode ) => state.scrollOffsets[ mode ] || 0,
	getLogs: ( state ) => state.logs.items,
	getLogsTotal: ( state ) => state.logs.total,
	isLoadingLogs: ( state ) => state.logs.isLoading,
	getLogsError: ( state ) => state.logs.error,
};

const controls = {
	API_FETCH_LOGS( { params } ) {
		return client.getLogs( params );
	},
};

const store = createReduxStore( STORE_KEY, {
	reducer,
	actions,
	selectors,
	controls,
} );

register( store );

export default store;
