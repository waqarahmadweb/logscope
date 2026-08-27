/**
 * Formats a `file:line` pair for display/copy. One implementation so the
 * six render/copy sites cannot drift on null handling: falls back to the
 * bare file when the line is missing, and to '' when both are — callers
 * that need a different empty placeholder ('—', null) map '' themselves.
 *
 * @param {string|null|undefined} file Absolute or relative file path.
 * @param {number|string|null}    line 1-based line number, if known.
 * @return {string} `path:line`, bare path, or ''.
 */
export default function fileLine( file, line ) {
	if ( ! file ) {
		return '';
	}
	return line ? `${ file }:${ line }` : file;
}
