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
 *   - expandedTraces: entryKey → bool, same rationale (Phase 7.3).
 *   - scrollOffsets: per-mode scroll positions so toggling between list
 *     and grouped restores the previous offset (Phase 7.2 AC).
 *   - tail: { active, lastByte, newCount } — Phase 7.4 polling state.
 *     `lastByte` mirrors the response's `last_byte` so the next poll
 *     asks for entries strictly after it; `newCount` is shown in the
 *     "N new" pill when the user has scrolled away from the bottom.
 */
import { createReduxStore, register } from '@wordpress/data';

import { client } from '../api/client';
import entryKey from '../utils/entryKey';
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
	expandedTraces: {},
	scrollOffsets: { list: 0, grouped: 0 },
	tail: {
		active: false,
		lastByte: 0,
		newCount: 0,
	},
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
	toggleTraceExpanded( key ) {
		return { type: 'TOGGLE_TRACE_EXPANDED', key };
	},
	setScrollOffset( mode, offset ) {
		return { type: 'SET_SCROLL_OFFSET', mode, offset };
	},
	setTailActive( active ) {
		return { type: 'TAIL_SET_ACTIVE', active };
	},
	appendTailEntries( entries, lastByte, atTop, rotated = false ) {
		return {
			type: 'TAIL_APPEND_ENTRIES',
			entries,
			lastByte,
			atTop,
			rotated,
		};
	},
	clearTailNewCount() {
		return { type: 'TAIL_CLEAR_NEW_COUNT' };
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
		case 'SET_VIEW_MODE': {
			if ( state.viewMode === action.mode ) {
				return state;
			}
			// Tail polling appends raw entries — flipping to grouped
			// while it's running would mutate state against a list the
			// user can no longer see. Auto-stop instead of leaving the
			// loop running invisibly.
			const tail =
				action.mode === 'grouped' && state.tail.active
					? { ...state.tail, active: false, newCount: 0 }
					: state.tail;
			return { ...state, viewMode: action.mode, tail };
		}
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
		case 'TOGGLE_TRACE_EXPANDED': {
			const next = { ...state.expandedTraces };
			if ( next[ action.key ] ) {
				delete next[ action.key ];
			} else {
				next[ action.key ] = true;
			}
			return { ...state, expandedTraces: next };
		}
		case 'TAIL_SET_ACTIVE':
			return {
				...state,
				tail: {
					...state.tail,
					active: action.active,
					newCount: action.active ? state.tail.newCount : 0,
				},
			};
		case 'TAIL_APPEND_ENTRIES': {
			const incoming = action.entries || [];
			// Rotation: server detected the file shrunk, so the response
			// is a fresh baseline, not a delta. Replace the list, drop
			// the new-since-you-looked counter, prune expanded-trace
			// keys whose entries are no longer visible.
			if ( action.rotated ) {
				const replacement = incoming.slice().reverse();
				return {
					...state,
					logs: { ...state.logs, items: replacement },
					expandedTraces: {},
					tail: {
						...state.tail,
						lastByte: action.lastByte,
						newCount: 0,
					},
				};
			}
			const items =
				incoming.length > 0
					? [ ...incoming.slice().reverse(), ...state.logs.items ]
					: state.logs.items;
			return {
				...state,
				logs: { ...state.logs, items },
				tail: {
					...state.tail,
					lastByte: action.lastByte,
					newCount: action.atTop
						? 0
						: state.tail.newCount + incoming.length,
				},
			};
		}
		case 'TAIL_CLEAR_NEW_COUNT':
			return { ...state, tail: { ...state.tail, newCount: 0 } };
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
		case 'LOGS_RECEIVED': {
			const items = action.payload.items || [];
			// A re-fetch replaces the list outright, so any expanded-
			// trace keys whose entries are no longer present become
			// dead weight that would grow without bound across a long
			// admin session. Prune to the new keyset (entries carry
			// `raw` directly; groups are unaffected since their
			// expansion lives in `expandedGroups`).
			const liveKeys = {};
			items.forEach( ( item ) => {
				const key = entryKey( item );
				if ( key ) {
					liveKeys[ key ] = true;
				}
			} );
			const expandedTraces = {};
			Object.keys( state.expandedTraces ).forEach( ( key ) => {
				if ( liveKeys[ key ] ) {
					expandedTraces[ key ] = true;
				}
			} );
			return {
				...state,
				expandedTraces,
				logs: {
					...state.logs,
					isLoading: false,
					items,
					total: action.payload.total || 0,
					page: action.payload.page || 1,
					perPage: action.payload.per_page || state.logs.perPage,
				},
				tail: {
					...state.tail,
					lastByte: action.payload.last_byte ?? state.tail.lastByte,
					// Re-fetches replace the list outright, so any "new since
					// you last looked" count from a previous tail loop is
					// stale by definition.
					newCount: 0,
				},
			};
		}
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
	isTraceExpanded: ( state, key ) => Boolean( state.expandedTraces[ key ] ),
	getExpandedTraces: ( state ) => state.expandedTraces,
	getScrollOffset: ( state, mode ) => state.scrollOffsets[ mode ] || 0,
	isTailActive: ( state ) => state.tail.active,
	getTailLastByte: ( state ) => state.tail.lastByte,
	getTailNewCount: ( state ) => state.tail.newCount,
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
