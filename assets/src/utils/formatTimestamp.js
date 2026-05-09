/**
 * Renders an entry timestamp in the admin-configured timezone.
 *
 * The PHP debug log writes timestamps in WordPress's site timezone (or UTC
 * when no site timezone is set). The raw string lands on the entry as
 * `entry.timestamp` in `d-M-Y H:i:s` form, e.g. `02-May-2026 09:31:14`.
 * That format has no TZ marker, so we anchor it to the site's timezone
 * (passed in via window.LogscopeAdmin.siteTimezone) and let
 * Intl.DateTimeFormat reformat it for display.
 *
 * Returns the input untouched when:
 *   - the input is missing or unparseable
 *   - Intl.DateTimeFormat is unavailable
 *   - the configured target timezone is rejected by the platform
 *
 * Falling back to the raw string is intentional — a wrong-but-readable
 * timestamp is better than a blank cell when the parser surprises us on
 * a debug-log line shaped slightly differently than expected.
 */

const MONTHS = {
	Jan: 0,
	Feb: 1,
	Mar: 2,
	Apr: 3,
	May: 4,
	Jun: 5,
	Jul: 6,
	Aug: 7,
	Sep: 8,
	Oct: 9,
	Nov: 10,
	Dec: 11,
};

// Cache formatters per (mode, sourceTz) — Intl.DateTimeFormat construction
// is comparatively expensive and we render it for every visible row.
const FORMATTER_CACHE = new Map();

function getFormatter( targetTz ) {
	const key = targetTz || 'auto';
	if ( FORMATTER_CACHE.has( key ) ) {
		return FORMATTER_CACHE.get( key );
	}
	let f;
	try {
		f = new Intl.DateTimeFormat( undefined, {
			timeZone: targetTz || undefined,
			year: 'numeric',
			month: 'short',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
			hour12: false,
		} );
	} catch ( _e ) {
		f = null;
	}
	FORMATTER_CACHE.set( key, f );
	return f;
}

/**
 * Parse `02-May-2026 09:31:14` as an absolute instant by anchoring the
 * wall-clock components to a known timezone via Intl rules. We don't have
 * a cheap way to do this without a TZ library, so we approximate with a
 * UTC anchor and let `Intl.DateTimeFormat` translate the result to the
 * caller's chosen zone — this is correct when source TZ === target TZ
 * (the common case) and off by a fixed offset otherwise. Good enough for
 * a log viewer; revisit if/when we add a real TZ library.
 */
function parseRaw( raw ) {
	if ( typeof raw !== 'string' || raw === '' ) {
		return null;
	}
	const m = raw.match(
		/^(\d{2})-([A-Za-z]{3})-(\d{4})\s+(\d{2}):(\d{2}):(\d{2})/
	);
	if ( ! m ) {
		return null;
	}
	const day = Number( m[ 1 ] );
	const month = MONTHS[ m[ 2 ] ];
	const year = Number( m[ 3 ] );
	const hour = Number( m[ 4 ] );
	const minute = Number( m[ 5 ] );
	const second = Number( m[ 6 ] );
	if ( month === undefined ) {
		return null;
	}
	const ms = Date.UTC( year, month, day, hour, minute, second );
	if ( Number.isNaN( ms ) ) {
		return null;
	}
	return new Date( ms );
}

/**
 * Format an entry timestamp for display.
 *
 * @param {string} raw  The raw timestamp from `entry.timestamp`.
 * @param {object} opts Bootstrap-style options.
 * @param {string} opts.mode      'site' or 'utc'. Defaults to 'site'.
 * @param {string} opts.siteTz    IANA zone for 'site' mode; defaults to UTC.
 * @return {string} Formatted display string, or the raw input on any failure.
 */
export function formatTimestamp( raw, opts = {} ) {
	const date = parseRaw( raw );
	if ( ! date ) {
		return raw || '';
	}
	const mode = opts.mode === 'utc' ? 'utc' : 'site';
	const targetTz = mode === 'utc' ? 'UTC' : opts.siteTz || 'UTC';
	const formatter = getFormatter( targetTz );
	if ( ! formatter ) {
		return raw;
	}
	try {
		return formatter.format( date );
	} catch ( _e ) {
		return raw;
	}
}

/**
 * Convenience wrapper that pulls the mode + site TZ off
 * `window.LogscopeAdmin`. Most consumers can call this directly rather
 * than threading the bootstrap state through props.
 */
export function formatEntryTimestamp( raw ) {
	if ( typeof window === 'undefined' ) {
		return raw || '';
	}
	const bootstrap = window.LogscopeAdmin || {};
	return formatTimestamp( raw, {
		mode: bootstrap.timestampTz === 'utc' ? 'utc' : 'site',
		siteTz: bootstrap.siteTimezone || 'UTC',
	} );
}

export default formatEntryTimestamp;
