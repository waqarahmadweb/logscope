/**
 * Filter bar above the log viewer. Owns the ephemeral typed-but-not-yet-
 * applied state for the regex search (debounced 300ms before it lands in
 * the store) and renders controlled inputs for severity, date range, and
 * source.
 *
 * Visually this is a single horizontal toolbar — severity pills with
 * leading colored dots, a flexed regex input with `/` keyboard hint and
 * 🔍 icon, and ghost dropdown pills for the date range, source, and
 * preset menu. A second strip below summarises the active filter state
 * (`Showing N of M · ⊙Fatal × · Clear filters`) and lets the user pop
 * individual filters off without scanning the toolbar for the right
 * pill. Both strips are part of the same `role="search"` region.
 *
 * The source dropdown is populated from distinct file paths in the
 * currently-loaded entries (per Phase 7.1 AC) — we don't ship a separate
 * REST endpoint for it because the same data is already in the response.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';

import { STORE_KEY } from '../../store';
import { SEVERITY_TOKENS, severityLabel } from '../../utils/severity';
import useDebouncedValue from '../../hooks/useDebouncedValue';
import { SHORTCUT, SHORTCUT_EVENT } from '../../shortcuts';

const REGEX_DEBOUNCE_MS = 300;

const DEFAULT_FILTERS_SHAPE = {
	severity: [],
	from: '',
	to: '',
	q: '',
	source: '',
};

export default function FilterBar() {
	const { filters, items, viewMode, presets, isSavingPresets, logsTotal } =
		useSelect( ( select ) => {
			const store = select( STORE_KEY );
			return {
				filters: store.getFilters(),
				items: store.getLogs(),
				viewMode: store.getViewMode(),
				presets: store.getPresets(),
				isSavingPresets: store.isSavingPresets(),
				logsTotal: store.getLogsTotal(),
			};
		}, [] );
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

	const [ regexInput, setRegexInput ] = useState( filters.q );
	const debouncedRegex = useDebouncedValue( regexInput, REGEX_DEBOUNCE_MS );
	const regexInputRef = useRef( null );

	// Each ghost-pill dropdown is an open/close popover anchored to the
	// pill itself. Keep the menu state local — the FilterBar is the only
	// component that needs to know which one is open.
	const [ openMenu, setOpenMenu ] = useState( null );
	const closeMenu = () => setOpenMenu( null );

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

	// Click-outside / Escape closes any open dropdown without needing each
	// menu to wire its own listener. Escape also nudges focus back to the
	// pill that opened the menu via [data-logscope-menu-anchor].
	useEffect( () => {
		if ( ! openMenu ) {
			return undefined;
		}
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				closeMenu();
				const anchor = document.querySelector(
					`[data-logscope-menu-anchor="${ openMenu }"]`
				);
				anchor?.focus();
			}
		};
		const onClick = ( e ) => {
			if (
				! e.target.closest( '.logscope-filter-bar__menu' ) &&
				! e.target.closest( '[data-logscope-menu-anchor]' )
			) {
				closeMenu();
			}
		};
		window.addEventListener( 'keydown', onKey );
		window.addEventListener( 'mousedown', onClick );
		return () => {
			window.removeEventListener( 'keydown', onKey );
			window.removeEventListener( 'mousedown', onClick );
		};
	}, [ openMenu ] );

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

	const onSavePreset = () => {
		const name = window.prompt(
			__( 'Save current filters as preset:', 'logscope' ),
			''
		);
		closeMenu();
		if ( name === null ) {
			return;
		}
		const trimmed = name.trim();
		if ( '' === trimmed ) {
			return;
		}
		savePreset( trimmed, { ...filters, viewMode } );
	};

	const onLoadPreset = ( name ) => {
		const preset = presets.find( ( p ) => p.name === name );
		closeMenu();
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

	const dateLabel =
		filters.from || filters.to
			? `${ filters.from || '…' } → ${ filters.to || '…' }`
			: __( 'Any date', 'logscope' );

	const sourceLabel = filters.source
		? truncateMiddle( filters.source, 36 )
		: __( 'All sources', 'logscope' );

	const filtersActive =
		filters.severity.length > 0 ||
		filters.from ||
		filters.to ||
		filters.q ||
		filters.source;

	const onClearAll = () => {
		setRegexInput( '' );
		resetFilters();
	};

	return (
		<div
			className="logscope-filter-bar"
			role="search"
			aria-label={ __( 'Log filters', 'logscope' ) }
		>
			<div className="logscope-filter-bar__toolbar">
				{ SEVERITY_TOKENS.map( ( token ) => {
					const on = filters.severity.includes( token );
					return (
						<button
							key={ token }
							type="button"
							className={
								'logscope-filter-bar__pill' +
								( on ? ' logscope-filter-bar__pill--on' : '' )
							}
							aria-pressed={ on }
							onClick={ () => toggleSeverity( token ) }
						>
							<span
								className={
									'logscope-filter-bar__dot logscope-filter-bar__dot--' +
									token
								}
								aria-hidden="true"
							/>
							{ severityLabel( token ) }
						</button>
					);
				} ) }

				<span className="logscope-filter-bar__sep" aria-hidden="true" />

				<div className="logscope-filter-bar__search">
					<span
						className="logscope-filter-bar__search-icon"
						aria-hidden="true"
					>
						🔍
					</span>
					<input
						ref={ regexInputRef }
						type="search"
						value={ regexInput }
						onChange={ ( e ) => setRegexInput( e.target.value ) }
						maxLength={ 200 }
						placeholder={ __(
							'Search regex…   e.g. wpdb::query',
							'logscope'
						) }
						aria-label={ __(
							'Search log messages (regex)',
							'logscope'
						) }
					/>
					<kbd
						className="logscope-filter-bar__kbd"
						title={ __(
							'Press / to focus the search input',
							'logscope'
						) }
					>
						/
					</kbd>
				</div>

				<span className="logscope-filter-bar__sep" aria-hidden="true" />

				{ /* Date range — ghost pill with popover */ }
				<div className="logscope-filter-bar__menu-wrap">
					<button
						type="button"
						data-logscope-menu-anchor="date"
						className={
							'logscope-filter-bar__pill logscope-filter-bar__pill--ghost' +
							( filters.from || filters.to
								? ' logscope-filter-bar__pill--active'
								: '' )
						}
						aria-haspopup="true"
						aria-expanded={ openMenu === 'date' }
						onClick={ () =>
							setOpenMenu( openMenu === 'date' ? null : 'date' )
						}
					>
						📅 { dateLabel } <span aria-hidden="true">▾</span>
					</button>
					{ openMenu === 'date' && (
						<div
							className="logscope-filter-bar__menu logscope-filter-bar__menu--date"
							role="dialog"
							aria-label={ __( 'Date range', 'logscope' ) }
						>
							<label>
								<span>{ __( 'From', 'logscope' ) }</span>
								<input
									type="date"
									value={ filters.from }
									onChange={ ( e ) =>
										setFilters( { from: e.target.value } )
									}
								/>
							</label>
							<label>
								<span>{ __( 'To', 'logscope' ) }</span>
								<input
									type="date"
									value={ filters.to }
									onChange={ ( e ) =>
										setFilters( { to: e.target.value } )
									}
								/>
							</label>
						</div>
					) }
				</div>

				{ /* Source — ghost pill with popover */ }
				<div className="logscope-filter-bar__menu-wrap">
					<button
						type="button"
						data-logscope-menu-anchor="source"
						className={
							'logscope-filter-bar__pill logscope-filter-bar__pill--ghost' +
							( filters.source
								? ' logscope-filter-bar__pill--active'
								: '' )
						}
						aria-haspopup="true"
						aria-expanded={ openMenu === 'source' }
						onClick={ () =>
							setOpenMenu(
								openMenu === 'source' ? null : 'source'
							)
						}
					>
						{ sourceLabel } <span aria-hidden="true">▾</span>
					</button>
					{ openMenu === 'source' && (
						<div
							className="logscope-filter-bar__menu logscope-filter-bar__menu--source"
							role="listbox"
							aria-label={ __( 'Source file', 'logscope' ) }
						>
							<button
								type="button"
								className="logscope-filter-bar__menu-item"
								onClick={ () => {
									setFilters( { source: '' } );
									closeMenu();
								} }
							>
								{ __( 'All sources', 'logscope' ) }
							</button>
							{ sources.length === 0 && (
								<div className="logscope-filter-bar__menu-empty">
									{ __(
										'No sources in current page.',
										'logscope'
									) }
								</div>
							) }
							{ sources.map( ( path ) => (
								<button
									key={ path }
									type="button"
									className={
										'logscope-filter-bar__menu-item' +
										( filters.source === path
											? ' logscope-filter-bar__menu-item--on'
											: '' )
									}
									onClick={ () => {
										setFilters( { source: path } );
										closeMenu();
									} }
									title={ path }
								>
									{ truncateMiddle( path, 60 ) }
								</button>
							) ) }
						</div>
					) }
				</div>

				{ /* Presets — ghost pill with popover */ }
				<div className="logscope-filter-bar__menu-wrap">
					<button
						type="button"
						data-logscope-menu-anchor="preset"
						className="logscope-filter-bar__pill logscope-filter-bar__pill--ghost"
						aria-haspopup="true"
						aria-expanded={ openMenu === 'preset' }
						onClick={ () =>
							setOpenMenu(
								openMenu === 'preset' ? null : 'preset'
							)
						}
					>
						＋ { __( 'Preset', 'logscope' ) }{ ' ' }
						<span aria-hidden="true">▾</span>
					</button>
					{ openMenu === 'preset' && (
						<div
							className="logscope-filter-bar__menu logscope-filter-bar__menu--preset"
							role="dialog"
							aria-label={ __( 'Filter presets', 'logscope' ) }
						>
							<button
								type="button"
								className="logscope-filter-bar__menu-item logscope-filter-bar__menu-item--primary"
								onClick={ onSavePreset }
								disabled={ isSavingPresets }
							>
								＋{ ' ' }
								{ __( 'Save current as preset…', 'logscope' ) }
							</button>
							{ presets.length === 0 && (
								<div className="logscope-filter-bar__menu-empty">
									{ __( 'No saved presets.', 'logscope' ) }
								</div>
							) }
							{ presets.map( ( preset ) => (
								<div
									key={ preset.name }
									className="logscope-filter-bar__menu-row"
								>
									<button
										type="button"
										className="logscope-filter-bar__menu-item logscope-filter-bar__menu-item--flex"
										onClick={ () =>
											onLoadPreset( preset.name )
										}
									>
										{ preset.name }
									</button>
									<button
										type="button"
										className="logscope-filter-bar__menu-delete"
										onClick={ () =>
											onDeletePreset( preset.name )
										}
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
										×
									</button>
								</div>
							) ) }
						</div>
					) }
				</div>
			</div>

			<FilterSummary
				filters={ filters }
				logsTotal={ logsTotal }
				items={ items }
				onPop={ ( patch ) => {
					if ( patch.q !== undefined ) {
						setRegexInput( '' );
					}
					setFilters( patch );
				} }
				onClearAll={ filtersActive ? onClearAll : null }
			/>
		</div>
	);
}

