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
 *   - settings: { values, draft, isLoading, isSaving, loadError,
 *     saveError, fieldErrors, lastSavedAt, testResult, isTesting } —
 *     Phase 8. `values` is server-of-record, `draft` is the editing
 *     buffer the panel renders. `fieldErrors` maps a field key to a
 *     translated error string when the REST layer rejects per-field.
 *     `testResult` mirrors the verdict from `/settings/test-path`.
 *   - toasts: transient Snackbar queue rendered by ToastHost (Phase
 *     11.1). Each entry has a stable id so dismiss-by-id can race-free
 *     coexist with auto-prune timers in the host component.
 */
import { createReduxStore, register } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

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
	settings: {
		values: null,
		draft: null,
		isLoading: false,
		isSaving: false,
		loadError: null,
		saveError: null,
		fieldErrors: {},
		lastSavedAt: 0,
		testResult: null,
		testError: null,
		isTesting: false,
	},
	toasts: [],
};

const TOAST_DEFAULT_TTL_MS = 5000;
let toastSeq = 0;

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
			yield actions.pushToast( {
				message:
					error?.message || __( 'Could not load logs.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	setSettingsDraft( partial ) {
		return { type: 'SETTINGS_SET_DRAFT', partial };
	},
	resetSettingsDraft() {
		return { type: 'SETTINGS_RESET_DRAFT' };
	},
	startLoadingSettings() {
		return { type: 'SETTINGS_LOADING' };
	},
	receiveSettings( payload ) {
		return { type: 'SETTINGS_RECEIVED', payload };
	},
	failLoadSettings( error ) {
		return { type: 'SETTINGS_LOAD_FAILED', error };
	},
	startSavingSettings() {
		return { type: 'SETTINGS_SAVING' };
	},
	settingsSaved( payload ) {
		return { type: 'SETTINGS_SAVED', payload };
	},
	failSaveSettings( error, fieldErrors = {} ) {
		return { type: 'SETTINGS_SAVE_FAILED', error, fieldErrors };
	},
	startTestingPath() {
		return { type: 'SETTINGS_TEST_STARTED' };
	},
	receiveTestResult( result ) {
		return { type: 'SETTINGS_TEST_RECEIVED', result };
	},
	failTestPath( error ) {
		return { type: 'SETTINGS_TEST_FAILED', error };
	},
	clearTestResult() {
		return { type: 'SETTINGS_TEST_CLEARED' };
	},
	*fetchSettings() {
		yield actions.startLoadingSettings();
		try {
			const payload = yield { type: 'API_FETCH_SETTINGS' };
			yield actions.receiveSettings( payload );
		} catch ( error ) {
			yield actions.failLoadSettings( error?.message || 'Unknown error' );
		}
	},
	*saveSettings( body ) {
		yield actions.startSavingSettings();
		try {
			const payload = yield {
				type: 'API_SAVE_SETTINGS',
				body,
			};
			yield actions.settingsSaved( payload );
			yield actions.pushToast( {
				message: __( 'Settings saved.', 'logscope' ),
				status: 'success',
			} );
		} catch ( error ) {
			// REST `unknown_setting` rejections come through with a
			// `data.unknown` array per the controller contract; surface
			// them as per-field errors so the panel can mark the
			// offending input rather than just showing a banner.
			const data = error?.data || {};
			const fieldErrors = {};
			if ( Array.isArray( data.unknown ) ) {
				data.unknown.forEach( ( key ) => {
					// Record a code rather than a translated string so the
					// panel owns user-facing copy. Keeps the store's job
					// to "what went wrong" rather than "how to phrase it."
					fieldErrors[ key ] = 'unknown_setting';
				} );
			}
			yield actions.failSaveSettings(
				error?.message || 'Unknown error',
				fieldErrors
			);
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not save settings.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	*testLogPath( path ) {
		yield actions.startTestingPath();
		try {
			const result = yield {
				type: 'API_TEST_LOG_PATH',
				path,
			};
			yield actions.receiveTestResult( result );
		} catch ( error ) {
			yield actions.failTestPath( error?.message || 'Unknown error' );
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not test the path.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	pushToast( { message, status = 'info', ttlMs = TOAST_DEFAULT_TTL_MS } ) {
		// Sequence + timestamp keeps ids unique even within the same ms tick.
		toastSeq += 1;
		const id = `t${ Date.now() }-${ toastSeq }`;
		return {
			type: 'TOAST_PUSHED',
			toast: {
				id,
				message,
				status,
				expiresAt: Date.now() + ttlMs,
			},
		};
	},
	dismissToast( id ) {
		return { type: 'TOAST_DISMISSED', id };
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
		case 'SETTINGS_LOADING':
			return {
				...state,
				settings: {
					...state.settings,
					isLoading: true,
					loadError: null,
				},
			};
		case 'SETTINGS_RECEIVED':
			return {
				...state,
				settings: {
					...state.settings,
					isLoading: false,
					loadError: null,
					values: { ...action.payload },
					draft: { ...action.payload },
					fieldErrors: {},
				},
			};
		case 'SETTINGS_LOAD_FAILED':
			return {
				...state,
				settings: {
					...state.settings,
					isLoading: false,
					loadError: action.error,
				},
			};
		case 'SETTINGS_SET_DRAFT':
			return {
				...state,
				settings: {
					...state.settings,
					draft: {
						...( state.settings.draft || {} ),
						...action.partial,
					},
					// Clear per-field errors for keys the admin is now
					// editing; the stale REST verdict no longer applies.
					fieldErrors: Object.keys( action.partial ).reduce(
						( acc, key ) => {
							const next = { ...acc };
							delete next[ key ];
							return next;
						},
						state.settings.fieldErrors
					),
				},
			};
		case 'SETTINGS_RESET_DRAFT':
			return {
				...state,
				settings: {
					...state.settings,
					draft: state.settings.values
						? { ...state.settings.values }
						: null,
					fieldErrors: {},
					saveError: null,
				},
			};
		case 'SETTINGS_SAVING':
			return {
				...state,
				settings: {
					...state.settings,
					isSaving: true,
					saveError: null,
					fieldErrors: {},
				},
			};
		case 'SETTINGS_SAVED':
			return {
				...state,
				settings: {
					...state.settings,
					isSaving: false,
					saveError: null,
					values: { ...action.payload },
					draft: { ...action.payload },
					fieldErrors: {},
					lastSavedAt: Date.now(),
				},
			};
		case 'SETTINGS_SAVE_FAILED':
			return {
				...state,
				settings: {
					...state.settings,
					isSaving: false,
					saveError: action.error,
					fieldErrors: action.fieldErrors || {},
				},
			};
		case 'SETTINGS_TEST_STARTED':
			return {
				...state,
				settings: {
					...state.settings,
					isTesting: true,
					testError: null,
				},
			};
		case 'SETTINGS_TEST_RECEIVED':
			return {
				...state,
				settings: {
					...state.settings,
					isTesting: false,
					testResult: action.result,
					testError: null,
				},
			};
		case 'SETTINGS_TEST_FAILED':
			// A transport failure (network down, 5xx) is not the same as
			// a probe verdict of "path rejected" — the admin needs to be
			// able to tell those apart. Keep the previous testResult (if
			// any) so the last successful probe stays visible while the
			// network is sorted out, and surface the transport error in
			// a separate slot the panel renders with neutral copy.
			return {
				...state,
				settings: {
					...state.settings,
					isTesting: false,
					testError: action.error,
				},
			};
		case 'SETTINGS_TEST_CLEARED':
			return {
				...state,
				settings: {
					...state.settings,
					testResult: null,
					testError: null,
				},
			};
		case 'TOAST_PUSHED':
			return { ...state, toasts: [ ...state.toasts, action.toast ] };
		case 'TOAST_DISMISSED':
			return {
				...state,
				toasts: state.toasts.filter( ( t ) => t.id !== action.id ),
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
	getSettingsValues: ( state ) => state.settings.values,
	getSettingsDraft: ( state ) => state.settings.draft,
	isLoadingSettings: ( state ) => state.settings.isLoading,
	isSavingSettings: ( state ) => state.settings.isSaving,
	getSettingsLoadError: ( state ) => state.settings.loadError,
	getSettingsSaveError: ( state ) => state.settings.saveError,
	getSettingsFieldErrors: ( state ) => state.settings.fieldErrors,
	getSettingsLastSavedAt: ( state ) => state.settings.lastSavedAt,
	getPathTestResult: ( state ) => state.settings.testResult,
	getPathTestError: ( state ) => state.settings.testError,
	isTestingPath: ( state ) => state.settings.isTesting,
	getToasts: ( state ) => state.toasts,
};

const controls = {
	API_FETCH_LOGS( { params } ) {
		return client.getLogs( params );
	},
	API_FETCH_SETTINGS() {
		return client.getSettings();
	},
	API_SAVE_SETTINGS( { body } ) {
		return client.saveSettings( body );
	},
	API_TEST_LOG_PATH( { path } ) {
		return client.testLogPath( path );
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
