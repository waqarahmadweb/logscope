/**
 * Content signature for the session-only "hide entry" list.
 *
 * Distinct from `entryKey` (which uses the per-fetch `_clientId`):
 * hiding must survive a re-fetch, where every row gets a fresh id but
 * the underlying log line is the same. Keying off raw + timestamp is
 * stable across re-fetches and is intentionally collision-prone for
 * exact duplicates — hiding one of N identical rows hides them all,
 * which matches the user intent ("this noise is not useful right now").
 *
 * Admins who want long-lived hiding should mute the signature server-
 * side; this helper is purely for ephemeral triage.
 *
 * @param {Object|null|undefined} entry Parsed log entry.
 * @return {string} Content signature, or '' for an unusable entry.
 */
export default function hiddenSig( entry ) {
	if ( ! entry ) {
		return '';
	}
	return (
		( entry.timestamp || '' ) + '\n' + ( entry.raw || entry.message || '' )
	);
}
