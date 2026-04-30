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
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import { SEVERITY_TOKENS, severityLabel } from '../../utils/severity';
import useDebouncedValue from '../../hooks/useDebouncedValue';
import { SHORTCUT, SHORTCUT_EVENT } from '../../shortcuts';

const REGEX_DEBOUNCE_MS = 300;

export default function FilterBar() {
	const { filters, items, viewMode, presets, isSavingPresets } = useSelect(
		( select ) => {
			const store = select( STORE_KEY );
			return {
				filters: store.getFilters(),
				items: store.getLogs(),
				viewMode: store.getViewMode(),
				presets: store.getPresets(),
				isSavingPresets: store.isSavingPresets(),
			};
		},
		[]
	);
	const {
		setFilters,
		resetFilters,
		setViewMode,
		fetchPresets,
		savePreset,
		deletePreset,
	} = useDispatch( STORE_KEY );

	useEffect( () => {
		fetchPresets();
	}, [ fetchPresets ] );

	const onSavePreset = () => {
		const name = window.prompt(
			__( 'Save current filters as preset:', 'logscope' ),
			''
		);
		if ( name === null ) {
			return;
		}
		const trimmed = name.trim();
		if ( '' === trimmed ) {
			return;
		}
		savePreset( trimmed, { ...filters, viewMode } );
	};

	const onLoadPreset = ( event ) => {
		const name = event.target.value;
		if ( '' === name ) {
			return;
		}
		const preset = presets.find( ( p ) => p.name === name );
		event.target.value = '';
		if ( ! preset ) {
			return;
		}
		const f = preset.filters || {};
		setFilters( {
			severity: Array.isArray( f.severity ) ? f.severity : [],
			from: f.from || '',
			to: f.to || '',
			q: f.q || '',
			source: f.source || '',
		} );
		if ( 'list' === f.viewMode || 'grouped' === f.viewMode ) {
			setViewMode( f.viewMode );
		}
	};

	const onDeletePreset = ( name ) => {
		if (
			window.confirm(
				/* translators: confirmation prompt before deleting a saved filter preset. */
				__( 'Delete this preset?', 'logscope' )
			)
		) {
			deletePreset( name );
		}
	};

	const [ regexInput, setRegexInput ] = useState( filters.q );
	const debouncedRegex = useDebouncedValue( regexInput, REGEX_DEBOUNCE_MS );
	const regexInputRef = useRef( null );

	// `/` shortcut from App focuses (and selects) this input. Listening here
	// rather than at App keeps the focus side-effect colocated with the
	// element it targets — App stays generic to a tab-switch event bus.
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return undefined;
		}
		const handler = ( event ) => {
			if ( event.detail !== SHORTCUT.FOCUS_SEARCH ) {
				return;
			}
			const el = regexInputRef.current;
			if ( el ) {
				el.focus();
				el.select();
			}
		};
		window.addEventListener( SHORTCUT_EVENT, handler );
		return () => window.removeEventListener( SHORTCUT_EVENT, handler );
	}, [] );

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
					ref={ regexInputRef }
					type="search"
					value={ regexInput }
					onChange={ ( e ) => setRegexInput( e.target.value ) }
					maxLength={ 200 }
					placeholder={ __( 'e.g. wpdb::query', 'logscope' ) }
					aria-label={ __(
						'Search log messages (regex)',
						'logscope'
					) }
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

			<div className="logscope-filter-bar__presets">
				<label className="logscope-filter-bar__field">
					<span>{ __( 'Preset', 'logscope' ) }</span>
					<select
						value=""
						onChange={ onLoadPreset }
						aria-label={ __( 'Load saved preset', 'logscope' ) }
					>
						<option value="">
							{ presets.length === 0
								? __( 'No saved presets', 'logscope' )
								: __( 'Load preset…', 'logscope' ) }
						</option>
						{ presets.map( ( preset ) => (
							<option key={ preset.name } value={ preset.name }>
								{ preset.name }
							</option>
						) ) }
					</select>
				</label>
				<Button
					variant="tertiary"
					onClick={ onSavePreset }
					disabled={ isSavingPresets }
				>
					{ __( 'Save preset', 'logscope' ) }
				</Button>
				{ presets.map( ( preset ) => (
					<button
						key={ preset.name }
						type="button"
						className="logscope-filter-bar__preset-delete"
						onClick={ () => onDeletePreset( preset.name ) }
						aria-label={
							__( 'Delete preset', 'logscope' ) +
							' — ' +
							preset.name
						}
						title={
							__( 'Delete preset', 'logscope' ) +
							' — ' +
							preset.name
						}
					>
						× { preset.name }
					</button>
				) ) }
			</div>

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
