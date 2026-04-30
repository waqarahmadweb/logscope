/**
 * Settings tab — schema-driven editor for `log_path` and `tail_interval`.
 *
 * The panel keeps a `draft` slice in the store separate from the `values`
 * slice so the admin can edit freely (and bail with Reset) without
 * mutating the server-of-record copy. Save POSTs the whole draft, the
 * server returns the new authoritative shape, and the reducer copies it
 * back into both slots.
 *
 * The "Test path" button hits the side-effect-free `/settings/test-path`
 * REST route. The verdict it returns (ok/resolved/exists/readable/
 * writable/reason/allowed_roots) is rendered inline beneath the field so
 * the admin sees the resolved absolute path on success and a clear
 * rejection reason ("outside allowed directories", "parent-directory
 * segment", etc.) on failure — satisfying the Phase 8.2 AC that
 * `../../../etc/passwd` shows a rejection message.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, TextControl } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import { FormSkeleton } from '../Skeleton';
import AlertsPanel from '../AlertsPanel';

const TAIL_INTERVAL_MIN = 1;
const DEDUP_WINDOW_MIN = 60;
const ALERT_FIELD_KEYS = [
	'alert_email_enabled',
	'alert_email_to',
	'alert_webhook_enabled',
	'alert_webhook_url',
	'alert_dedup_window',
];

/**
 * Map server-side field-error codes to their translated, user-facing
 * copy. The store records codes (not strings) so user-facing wording
 * stays in the panel layer alongside every other __() call.
 */
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
		resetSettingsDraft,
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

	// Trim before compare so a trailing space on log_path doesn't toggle
	// the Save button into a dirty state for a no-op edit; the sanitiser
	// trims server-side anyway, so the trimmed value is what would land.
	const baseDirty =
		values &&
		( ( draft.log_path || '' ).trim() !==
			( values.log_path || '' ).trim() ||
			Number( draft.tail_interval ) !== Number( values.tail_interval ) );

	const alertDirty =
		values &&
		ALERT_FIELD_KEYS.some( ( key ) => {
			const a = draft[ key ];
			const b = values[ key ];
			if (
				key === 'alert_email_enabled' ||
				key === 'alert_webhook_enabled' ||
				key === 'alert_dedup_window'
			) {
				return Number( a ) !== Number( b );
			}
			return ( a || '' ).trim() !== ( b || '' ).trim();
		} );

	const isDirty = baseDirty || alertDirty;

	const handleSave = () => {
		saveSettings( {
			log_path: draft.log_path,
			tail_interval: Number( draft.tail_interval ) || TAIL_INTERVAL_MIN,
			alert_email_enabled:
				Number( draft.alert_email_enabled ) === 1 ? 1 : 0,
			alert_email_to: draft.alert_email_to || '',
			alert_webhook_enabled:
				Number( draft.alert_webhook_enabled ) === 1 ? 1 : 0,
			alert_webhook_url: draft.alert_webhook_url || '',
			alert_dedup_window:
				Number( draft.alert_dedup_window ) || DEDUP_WINDOW_MIN,
		} );
	};

	const handleTest = () => {
		testLogPath( draft.log_path );
	};

	return (
		<form
			className="logscope-settings-panel"
			onSubmit={ ( event ) => {
				event.preventDefault();
				handleSave();
			} }
		>
			<div className="logscope-settings-panel__stack">
				<TextControl
					label={ __( 'Custom log path', 'logscope' ) }
					help={ __(
						'Absolute path to your debug log. Leave empty to use the default WordPress location.',
						'logscope'
					) }
					value={ draft.log_path }
					onChange={ ( next ) => {
						setSettingsDraft( { log_path: next } );
						if ( testResult ) {
							clearTestResult();
						}
					} }
					autoComplete="off"
					spellCheck={ false }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>

				<div className="logscope-settings-panel__actions-row">
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

				{ testResult && <PathTestVerdict result={ testResult } /> }
				{ testError && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s is the underlying error message from the failed REST call. */
							__( 'Could not test the path: %s', 'logscope' ),
							testError
						) }
					</Notice>
				) }
				{ fieldErrors.log_path && (
					<Notice status="error" isDismissible={ false }>
						{ translateFieldError( fieldErrors.log_path ) }
					</Notice>
				) }

				<TextControl
					type="number"
					label={ __( 'Tail interval (seconds)', 'logscope' ) }
					help={ __(
						'How often the live tail polls for new entries. Minimum 1 second.',
						'logscope'
					) }
					value={ String( draft.tail_interval ?? '' ) }
					min={ TAIL_INTERVAL_MIN }
					onChange={ ( next ) =>
						setSettingsDraft( {
							tail_interval: next === '' ? '' : Number( next ),
						} )
					}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ fieldErrors.tail_interval && (
					<Notice status="error" isDismissible={ false }>
						{ translateFieldError( fieldErrors.tail_interval ) }
					</Notice>
				) }

				<AlertsPanel />

				{ saveError && Object.keys( fieldErrors ).length === 0 && (
					<Notice status="error" isDismissible={ false }>
						{ saveError }
					</Notice>
				) }

				<div className="logscope-settings-panel__actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ isSaving }
						disabled={ isSaving || ! isDirty }
					>
						{ __( 'Save settings', 'logscope' ) }
					</Button>
					<Button
						variant="tertiary"
						type="button"
						onClick={ resetSettingsDraft }
						disabled={ isSaving || ! isDirty }
					>
						{ __( 'Reset', 'logscope' ) }
					</Button>
				</div>
			</div>
		</form>
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
