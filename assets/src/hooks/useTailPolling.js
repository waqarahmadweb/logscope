/**
 * Polls `GET /logs?since=<last_byte>` on the configured tail interval
 * and appends new entries to the store. Dormant until `isTailActive`
 * flips true, then runs a `setTimeout` chain (rather than
 * `setInterval`) so a slow REST call cannot pile up overlapping
 * requests — each tick waits for the previous response before
 * scheduling the next.
 *
 * "Scrolled to top" is the proxy for "user is reading the newest
 * line" because the viewer prepends new entries at the top. When the
 * user has scrolled away, appendTailEntries increments `newCount` and
 * the LogViewer renders the "N new" pill; clicking it scrolls to top
 * and clears the counter.
 *
 * Filter race: a tick may start with one filter shape and resolve
 * after the user has changed it. The closure snapshots `filtersRef`
 * at send-time and discards the response if the live shape no longer
 * matches — otherwise stale entries that don't satisfy the active
 * filter would land in the list. Server rotation (`rotated: true`)
 * forwards through to the reducer, which replaces the list rather
 * than appending.
 */
import { useEffect, useRef } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';

import { STORE_KEY } from '../store';
import { client } from '../api/client';
import buildFilterParams from '../utils/filterParams';

const DEFAULT_INTERVAL_SEC = 3;
const SCROLL_THRESHOLD_PX = 8;

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
			const filtersAtSend = filtersRef.current;
			try {
				const res = await client.getLogs( {
					since: lastByteRef.current,
					...buildFilterParams( filtersAtSend ),
				} );
				if ( state.cancelled ) {
					return;
				}
				// Drop the response if filters changed mid-flight; the
				// next tick will refetch with the live shape and the
				// LogViewer's primary fetch effect will already have
				// rebaselined the list to the new filter set.
				if (
					JSON.stringify( filtersRef.current ) !==
					JSON.stringify( filtersAtSend )
				) {
					return;
				}
				const el = scrollElementRef.current;
				const atTop = el ? el.scrollTop <= SCROLL_THRESHOLD_PX : true;
				const rotated = !! res.rotated;
				appendTailEntries(
					res.items || [],
					res.last_byte || lastByteRef.current,
					atTop,
					rotated
				);
				if (
					( atTop || rotated ) &&
					el &&
					( res.items || [] ).length > 0
				) {
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
