/**
 * Filter bar above the log viewer. Owns the ephemeral typed-but-not-yet-
 * applied state for the regex search (debounced 300ms before it lands in
 * the store) and renders controlled inputs for severity, date range, and
 * source. The "applied" filter values live in the store so the URL-sync
 * hook and the LogViewer's fetch effect see one consistent shape.
 *
 * The source dropdown is populated from distinct file paths in the
 * currently-loaded entries (per Phase 7.1 AC) — we don't ship a separate
 * REST endpoint for it because the same data is already in the response.
 * In grouped mode the items expose `file` directly; in list mode they do
 * the same. Either way, we read from `getLogs()`.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import { SEVERITY_TOKENS, severityLabel } from '../../utils/severity';
import useDebouncedValue from '../../hooks/useDebouncedValue';

const REGEX_DEBOUNCE_MS = 300;

export default function FilterBar() {
	const { filters, items } = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return { filters: store.getFilters(), items: store.getLogs() };
	}, [] );
	const { setFilters, resetFilters } = useDispatch( STORE_KEY );

	const [ regexInput, setRegexInput ] = useState( filters.q );
	const debouncedRegex = useDebouncedValue( regexInput, REGEX_DEBOUNCE_MS );

	// One-way sync: typed input → debounced → store. Store-side resets
	// (Reset button) feed back through the `filters.q` selector below.
	useEffect( () => {
		if ( debouncedRegex !== filters.q ) {
			setFilters( { q: debouncedRegex } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ debouncedRegex ] );

	useEffect( () => {
		if ( filters.q !== regexInput && filters.q === '' ) {
			setRegexInput( '' );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ filters.q ] );

	const sources = useMemo( () => {
		const seen = new Set();
		items.forEach( ( item ) => {
			if ( item?.file ) {
				seen.add( item.file );
			}
		} );
		return Array.from( seen ).sort();
	}, [ items ] );

	const toggleSeverity = ( token ) => {
		const next = filters.severity.includes( token )
			? filters.severity.filter( ( s ) => s !== token )
			: [ ...filters.severity, token ];
		setFilters( { severity: next } );
	};

	return (
		<div
			className="logscope-filter-bar"
			role="search"
			aria-label={ __( 'Log filters', 'logscope' ) }
		>
			<fieldset className="logscope-filter-bar__severity">
				<legend>{ __( 'Severity', 'logscope' ) }</legend>
				{ SEVERITY_TOKENS.map( ( token ) => (
					<label
						key={ token }
						className="logscope-filter-bar__severity-item"
					>
						<input
							type="checkbox"
							checked={ filters.severity.includes( token ) }
							onChange={ () => toggleSeverity( token ) }
						/>
						{ severityLabel( token ) }
					</label>
				) ) }
			</fieldset>

			<label className="logscope-filter-bar__field">
				<span>{ __( 'From', 'logscope' ) }</span>
				<input
					type="date"
					value={ filters.from }
					onChange={ ( e ) => setFilters( { from: e.target.value } ) }
				/>
			</label>

			<label className="logscope-filter-bar__field">
				<span>{ __( 'To', 'logscope' ) }</span>
				<input
					type="date"
					value={ filters.to }
					onChange={ ( e ) => setFilters( { to: e.target.value } ) }
				/>
			</label>

			<label className="logscope-filter-bar__field logscope-filter-bar__field--regex">
				<span>{ __( 'Search (regex)', 'logscope' ) }</span>
				<input
					type="search"
					value={ regexInput }
					onChange={ ( e ) => setRegexInput( e.target.value ) }
					maxLength={ 200 }
					placeholder={ __( 'e.g. wpdb::query', 'logscope' ) }
				/>
			</label>

			<label className="logscope-filter-bar__field">
				<span>{ __( 'Source', 'logscope' ) }</span>
				<select
					value={ filters.source }
					onChange={ ( e ) =>
						setFilters( { source: e.target.value } )
					}
				>
					<option value="">
						{ __( 'All sources', 'logscope' ) }
					</option>
					{ sources.map( ( path ) => (
						<option key={ path } value={ path }>
							{ path }
						</option>
					) ) }
				</select>
			</label>

			<Button
				variant="tertiary"
				onClick={ () => {
					setRegexInput( '' );
					resetFilters();
				} }
			>
				{ __( 'Reset', 'logscope' ) }
			</Button>
		</div>
	);
}
