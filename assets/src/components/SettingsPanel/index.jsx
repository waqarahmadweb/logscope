/**
 * Settings tab — schema-driven editor backed by the @wordpress/data store.
 *
 * The panel keeps a `draft` slice in the store separate from the `values`
 * slice so the admin can edit freely (and bail with Reset) without
 * mutating the server-of-record copy. Save POSTs the whole draft, the
 * server returns the new authoritative shape, and the reducer copies it
 * back into both slots.
 *
 * Layout: a sticky left sidenav anchors into four sections (Log file,
 * Monitoring & alerts, Display, Muted signatures) with a single Save
 * button at the top of the rail. An IntersectionObserver mirrors the
 * active section back into the nav so the highlight tracks scrolling.
 *
 * The save body covers every settings key the UI exposes (log + tail +
 * cron + alerts + display). Channel/cron toggles all flow through the
 * shared draft so a single Save persists everything atomically.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, TextControl } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import { FormSkeleton } from '../Skeleton';
import MonitoringPanel, { validateMonitoring } from '../MonitoringPanel';
import DisplayPanel, { validateDisplay } from '../DisplayPanel';
import MutedSignaturesPanel from '../MutedSignaturesPanel';

const TAIL_INTERVAL_MIN = 1;
const DEDUP_WINDOW_MIN = 60;
const SCAN_INTERVAL_MIN = 1;
const PER_PAGE_MIN = 10;

const SECTIONS = [
	{
		id: 'logscope-settings-log-file',
		label: __( 'Log file', 'logscope' ),
		icon: '📄',
	},
	{
		id: 'logscope-settings-monitoring',
		label: __( 'Monitoring & alerts', 'logscope' ),
		icon: '🔔',
	},
	{
		id: 'logscope-settings-display',
		label: __( 'Display', 'logscope' ),
		icon: '🎛',
	},
	{
		id: 'logscope-settings-muted',
		label: __( 'Muted signatures', 'logscope' ),
		icon: '🔕',
	},
];

function translateFieldError( code ) {
	switch ( code ) {
		case 'unknown_setting':
			return __( 'Unknown setting key.', 'logscope' );
		default:
			return code;
	}
}

export default function SettingsPanel() {
	const {
		values,
		draft,
		isLoading,
		isSaving,
		loadError,
		saveError,
		fieldErrors,
		testResult,
		testError,
		isTesting,
	} = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			values: store.getSettingsValues(),
			draft: store.getSettingsDraft(),
			isLoading: store.isLoadingSettings(),
			isSaving: store.isSavingSettings(),
			loadError: store.getSettingsLoadError(),
			saveError: store.getSettingsSaveError(),
			fieldErrors: store.getSettingsFieldErrors(),
			testResult: store.getPathTestResult(),
			testError: store.getPathTestError(),
			isTesting: store.isTestingPath(),
		};
	}, [] );

	const {
		fetchSettings,
		saveSettings,
		setSettingsDraft,
		testLogPath,
		clearTestResult,
	} = useDispatch( STORE_KEY );

	useEffect( () => {
		if ( ! values && ! isLoading && ! loadError ) {
			fetchSettings();
		}
	}, [ values, isLoading, loadError, fetchSettings ] );

	if ( isLoading && ! draft ) {
		return <FormSkeleton />;
	}

	if ( loadError && ! draft ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ sprintf(
					/* translators: %s is the underlying error message. */
					__( 'Could not load settings: %s', 'logscope' ),
					loadError
				) }
			</Notice>
		);
	}

	if ( ! draft ) {
		return null;
	}

	const monitoringValidation = validateMonitoring( draft );
	const displayValidation = validateDisplay( draft );

	// Trim before compare so a trailing space on log_path doesn't toggle
	// the Save button into a dirty state for a no-op edit.
	const isFieldDirty = ( key, isString ) => {
		if ( ! values ) {
			return false;
		}
		const a = draft[ key ];
		const b = values[ key ];
		if ( isString ) {
			return (
				( a || '' ).toString().trim() !== ( b || '' ).toString().trim()
			);
		}
		return Number( a ) !== Number( b );
	};

	const isDirty =
		isFieldDirty( 'log_path', true ) ||
		isFieldDirty( 'tail_interval', false ) ||
		isFieldDirty( 'cron_scan_enabled', false ) ||
		isFieldDirty( 'cron_scan_interval_minutes', false ) ||
		isFieldDirty( 'alert_email_enabled', false ) ||
		isFieldDirty( 'alert_email_to', true ) ||
		isFieldDirty( 'alert_webhook_enabled', false ) ||
		isFieldDirty( 'alert_webhook_url', true ) ||
		isFieldDirty( 'alert_dedup_window', false ) ||
		isFieldDirty( 'default_per_page', false ) ||
		isFieldDirty( 'default_severity_filter', true ) ||
		isFieldDirty( 'timestamp_tz', true );

	const validationOk = monitoringValidation.valid && displayValidation.valid;

	const handleSave = () => {
		saveSettings( {
			log_path: draft.log_path,
			tail_interval: Number( draft.tail_interval ) || TAIL_INTERVAL_MIN,
			cron_scan_enabled: Number( draft.cron_scan_enabled ) === 1 ? 1 : 0,
			cron_scan_interval_minutes:
				Number( draft.cron_scan_interval_minutes ) || SCAN_INTERVAL_MIN,
			alert_email_enabled:
				Number( draft.alert_email_enabled ) === 1 ? 1 : 0,
			alert_email_to: draft.alert_email_to || '',
			alert_webhook_enabled:
				Number( draft.alert_webhook_enabled ) === 1 ? 1 : 0,
			alert_webhook_url: draft.alert_webhook_url || '',
			alert_dedup_window:
				Number( draft.alert_dedup_window ) || DEDUP_WINDOW_MIN,
			default_per_page: Number( draft.default_per_page ) || PER_PAGE_MIN,
			default_severity_filter: draft.default_severity_filter || '',
			timestamp_tz: draft.timestamp_tz === 'utc' ? 'utc' : 'site',
		} );
	};

	const handleTest = () => {
		testLogPath( draft.log_path );
	};

	return (
		<form
			className="logscope-settings-panel"
			aria-busy={ isSaving }
			onSubmit={ ( event ) => {
				event.preventDefault();
				if ( ! isDirty || isSaving || ! validationOk ) {
					return;
				}
				handleSave();
			} }
		>
			<div className="logscope-settings-panel__layout">
				<SettingsNav
					isDirty={ isDirty }
					isSaving={ isSaving }
					isValid={ validationOk }
				/>

				<div className="logscope-settings-panel__pane">
					<section
						id={ SECTIONS[ 0 ].id }
						className="logscope-settings-panel__section"
					>
						<h2 className="logscope-settings-panel__section-title">
							{ __( 'Log file', 'logscope' ) }
						</h2>
						<p className="logscope-settings-panel__section-lead">
							{ __(
								'Where Logscope reads PHP errors from, and how often the live tail polls.',
								'logscope'
							) }
						</p>

						<div className="logscope-settings-panel__field">
							<div className="logscope-settings-panel__field-row">
								<TextControl
									label={ __(
										'Custom log path',
										'logscope'
									) }
									help={ __(
										'Absolute path to your debug log. Leave empty to use the default WordPress location.',
										'logscope'
									) }
									value={ draft.log_path }
									onChange={ ( next ) => {
										setSettingsDraft( {
											log_path: next,
										} );
										if ( testResult ) {
											clearTestResult();
										}
									} }
									autoComplete="off"
									spellCheck={ false }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								<Button
									variant="secondary"
									onClick={ handleTest }
									isBusy={ isTesting }
									disabled={
										isTesting ||
										! draft.log_path ||
										! draft.log_path.trim()
									}
								>
									{ __( 'Test path', 'logscope' ) }
								</Button>
							</div>

							{ testResult && (
								<div style={ { marginTop: 10 } }>
									<PathTestVerdict result={ testResult } />
								</div>
							) }
							{ testError && (
								<div style={ { marginTop: 10 } }>
									<Notice
										status="warning"
										isDismissible={ false }
									>
										{ sprintf(
											/* translators: %s is the underlying error message from the failed REST call. */
											__(
												'Could not test the path: %s',
												'logscope'
											),
											testError
										) }
									</Notice>
								</div>
							) }
							{ fieldErrors.log_path && (
								<div style={ { marginTop: 10 } }>
									<Notice
										status="error"
										isDismissible={ false }
									>
										{ translateFieldError(
											fieldErrors.log_path
										) }
									</Notice>
								</div>
							) }
						</div>

						<div className="logscope-settings-panel__field">
							<TextControl
								type="number"
								label={ __(
									'Tail interval (seconds)',
									'logscope'
								) }
								help={ __(
									'How often the live tail polls for new entries. Minimum 1 second.',
									'logscope'
								) }
								value={ String( draft.tail_interval ?? '' ) }
								min={ TAIL_INTERVAL_MIN }
								onChange={ ( next ) =>
									setSettingsDraft( {
										tail_interval:
											next === '' ? '' : Number( next ),
									} )
								}
								__next40pxDefaultSize
								__nextHasNoMarginBottom
							/>
							{ fieldErrors.tail_interval && (
								<div style={ { marginTop: 10 } }>
									<Notice
										status="error"
										isDismissible={ false }
									>
										{ translateFieldError(
											fieldErrors.tail_interval
										) }
									</Notice>
								</div>
							) }
						</div>

						<DebugConstantsCard />
					</section>

					<section
						id={ SECTIONS[ 1 ].id }
						className="logscope-settings-panel__section"
					>
						<MonitoringPanel />
					</section>

					<section
						id={ SECTIONS[ 2 ].id }
						className="logscope-settings-panel__section"
					>
						<DisplayPanel />
					</section>

					<section
						id={ SECTIONS[ 3 ].id }
						className="logscope-settings-panel__section"
					>
						<MutedSignaturesPanel />
					</section>

					{ saveError && Object.keys( fieldErrors ).length === 0 && (
						<Notice status="error" isDismissible={ false }>
							{ saveError }
						</Notice>
					) }
				</div>
			</div>
		</form>
	);
}

