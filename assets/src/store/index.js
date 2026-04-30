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
import { createReduxStore, register, select } from '@wordpress/data';
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
		alertTest: {
			isSending: false,
			results: null,
			error: null,
		},
	},
	mutes: {
		items: [],
		isLoading: false,
		isSaving: false,
		loadError: null,
		saveError: null,
	},
	presets: {
		items: [],
		isLoading: false,
		isSaving: false,
		loadError: null,
		saveError: null,
	},
	stats: {
		range: '24h',
		bucket: 'hour',
		data: null,
		isLoading: false,
		loadError: null,
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
	startSendingTestAlert() {
		return { type: 'ALERT_TEST_SENDING' };
	},
	receiveAlertTestResults( results ) {
		return { type: 'ALERT_TEST_RECEIVED', results };
	},
	failAlertTest( error ) {
		return { type: 'ALERT_TEST_FAILED', error };
	},
	clearAlertTestResults() {
		return { type: 'ALERT_TEST_CLEARED' };
	},
	*sendTestAlert() {
		yield actions.startSendingTestAlert();
		try {
			const payload = yield { type: 'API_TEST_ALERT' };
			const results = ( payload && payload.results ) || [];
			yield actions.receiveAlertTestResults( results );
			const sent = results.filter( ( r ) => r.outcome === 'sent' ).length;
			yield actions.pushToast( {
				message:
					sent > 0
						? __( 'Test alert sent.', 'logscope' )
						: __(
								'Test alert dispatched but no backend reported success.',
								'logscope'
						  ),
				status: sent > 0 ? 'success' : 'warning',
			} );
		} catch ( error ) {
			yield actions.failAlertTest( error?.message || 'Unknown error' );
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not send test alert.', 'logscope' ),
				status: 'error',
			} );
		}
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
	startLoadingMutes() {
		return { type: 'MUTES_LOADING' };
	},
	receiveMutes( items ) {
		return { type: 'MUTES_RECEIVED', items };
	},
	failLoadMutes( error ) {
		return { type: 'MUTES_LOAD_FAILED', error };
	},
	startSavingMutes() {
		return { type: 'MUTES_SAVING' };
	},
	failSaveMutes( error ) {
		return { type: 'MUTES_SAVE_FAILED', error };
	},
	*fetchMutes() {
		yield actions.startLoadingMutes();
		try {
			const payload = yield { type: 'API_FETCH_MUTES' };
			yield actions.receiveMutes( ( payload && payload.items ) || [] );
		} catch ( error ) {
			yield actions.failLoadMutes( error?.message || 'Unknown error' );
		}
	},
	*muteSignature( signature, reason = '' ) {
		yield actions.startSavingMutes();
		try {
			const payload = yield {
				type: 'API_MUTE_SIGNATURE',
				signature,
				reason,
			};
			yield actions.receiveMutes( ( payload && payload.items ) || [] );
			yield actions.pushToast( {
				message: __( 'Signature muted.', 'logscope' ),
				status: 'success',
			} );
		} catch ( error ) {
			yield actions.failSaveMutes( error?.message || 'Unknown error' );
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not mute the signature.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	*unmuteSignature( signature ) {
		yield actions.startSavingMutes();
		try {
			const payload = yield {
				type: 'API_UNMUTE_SIGNATURE',
				signature,
			};
			yield actions.receiveMutes( ( payload && payload.items ) || [] );
			yield actions.pushToast( {
				message: __( 'Signature unmuted.', 'logscope' ),
				status: 'success',
			} );
		} catch ( error ) {
			yield actions.failSaveMutes( error?.message || 'Unknown error' );
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not unmute the signature.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	startLoadingPresets() {
		return { type: 'PRESETS_LOADING' };
	},
	receivePresets( items ) {
		return { type: 'PRESETS_RECEIVED', items };
	},
	failLoadPresets( error ) {
		return { type: 'PRESETS_LOAD_FAILED', error };
	},
	startSavingPresets() {
		return { type: 'PRESETS_SAVING' };
	},
	failSavePresets( error ) {
		return { type: 'PRESETS_SAVE_FAILED', error };
	},
	*fetchPresets() {
		yield actions.startLoadingPresets();
		try {
			const payload = yield { type: 'API_FETCH_PRESETS' };
			yield actions.receivePresets( ( payload && payload.items ) || [] );
		} catch ( error ) {
			yield actions.failLoadPresets( error?.message || 'Unknown error' );
		}
	},
	*savePreset( name, filters ) {
		yield actions.startSavingPresets();
		try {
			const payload = yield {
				type: 'API_SAVE_PRESET',
				name,
				filters,
			};
			yield actions.receivePresets( ( payload && payload.items ) || [] );
			yield actions.pushToast( {
				message: __( 'Preset saved.', 'logscope' ),
				status: 'success',
			} );
		} catch ( error ) {
			yield actions.failSavePresets( error?.message || 'Unknown error' );
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not save the preset.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	*deletePreset( name ) {
		yield actions.startSavingPresets();
		try {
			const payload = yield {
				type: 'API_DELETE_PRESET',
				name,
			};
			yield actions.receivePresets( ( payload && payload.items ) || [] );
			yield actions.pushToast( {
				message: __( 'Preset deleted.', 'logscope' ),
				status: 'success',
			} );
		} catch ( error ) {
			yield actions.failSavePresets( error?.message || 'Unknown error' );
			yield actions.pushToast( {
				message:
					error?.message ||
					__( 'Could not delete the preset.', 'logscope' ),
				status: 'error',
			} );
		}
	},
	setStatsRange( range ) {
		return { type: 'STATS_SET_RANGE', range };
	},
	setStatsBucket( bucket ) {
		return { type: 'STATS_SET_BUCKET', bucket };
	},
	startLoadingStats() {
		return { type: 'STATS_LOADING' };
	},
	receiveStats( payload ) {
		return { type: 'STATS_RECEIVED', payload };
	},
	failLoadStats( error ) {
		return { type: 'STATS_LOAD_FAILED', error };
	},
	*fetchStats() {
		yield actions.startLoadingStats();
		try {
			const payload = yield { type: 'API_FETCH_STATS' };
			yield actions.receiveStats( payload );
		} catch ( error ) {
			yield actions.failLoadStats( error?.message || 'Unknown error' );
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
		case 'ALERT_TEST_SENDING':
			return {
				...state,
				settings: {
					...state.settings,
					alertTest: {
						isSending: true,
						results: null,
						error: null,
					},
				},
			};
		case 'ALERT_TEST_RECEIVED':
			return {
				...state,
				settings: {
					...state.settings,
					alertTest: {
						isSending: false,
						results: action.results,
						error: null,
					},
				},
			};
		case 'ALERT_TEST_FAILED':
			return {
				...state,
				settings: {
					...state.settings,
					alertTest: {
						isSending: false,
						results: null,
						error: action.error,
					},
				},
			};
		case 'ALERT_TEST_CLEARED':
			return {
				...state,
				settings: {
					...state.settings,
					alertTest: {
						isSending: false,
						results: null,
						error: null,
					},
				},
			};
		case 'MUTES_LOADING':
			return {
				...state,
				mutes: {
					...state.mutes,
					isLoading: true,
					loadError: null,
				},
			};
		case 'MUTES_RECEIVED':
			return {
				...state,
				mutes: {
					...state.mutes,
					isLoading: false,
					isSaving: false,
					loadError: null,
					saveError: null,
					items: action.items,
				},
			};
		case 'MUTES_LOAD_FAILED':
			return {
				...state,
				mutes: {
					...state.mutes,
					isLoading: false,
					loadError: action.error,
				},
			};
		case 'MUTES_SAVING':
			return {
				...state,
				mutes: {
					...state.mutes,
					isSaving: true,
					saveError: null,
				},
			};
		case 'MUTES_SAVE_FAILED':
			return {
				...state,
				mutes: {
					...state.mutes,
					isSaving: false,
					saveError: action.error,
				},
			};
		case 'PRESETS_LOADING':
			return {
				...state,
				presets: { ...state.presets, isLoading: true, loadError: null },
			};
		case 'PRESETS_RECEIVED':
			return {
				...state,
				presets: {
					...state.presets,
					isLoading: false,
					isSaving: false,
					loadError: null,
					saveError: null,
					items: action.items,
				},
			};
		case 'PRESETS_LOAD_FAILED':
			return {
				...state,
				presets: {
					...state.presets,
					isLoading: false,
					loadError: action.error,
				},
			};
		case 'PRESETS_SAVING':
			return {
				...state,
				presets: { ...state.presets, isSaving: true, saveError: null },
			};
		case 'PRESETS_SAVE_FAILED':
			return {
				...state,
				presets: {
					...state.presets,
					isSaving: false,
					saveError: action.error,
				},
			};
		case 'STATS_SET_RANGE':
			if ( state.stats.range === action.range ) {
				return state;
			}
			return {
				...state,
				stats: { ...state.stats, range: action.range },
			};
		case 'STATS_SET_BUCKET':
			if ( state.stats.bucket === action.bucket ) {
				return state;
			}
			return {
				...state,
				stats: { ...state.stats, bucket: action.bucket },
			};
		case 'STATS_LOADING':
			return {
				...state,
				stats: { ...state.stats, isLoading: true, loadError: null },
			};
		case 'STATS_RECEIVED':
			return {
				...state,
				stats: {
					...state.stats,
					isLoading: false,
					loadError: null,
					data: action.payload,
				},
			};
		case 'STATS_LOAD_FAILED':
			return {
				...state,
				stats: {
					...state.stats,
					isLoading: false,
					loadError: action.error,
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
	isSendingTestAlert: ( state ) =>
		Boolean(
			state.settings.alertTest && state.settings.alertTest.isSending
		),
	getAlertTestResults: ( state ) =>
		state.settings.alertTest ? state.settings.alertTest.results : null,
	getAlertTestError: ( state ) =>
		state.settings.alertTest ? state.settings.alertTest.error : null,
	getPresets: ( state ) => state.presets.items,
	isSavingPresets: ( state ) => state.presets.isSaving,
	getMutes: ( state ) => state.mutes.items,
	isLoadingMutes: ( state ) => state.mutes.isLoading,
	isSavingMutes: ( state ) => state.mutes.isSaving,
	getMutesLoadError: ( state ) => state.mutes.loadError,
	getMutesSaveError: ( state ) => state.mutes.saveError,
	isMuted: ( state, signature ) =>
		state.mutes.items.some( ( m ) => m.signature === signature ),
	getStatsRange: ( state ) => state.stats.range,
	getStatsBucket: ( state ) => state.stats.bucket,
	getStatsData: ( state ) => state.stats.data,
	isLoadingStats: ( state ) => state.stats.isLoading,
	getStatsLoadError: ( state ) => state.stats.loadError,
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
	API_TEST_ALERT() {
		return client.testAlert();
	},
	API_FETCH_MUTES() {
		return client.getMutes();
	},
	API_MUTE_SIGNATURE( { signature, reason } ) {
		return client.muteSignature( signature, reason );
	},
	API_UNMUTE_SIGNATURE( { signature } ) {
		return client.unmuteSignature( signature );
	},
	API_FETCH_PRESETS() {
		return client.getPresets();
	},
	API_SAVE_PRESET( { name, filters } ) {
		return client.savePreset( name, filters );
	},
	API_DELETE_PRESET( { name } ) {
		return client.deletePreset( name );
	},
	API_FETCH_STATS() {
		// Read current range/bucket out of the store so the thunk caller
		// does not have to thread them through. `select` is imported
		// statically; the registered store is the source of truth for
		// what to fetch.
		const range = select( STORE_KEY ).getStatsRange();
		const bucket = select( STORE_KEY ).getStatsBucket();
		return client.getStats( { range, bucket } );
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
