/**
 * Classifies a stack-frame file path into a WordPress source category.
 *
 * Mirrors the server-side `Logscope\Log\SourceClassifier` so the
 * StackTracePanel can color-code frames by origin (plugin / theme /
 * mu-plugin / core) without a per-frame REST round-trip. Both `/` and
 * `\` separators are accepted.
 *
 * Returns `{ kind, slug }`:
 *   kind  — 'plugin' | 'theme' | 'mu-plugin' | 'core' | 'unknown'
 *   slug  — short human-readable identifier (the plugin / theme folder
 *           name, or null for core / unknown).
 */

const PATTERNS = [
	{ kind: 'mu-plugin', re: /\/wp-content\/mu-plugins\/([^/]+)/ },
	{ kind: 'plugin', re: /\/wp-content\/plugins\/([^/]+)/ },
	{ kind: 'theme', re: /\/wp-content\/themes\/([^/]+)/ },
];

export default function frameSource( file ) {
	if ( typeof file !== 'string' || file === '' ) {
		return { kind: 'unknown', slug: null };
	}

	const normalised = file.replace( /\\/g, '/' );

	for ( const { kind, re } of PATTERNS ) {
		const match = normalised.match( re );
		if ( match ) {
			return { kind, slug: match[ 1 ] };
		}
	}

	if (
		normalised.indexOf( '/wp-includes/' ) !== -1 ||
		normalised.indexOf( '/wp-admin/' ) !== -1 ||
		/\/wp-[a-z-]+\.php$/.test( normalised )
	) {
		return { kind: 'core', slug: null };
	}

	return { kind: 'unknown', slug: null };
}