/**
 * Read-only card that shows the current state of the WP debug constants.
 * Editing them requires a wp-config.php change, which the plugin does not
 * perform automatically — this is a diagnostic surface only.
 */
function DebugConstantsCard() {
	const constants = ( typeof window !== 'undefined' &&
		window.LogscopeAdmin?.debugConstants ) || {
		wp_debug: false,
		wp_debug_log: false,
		wp_debug_display: false,
	};

	const rows = [
		{
			key: 'wp_debug',
			label: __( 'WP_DEBUG', 'logscope' ),
			value: Boolean( constants.wp_debug ),
			recommended: true,
			tip: __(
				'Master debug switch. Must be on for WordPress to record errors anywhere.',
				'logscope'
			),
		},
		{
			key: 'wp_debug_log',
			label: __( 'WP_DEBUG_LOG', 'logscope' ),
			value: Boolean( constants.wp_debug_log ),
			recommended: true,
			tip: __(
				'Writes errors to wp-content/debug.log so Logscope has something to read. Required.',
				'logscope'
			),
		},
		{
			key: 'wp_debug_display',
			label: __( 'WP_DEBUG_DISPLAY', 'logscope' ),
			value: Boolean( constants.wp_debug_display ),
			// Recommended OFF — this is a security/UX hazard on production.
			recommended: false,
			tip: __(
				'Echoes errors into the page response. Should be off on a public site — leaks paths and may break layouts.',
				'logscope'
			),
		},
	];

	const anyMisaligned = rows.some( ( row ) => row.value !== row.recommended );

	return (
		<div className="logscope-debug-constants">
			<h3 className="logscope-debug-constants__heading">
				{ __( 'WordPress debug constants', 'logscope' ) }
			</h3>
			<p className="logscope-debug-constants__lead">
				{ __(
					'Read-only view of how WordPress is configured for debug logging. Change these by editing wp-config.php.',
					'logscope'
				) }
			</p>
			<ul className="logscope-debug-constants__list">
				{ rows.map( ( row ) => {
					const aligned = row.value === row.recommended;
					return (
						<li
							key={ row.key }
							className={
								'logscope-debug-constants__row' +
								( aligned
									? ' logscope-debug-constants__row--ok'
									: ' logscope-debug-constants__row--warn' )
							}
						>
							<div className="logscope-debug-constants__row-head">
								<code className="logscope-debug-constants__name">
									{ row.label }
								</code>
								<span className="logscope-debug-constants__state">
									{ row.value
										? __( 'true', 'logscope' )
										: __( 'false', 'logscope' ) }
								</span>
								<span className="logscope-debug-constants__rec">
									{ sprintf(
										/* translators: %s: 'true' or 'false' */
										__( 'recommended: %s', 'logscope' ),
										row.recommended
											? __( 'true', 'logscope' )
											: __( 'false', 'logscope' )
									) }
								</span>
							</div>
							<p className="logscope-debug-constants__tip">
								{ row.tip }
							</p>
						</li>
					);
				} ) }
			</ul>
			{ anyMisaligned && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'One or more debug constants are not at the recommended setting. Edit wp-config.php to change them — Logscope does not modify your WordPress configuration.',
						'logscope'
					) }
				</Notice>
			) }
		</div>
	);
}

