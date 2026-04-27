/**
 * Logscope @wordpress/data store, registered as `logscope/core`.
 *
 * Minimal scope for the Phase 6 shell: active tab + a typed slot for the
 * log entries the LogViewer (6.4) and the FilterBar (7.1) will populate.
 * Concrete fetch logic lands with each consuming feature.
 */
import { createReduxStore, register } from '@wordpress/data';

import { client } from '../api/client';

export const STORE_KEY = 'logscope/core';

const DEFAULT_STATE = {
	activeTab: 'logs',
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
			return actions.receiveLogs( response );
		} catch ( error ) {
			return actions.failLogs( error?.message || 'Unknown error' );
		}
	},
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case 'SET_ACTIVE_TAB':
			return { ...state, activeTab: action.tab };
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
