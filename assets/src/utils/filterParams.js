/**
 * Translates the store's `filters` slice into the query-string shape
 * the REST `/logs` endpoint accepts. Shared between LogViewer's
 * primary fetch and useTailPolling's poll loop so the two cannot
 * drift in how a filter shape is encoded.
 *
 * @param {Object} filters Filters slice from the store.
 * @return {Object} Query-string params (only non-empty values).
 */
export default function buildFilterParams( filters ) {
	const params = {};
	if ( filters.severity?.length > 0 ) {
		params.severity = filters.severity.join( ',' );
	}
	[ 'from', 'to', 'q', 'source' ].forEach( ( key ) => {
		if ( filters[ key ] ) {
			params[ key ] = filters[ key ];
		}
	} );
	return params;
}
