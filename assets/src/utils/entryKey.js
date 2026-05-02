/**
 * Stable identifier for a parsed log entry, used as the key in the
 * store's `expandedTraces` and `selectedEntries` maps.
 *
 * The store stamps every entry with a monotonically-increasing
 * `_clientId` at receive/append time; that is the only field
 * guaranteed unique across the loaded set. PHP error logs routinely
 * repeat the *exact* same line within the same second (think a notice
 * fired on every page request), so a content-based key would collide
 * and selecting one row would flip every duplicate. Falling back to
 * the composite shape is only there for entries that arrive from a
 * code path that has not stamped them yet — production data always
 * carries `_clientId`.
 *
 * @param {Object|null|undefined} entry Parsed log entry.
 * @return {string} Stable composite key.
 */
export default function entryKey( entry ) {
	if ( ! entry ) {
		return '';
	}
	if ( entry._clientId !== undefined && entry._clientId !== null ) {
		return 'c' + entry._clientId;
	}
	return [
		entry.timestamp || '',
		entry.file || '',
		entry.line || '',
		entry.severity || '',
		entry.message || '',
		entry.raw || '',
	].join( '' );
}
