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

if ( bootstrap.restRoot ) {
	apiFetch.use( apiFetch.createRootURLMiddleware( bootstrap.restRoot ) );
}

if ( bootstrap.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.nonce ) );
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
};

export { bootstrap };