function SettingsNav( { isDirty, isSaving, isValid } ) {
	const [ activeId, setActiveId ] = useState( SECTIONS[ 0 ].id );
	const lockUntilRef = useRef( 0 );

	// Mirror the section closest to the top of the viewport into the nav
	// highlight. rootMargin pushes the "active band" into the upper
	// portion of the viewport so a section becomes active as its top
	// crosses ~30% down the screen, not when it's already half-scrolled.
	useEffect( () => {
		const elements = SECTIONS.map( ( s ) =>
			document.getElementById( s.id )
		).filter( Boolean );
		if ( elements.length === 0 ) {
			return undefined;
		}
		const observer = new IntersectionObserver(
			( entries ) => {
				if ( Date.now() < lockUntilRef.current ) {
					return;
				}
				const visible = entries
					.filter( ( e ) => e.isIntersecting )
					.sort(
						( a, b ) =>
							a.boundingClientRect.top - b.boundingClientRect.top
					);
				if ( visible.length > 0 ) {
					setActiveId( visible[ 0 ].target.id );
				}
			},
			{ rootMargin: '-20% 0px -60% 0px', threshold: 0 }
		);
		elements.forEach( ( el ) => observer.observe( el ) );
		return () => observer.disconnect();
	}, [] );

	const handleClick = ( id ) => ( event ) => {
		event.preventDefault();
		const target = document.getElementById( id );
		if ( ! target ) {
			return;
		}
		// Briefly suppress the observer so the click-driven highlight
		// isn't fought by intersection events fired during the smooth
		// scroll — otherwise the highlight can flicker through every
		// section the scroll passes.
		setActiveId( id );
		lockUntilRef.current = Date.now() + 700;
		target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	};

	return (
		<aside
			className="logscope-settings-panel__nav"
			aria-label={ __( 'Settings sections', 'logscope' ) }
		>
			<ul className="logscope-settings-panel__nav-list">
				{ SECTIONS.map( ( section ) => {
					const on = section.id === activeId;
					return (
						<li key={ section.id }>
							<a
								href={ `#${ section.id }` }
								className={
									'logscope-settings-panel__nav-link' +
									( on
										? ' logscope-settings-panel__nav-link--on'
										: '' )
								}
								onClick={ handleClick( section.id ) }
							>
								<span
									className="logscope-settings-panel__nav-icon"
									aria-hidden="true"
								>
									{ section.icon }
								</span>
								<span>{ section.label }</span>
							</a>
						</li>
					);
				} ) }
			</ul>
			<div className="logscope-settings-panel__nav-sep" />
			<div className="logscope-settings-panel__nav-actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty || ! isValid }
				>
					{ isSaving
						? __( 'Saving…', 'logscope' )
						: __( 'Save settings', 'logscope' ) }
				</Button>
				{ isDirty && (
					<div className="logscope-settings-panel__nav-dirty">
						{ __( 'Unsaved changes', 'logscope' ) }
					</div>
				) }
				{ isDirty && ! isValid && (
					<div className="logscope-settings-panel__nav-invalid">
						{ __(
							'Fix the highlighted fields to enable Save.',
							'logscope'
						) }
					</div>
				) }
			</div>
		</aside>
	);
}

