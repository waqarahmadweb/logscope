/**
 * Renders `text` with substrings matching the FilterBar regex wrapped in
 * `<mark class="logscope-mark">`. Returns the bare string when `pattern`
 * is empty, doesn't compile, or matches nothing — callers don't need to
 * branch on the highlight state themselves.
 *
 * The pattern comes from user input and may be partial / invalid mid-
 * type, so a `try/catch` around `RegExp` is load-bearing: a thrown
 * SyntaxError would otherwise unmount the row. The regex is built with
 * the global flag so every occurrence highlights, and case-insensitive
 * to mirror the server-side `q` semantics (server uses preg_match
 * without /i but real WP error messages are mixed-case enough that an
 * exact-case search rarely matches what the user typed; matching the
 * server's behaviour exactly here would surprise more than it would
 * align).
 *
 * Defends against catastrophic patterns by capping the input scan: a
 * zero-width match would otherwise loop forever.
 *
 * @param {string} text    Source text to highlight inside.
 * @param {string} pattern Raw regex pattern from the search filter.
 * @return {React.ReactNode} String when no highlights, fragment otherwise.
 */
export default function highlightMatches( text, pattern ) {
	if ( ! text || ! pattern ) {
		return text || '';
	}

	let regex;
	try {
		regex = new RegExp( pattern, 'gi' );
	} catch ( e ) {
		return text;
	}

	const parts = [];
	let lastIndex = 0;
	let match;
	let safety = 0;
	while ( ( match = regex.exec( text ) ) !== null ) {
		// Zero-width match would spin forever — bump past it.
		if ( match.index === regex.lastIndex ) {
			regex.lastIndex += 1;
			continue;
		}
		if ( match.index > lastIndex ) {
			parts.push( text.slice( lastIndex, match.index ) );
		}
		parts.push(
			<mark key={ parts.length } className="logscope-mark">
				{ match[ 0 ] }
			</mark>
		);
		lastIndex = match.index + match[ 0 ].length;

		// Belt-and-braces: cap iterations to avoid pathological inputs
		// holding the render loop. 1000 highlights per row is well past
		// any realistic case.
		if ( ++safety > 1000 ) {
			break;
		}
	}

	if ( parts.length === 0 ) {
		return text;
	}

	if ( lastIndex < text.length ) {
		parts.push( text.slice( lastIndex ) );
	}

	return <>{ parts }</>;
}
