/**
 * Stable identifier for a parsed log entry, used as the key in the
 * store's `expandedTraces` map. Always concatenates the full shape
 * rather than short-circuiting on `raw` alone — PHP error logs
 * routinely repeat identical lines (the same notice on every page
 * load), so a `raw`-only key would collide and expanding one row
 * would expand its duplicates. Index isn't safe either, since
 * tail-prepend shifts every index downward on every poll.
 *
 * @param {Object|null|undefined} entry Parsed log entry.
 * @return {string} Stable composite key.
 */
export default function entryKey( entry ) {
	if ( ! entry ) {
		return '';
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