function PathTestVerdict( { result } ) {
	if ( result.ok ) {
		const detail = result.exists
			? sprintf(
					/* translators: %s is the canonicalised absolute path. */
					__( 'Resolved: %s', 'logscope' ),
					result.resolved
			  )
			: __(
					'Path is allowed. The file does not exist yet — it will be created on first write.',
					'logscope'
			  );
		return (
			<Notice status="success" isDismissible={ false }>
				<strong>{ __( 'Path looks good.', 'logscope' ) }</strong>{ ' ' }
				{ detail }
				{ result.exists && ! result.readable && (
					<>
						{ ' · ' }
						{ __( 'Not currently readable.', 'logscope' ) }
					</>
				) }
				{ ( result.exists
					? ! result.writable
					: ! result.parent_writable ) && (
					<>
						{ ' · ' }
						{ __( 'Not currently writable.', 'logscope' ) }
					</>
				) }
			</Notice>
		);
	}

	return (
		<Notice status="error" isDismissible={ false }>
			<strong>{ __( 'Path rejected.', 'logscope' ) }</strong>{ ' ' }
			{ result.reason ||
				__( 'Path is outside the allowed directories.', 'logscope' ) }
			{ Array.isArray( result.allowed_roots ) &&
				result.allowed_roots.length > 0 && (
					<div className="logscope-settings-panel__allowed-roots">
						{ __( 'Allowed roots:', 'logscope' ) }{ ' ' }
						<code>{ result.allowed_roots.join( ', ' ) }</code>
					</div>
				) }
		</Notice>
	);
}
