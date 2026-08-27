/**
 * Shared CSV export helpers for the entries (LogViewer) and groups
 * (GroupedView) exports — one implementation so the quoting and
 * injection rules cannot drift between the two.
 */

/**
 * Formats one value as an RFC-4180 CSV cell, neutralizing spreadsheet
 * formula/DDE injection.
 *
 * Log messages are attacker-influenced (anything can be echoed into
 * debug.log) and the BOM we prepend steers the file into Excel, so a
 * cell starting with `=`, `+`, `-`, `@`, tab, or CR would execute as a
 * formula on open. Prefixing a single quote makes Excel/LibreOffice/
 * Sheets treat it as literal text.
 *
 * @param {*} value Raw cell value.
 * @return {string} Escaped cell.
 */
export function csvCell( value ) {
	if ( value === undefined || value === null ) {
		return '';
	}
	let str = String( value );
	if ( /^[=+\-@\t\r]/.test( str ) ) {
		str = "'" + str;
	}
	if ( /[",\r\n]/.test( str ) ) {
		return '"' + str.replace( /"/g, '""' ) + '"';
	}
	return str;
}

/**
 * Local-time `YYYYMMDD-HHMMSS` stamp for export filenames.
 *
 * @return {string} Timestamp suitable for a filename suffix.
 */
export function timestampForFilename() {
	const now = new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return (
		now.getFullYear() +
		pad( now.getMonth() + 1 ) +
		pad( now.getDate() ) +
		'-' +
		pad( now.getHours() ) +
		pad( now.getMinutes() ) +
		pad( now.getSeconds() )
	);
}

/**
 * Wraps finished CSV text in a BOM-prefixed Blob and triggers a browser
 * download.
 *
 * The UTF-8 BOM makes Excel auto-detect the encoding instead of
 * rendering non-ASCII log content as Latin-1 mojibake. The revoke is
 * deferred so Safari has time to start the download — same pattern as
 * file-saver.
 *
 * @param {string} csv      Full CSV text (with trailing CRLF).
 * @param {string} filename Download filename.
 */
export function downloadCsv( csv, filename ) {
	const blob = new Blob( [ '﻿', csv ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = URL.createObjectURL( blob );
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = filename;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