function FilterSummary( { filters, logsTotal, items, onPop, onClearAll } ) {
	const chips = [];
	filters.severity.forEach( ( token ) => {
		chips.push( {
			key: 'sev-' + token,
			label: severityLabel( token ),
			dot: token,
			onRemove: () =>
				onPop( {
					severity: filters.severity.filter( ( s ) => s !== token ),
				} ),
		} );
	} );
	if ( filters.q ) {
		chips.push( {
			key: 'q',
			label: '/' + truncateMiddle( filters.q, 24 ) + '/',
			onRemove: () => onPop( { q: '' } ),
		} );
	}
	if ( filters.from || filters.to ) {
		chips.push( {
			key: 'date',
			label: ( filters.from || '…' ) + ' → ' + ( filters.to || '…' ),
			onRemove: () => onPop( { from: '', to: '' } ),
		} );
	}
	if ( filters.source ) {
		chips.push( {
			key: 'src',
			label: truncateMiddle( filters.source, 28 ),
			onRemove: () => onPop( { source: '' } ),
		} );
	}

	const visible = items.length;

	return (
		<div className="logscope-filter-summary" aria-live="polite">
			<span>
				{ chips.length > 0
					? sprintf(
							/* translators: 1: visible entries on the page, 2: total matching filters across pagination. */
							__( 'Showing %1$d of %2$d', 'logscope' ),
							visible,
							logsTotal
					  )
					: sprintf(
							/* translators: %d is the total number of log entries. */
							__( '%d entries · no filters', 'logscope' ),
							logsTotal
					  ) }
			</span>
			{ chips.length > 0 && (
				<>
					<span
						className="logscope-filter-summary__sep"
						aria-hidden="true"
					>
						·
					</span>
					{ chips.map( ( chip ) => (
						<span
							key={ chip.key }
							className="logscope-filter-summary__chip"
						>
							{ chip.dot && (
								<span
									className={
										'logscope-filter-bar__dot logscope-filter-bar__dot--' +
										chip.dot
									}
									aria-hidden="true"
								/>
							) }
							{ chip.label }
							<button
								type="button"
								className="logscope-filter-summary__chip-remove"
								onClick={ chip.onRemove }
								aria-label={
									__( 'Remove filter', 'logscope' ) +
									' — ' +
									chip.label
								}
							>
								×
							</button>
						</span>
					) ) }
					{ onClearAll && (
						<>
							<span
								className="logscope-filter-summary__sep"
								aria-hidden="true"
							>
								·
							</span>
							<button
								type="button"
								className="logscope-filter-summary__clear"
								onClick={ onClearAll }
							>
								{ __( 'Clear filters', 'logscope' ) }
							</button>
						</>
					) }
				</>
			) }
		</div>
	);
}

function truncateMiddle( str, max ) {
	if ( ! str || str.length <= max ) {
		return str;
	}
	const head = Math.ceil( ( max - 1 ) / 2 );
	const tail = Math.floor( ( max - 1 ) / 2 );
	return str.slice( 0, head ) + '…' + str.slice( str.length - tail );
}

// Exported for tests; no other production caller.
export { DEFAULT_FILTERS_SHAPE };
