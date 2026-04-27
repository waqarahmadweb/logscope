/**
 * Polls `GET /logs?since=<last_byte>` on the configured tail interval
 * and appends new entries to the store. The hook is dormant until
 * `isTailActive(state)` flips true, then runs a `setTimeout` chain
 * (rather than `setInterval`) so a slow REST call cannot pile up
 * overlapping requests — each tick waits for the previous response
 * before scheduling the next.
 *
 * "Scrolled to top" is the proxy for "user is reading the newest line",
 * because the viewer prepends new entries at the top. When the user has
 * scrolled away, appendTailEntries increments `newCount` and the
 * LogViewer renders the "N new" pill; on click the pill scrolls to top
 * and clears the counter.
 */
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

import { STORE_KEY } from '../store';
import { client } from '../api/client';

const DEFAULT_INTERVAL_SEC = 3;
const SCROLL_THRESHOLD_PX = 8;

function buildParams( filters, since ) {
	const params = { since };
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

export default function useTailPolling( scrollElementRef ) {
	const { isActive, lastByte, filters } = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			isActive: store.isTailActive(),
			lastByte: store.getTailLastByte(),
			filters: store.getFilters(),
		};
	}, [] );
	const { appendTailEntries } = useDispatch( STORE_KEY );

	// Refs let the polling closure read the latest values without
	// resubscribing the effect on every keystroke.
	const lastByteRef = useRef( lastByte );
	const filtersRef = useRef( filters );
	useEffect( () => {
		lastByteRef.current = lastByte;
	}, [ lastByte ] );
	useEffect( () => {
		filtersRef.current = filters;
	}, [ filters ] );

	useEffect( () => {
		if ( ! isActive ) {
			return undefined;
		}

		const intervalSec =
			Number(
				( typeof window !== 'undefined' &&
					window.LogscopeAdmin?.tailInterval ) ||
					DEFAULT_INTERVAL_SEC
			) || DEFAULT_INTERVAL_SEC;
		const intervalMs = Math.max( 1, intervalSec ) * 1000;
		const state = { cancelled: false, timer: null };

		const tick = async () => {
			try {
				const res = await client.getLogs(
					buildParams( filtersRef.current, lastByteRef.current )
				);
				if ( state.cancelled ) {
					return;
				}
				const el = scrollElementRef.current;
				const atTop = el ? el.scrollTop <= SCROLL_THRESHOLD_PX : true;
				appendTailEntries(
					res.items || [],
					res.last_byte || lastByteRef.current,
					atTop
				);
				if ( atTop && el && ( res.items || [] ).length > 0 ) {
					el.scrollTop = 0;
				}
			} catch ( e ) {
				// Network blips during a tail loop are expected on flaky
				// hosts; swallow and try again next tick.
			}
			if ( ! state.cancelled ) {
				state.timer = setTimeout( tick, intervalMs );
			}
		};

		state.timer = setTimeout( tick, intervalMs );
		return () => {
			state.cancelled = true;
			if ( state.timer ) {
				clearTimeout( state.timer );
			}
		};
	}, [ isActive, appendTailEntries, scrollElementRef ] );
}
