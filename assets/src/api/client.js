/**
 * REST client wrapper around @wordpress/api-fetch.
 *
 * Reads bootstrap config (REST root + nonce) that AssetLoader
 * localizes onto window.LogscopeAdmin. The middleware ensures every
 * request carries the `X-WP-Nonce` header so cookie-authenticated
 * REST traffic round-trips through core's auth pipeline.
 */
import apiFetch from '@wordpress/api-fetch';

const bootstrap =
	( typeof window !== 'undefined' && window.LogscopeAdmin ) || {};

// Guard against double-registration: HMR or an unexpected double
// import would otherwise stack the same middleware twice and the
// later one would prepend its prefix to an already-prefixed URL.
// Stash the flag on apiFetch itself so both copies of this module
// (HMR pre/post) see it.
if ( ! apiFetch.__logscopeMiddlewareInstalled ) {
	if ( bootstrap.restRoot ) {
		apiFetch.use( apiFetch.createRootURLMiddleware( bootstrap.restRoot ) );
	}
	if ( bootstrap.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.nonce ) );
	}
	apiFetch.__logscopeMiddlewareInstalled = true;
}

/**
 * Logscope REST namespace path. Concatenated onto the root URL by the
 * middleware above; callers pass paths like `/logs` or `/settings`.
 */
const NAMESPACE = '/logscope/v1';

/**
 * Builds a fully-qualified Logscope REST path.
 *
 * @param {string} path Path under the Logscope namespace (with leading slash).
 * @return {string} Path apiFetch will resolve against the REST root.
 */
function logscopePath( path ) {
	return NAMESPACE + path;
}

export const client = {
	getLogs( params = {} ) {
		const query = new URLSearchParams();
		Object.entries( params ).forEach( ( [ key, value ] ) => {
			if ( value !== undefined && value !== null && value !== '' ) {
				query.append( key, String( value ) );
			}
		} );
		const qs = query.toString();
		return apiFetch( {
			path: logscopePath( '/logs' ) + ( qs ? '?' + qs : '' ),
		} );
	},
	getSettings() {
		return apiFetch( { path: logscopePath( '/settings' ) } );
	},
	saveSettings( body ) {
		return apiFetch( {
			path: logscopePath( '/settings' ),
			method: 'POST',
			data: body,
		} );
	},
	testLogPath( path ) {
		return apiFetch( {
			path: logscopePath( '/settings/test-path' ),
			method: 'POST',
			data: { path },
		} );
	},
	testAlert() {
		return apiFetch( {
			path: logscopePath( '/alerts/test' ),
			method: 'POST',
		} );
	},
	getMutes( includeMuted = false ) {
		const qs = includeMuted ? '?include_muted=true' : '';
		return apiFetch( {
			path: logscopePath( '/logs/mute' ) + qs,
		} );
	},
	muteSignature( signature, reason = '' ) {
		return apiFetch( {
			path: logscopePath( '/logs/mute' ),
			method: 'POST',
			data: { signature, reason },
		} );
	},
	unmuteSignature( signature ) {
		return apiFetch( {
			path:
				logscopePath( '/logs/mute/' ) + encodeURIComponent( signature ),
			method: 'DELETE',
		} );
	},
};

export { bootstrap };
