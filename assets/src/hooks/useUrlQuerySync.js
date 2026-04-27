/**
 * Mirrors filter + view-mode state into the URL query string and seeds
 * initial state from it on mount. The Logscope admin page is a single
 * mount under tools.php with no router; `history.replaceState` keeps the
 * URL in sync without polluting the back stack on every keystroke.
 *
 * Only Logscope-owned keys are touched; foreign params on the URL
 * (page, post_type, …) are preserved.
 */
import { useEffect, useRef } from '@wordpress/element';

const KEYS = [ 'view', 'severity', 'from', 'to', 'q', 'source' ];

export function readInitialQueryState() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	const params = new URLSearchParams( window.location.search );
	if ( ! KEYS.some( ( k ) => params.has( k ) ) ) {
		return null;
	}
	const severityRaw = params.get( 'severity' );
	return {
		viewMode: params.get( 'view' ) === 'grouped' ? 'grouped' : 'list',
		filters: {
			severity: severityRaw
				? severityRaw.split( ',' ).filter( Boolean )
				: [],
			from: params.get( 'from' ) || '',
			to: params.get( 'to' ) || '',
			q: params.get( 'q' ) || '',
			source: params.get( 'source' ) || '',
		},
	};
}

export default function useUrlQuerySync( viewMode, filters ) {
	// Skip the very first effect tick: on mount the store has either
	// already absorbed the URL state (via readInitialQueryState) or is at
	// defaults — either way replaying it back to the URL is a no-op.
	const skipFirst = useRef( true );

	useEffect( () => {
		if ( skipFirst.current ) {
			skipFirst.current = false;
			return;
		}
		if ( typeof window === 'undefined' ) {
			return;
		}
		const params = new URLSearchParams( window.location.search );

		if ( viewMode === 'grouped' ) {
			params.set( 'view', 'grouped' );
		} else {
			params.delete( 'view' );
		}

		if ( filters.severity.length > 0 ) {
			params.set( 'severity', filters.severity.join( ',' ) );
		} else {
			params.delete( 'severity' );
		}

		[ 'from', 'to', 'q', 'source' ].forEach( ( key ) => {
			if ( filters[ key ] ) {
				params.set( key, filters[ key ] );
			} else {
				params.delete( key );
			}
		} );

		const qs = params.toString();
		const next =
			window.location.pathname +
			( qs ? '?' + qs : '' ) +
			window.location.hash;
		window.history.replaceState( null, '', next );
	}, [ viewMode, filters ] );
}
