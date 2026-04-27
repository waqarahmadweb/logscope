/**
 * Shared severity → CSS tone + display-label mapping for the log viewer
 * and the grouped view. The REST layer serialises `Severity::*` lowercase
 * tokens (`fatal`, `warning`, …) directly, so the keys here mirror those
 * tokens. A previous draft used the human "Fatal error" / "Warning"
 * strings as keys; that always missed and every row got the unknown tone.
 */
import { __ } from '@wordpress/i18n';

export const SEVERITY_TOKENS = [
	'fatal',
	'parse',
	'warning',
	'notice',
	'deprecated',
	'strict',
	'unknown',
];

const TONE_BY_TOKEN = {
	fatal: 'fatal',
	parse: 'fatal',
	warning: 'warning',
	notice: 'notice',
	deprecated: 'deprecated',
	strict: 'deprecated',
	unknown: 'unknown',
};

export function severityTone( token ) {
	return TONE_BY_TOKEN[ token ] || 'unknown';
}

export function severityLabel( token ) {
	switch ( token ) {
		case 'fatal':
			return __( 'Fatal error', 'logscope' );
		case 'parse':
			return __( 'Parse error', 'logscope' );
		case 'warning':
			return __( 'Warning', 'logscope' );
		case 'notice':
			return __( 'Notice', 'logscope' );
		case 'deprecated':
			return __( 'Deprecated', 'logscope' );
		case 'strict':
			return __( 'Strict Standards', 'logscope' );
		default:
			return __( 'Unknown', 'logscope' );
	}
}
