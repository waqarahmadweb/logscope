/**
 * Monitoring & alerts settings section — single master toggle that gates
 * the scheduled scan plus the email / webhook destinations it feeds.
 *
 * Replaces the previous split between "Alerts" (where notifications go)
 * and "Scheduled scan" (when the log gets checked). They are two halves
 * of the same feature and the split made the dependency between them
 * easy to misconfigure (channels on but no scan, or scan on with no
 * channel). The merged surface gates everything behind one toggle and
 * validates the combination on save.
 *
 * Master toggle = `cron_scan_enabled`. No new schema field.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { STORE_KEY } from '../../store';

const SCAN_INTERVAL_MIN = 1;
const SCAN_INTERVAL_MAX = 1440;
const DEDUP_WINDOW_MIN = 60;
const DEDUP_WINDOW_DEFAULT = 1800;
// Loose RFC 5322-ish gate. The PHP sanitiser uses sanitize_email() for
// the authoritative check; this only exists to catch obvious mistakes
// (no @, trailing space) before the user clicks Save.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export const MONITORING_FIELD_KEYS = [
	'cron_scan_enabled',
	'cron_scan_interval_minutes',
	'alert_email_enabled',
	'alert_email_to',
	'alert_webhook_enabled',
	'alert_webhook_url',
	'alert_dedup_window',
];

/**
 * Pure validator over a draft. Returns `{ valid, errors }` where `errors`
 * is keyed by the same field names the panel renders, plus a synthetic
 * `master` slot for cross-field problems (e.g. master on but no channel).
 *
 * Exported so SettingsPanel can gate the Save button without re-deriving
 * the rules.
 *
 * @param {object} draft Settings draft from the store.
 * @return {{valid: boolean, errors: object}}
 */
export function validateMonitoring( draft ) {
	const errors = {};
	if ( ! draft ) {
		return { valid: true, errors };
	}

	const enabled = Number( draft.cron_scan_enabled ) === 1;
	if ( ! enabled ) {
		// Master off: channel/interval values are preserved on disk but
		// not exercised. Nothing to validate.
		return { valid: true, errors };
	}

	const interval = Number( draft.cron_scan_interval_minutes );
	if (
		! Number.isFinite( interval ) ||
		interval < SCAN_INTERVAL_MIN ||
		interval > SCAN_INTERVAL_MAX
	) {
		errors.cron_scan_interval_minutes = sprintf(
			/* translators: 1: minimum minutes, 2: maximum minutes. */
			__(
				'Scan interval must be between %1$d and %2$d minutes.',
				'logscope'
			),
			SCAN_INTERVAL_MIN,
			SCAN_INTERVAL_MAX
		);
	}

	const emailOn = Number( draft.alert_email_enabled ) === 1;
	const webhookOn = Number( draft.alert_webhook_enabled ) === 1;
	if ( ! emailOn && ! webhookOn ) {
		errors.master = __(
			'Enable at least one channel — email or webhook — so alerts have somewhere to go.',
			'logscope'
		);
	}

	if ( emailOn ) {
		const value = ( draft.alert_email_to || '' ).trim();
		if ( '' === value ) {
			errors.alert_email_to = __(
				'Recipient email is required when email alerts are on.',
				'logscope'
			);
		} else if ( ! EMAIL_RE.test( value ) ) {
			errors.alert_email_to = __(
				'Enter a valid email address.',
				'logscope'
			);
		}
	}

	if ( webhookOn ) {
		const value = ( draft.alert_webhook_url || '' ).trim();
		if ( '' === value ) {
			errors.alert_webhook_url = __(
				'Webhook URL is required when webhook alerts are on.',
				'logscope'
			);
		} else if ( ! /^https?:\/\//i.test( value ) ) {
			errors.alert_webhook_url = __(
				'URL must start with http:// or https://.',
				'logscope'
			);
		}
	}

	const dedup = Number( draft.alert_dedup_window );
	if ( ! Number.isFinite( dedup ) || dedup < DEDUP_WINDOW_MIN ) {
		errors.alert_dedup_window = sprintf(
			/* translators: %d: minimum dedup window in seconds. */
			__( 'Dedup window must be at least %d seconds.', 'logscope' ),
			DEDUP_WINDOW_MIN
		);
	}

	return { valid: Object.keys( errors ).length === 0, errors };
}

function outcomeLabel( outcome ) {
	switch ( outcome ) {
		case 'sent':
			return __( 'Sent', 'logscope' );
		case 'skipped':
			return __( 'Skipped (disabled)', 'logscope' );
		case 'failed':
			return __( 'Failed', 'logscope' );
		case 'deduped':
			return __( 'Rate-limited', 'logscope' );
		default:
			return outcome;
	}
}

function outcomeStatus( outcome ) {
	if ( 'sent' === outcome ) {
		return 'success';
	}
	if ( 'failed' === outcome ) {
		return 'error';
	}
	return 'info';
}

function formatRelative( seconds ) {
	if ( seconds < 60 ) {
		return sprintf(
			/* translators: %d: seconds. */
			_n( '%d second ago', '%d seconds ago', seconds, 'logscope' ),
			seconds
		);
	}
	const minutes = Math.round( seconds / 60 );
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: minutes. */
			_n( '%d minute ago', '%d minutes ago', minutes, 'logscope' ),
			minutes
		);
	}
	const hours = Math.round( minutes / 60 );
	if ( hours < 24 ) {
		return sprintf(
			/* translators: %d: hours. */
			_n( '%d hour ago', '%d hours ago', hours, 'logscope' ),
			hours
		);
	}
	const days = Math.round( hours / 24 );
	return sprintf(
		/* translators: %d: days. */
		_n( '%d day ago', '%d days ago', days, 'logscope' ),
		days
	);
}

function statusLine() {
	const status = window.LogscopeAdmin?.cronStatus;
	if ( ! status || ! status.lastScannedAt ) {
		return __( 'Last scan: never', 'logscope' );
	}
	const now = Math.floor( Date.now() / 1000 );
	const delta = Math.max( 0, now - Number( status.lastScannedAt ) );
	const dispatched = Number( status.lastScannedDispatched ) || 0;
	const dispatchedFragment = sprintf(
		/* translators: %d: number of fatal-error groups dispatched on the last scan. */
		_n(
			'%d fatal dispatched',
			'%d fatals dispatched',
			dispatched,
			'logscope'
		),
		dispatched
	);
	return sprintf(
		/* translators: 1: relative time since the last scan, 2: dispatch count fragment. */
		__( 'Last scan: %1$s · %2$s', 'logscope' ),
		formatRelative( delta ),
		dispatchedFragment
	);
}

export default function MonitoringPanel() {
	const { draft, isSendingTestAlert, alertTestResults, alertTestError } =
		useSelect( ( select ) => {
			const store = select( STORE_KEY );
			return {
				draft: store.getSettingsDraft(),
				isSendingTestAlert: store.isSendingTestAlert(),
				alertTestResults: store.getAlertTestResults(),
				alertTestError: store.getAlertTestError(),
			};
		}, [] );

	const { setSettingsDraft, sendTestAlert, clearAlertTestResults } =
		useDispatch( STORE_KEY );

	if ( ! draft ) {
		return null;
	}

	const enabled = Number( draft.cron_scan_enabled ) === 1;
	const emailEnabled = Number( draft.alert_email_enabled ) === 1;
	const webhookEnabled = Number( draft.alert_webhook_enabled ) === 1;
	const anyChannel = emailEnabled || webhookEnabled;

	const { errors } = validateMonitoring( draft );

	const handleSendTest = () => {
		clearAlertTestResults();
		// Persist any dirty alert fields first so the server-side test
		// reads the toggles the user just flipped instead of stale values.
		// We always pass the current draft values for the channel fields;
		// the thunk no-ops the save when the body matches the persisted
		// state, so this is safe to call even on a clean draft.
		sendTestAlert( {
			alert_email_enabled: emailEnabled ? 1 : 0,
			alert_email_to: draft.alert_email_to || '',
			alert_webhook_enabled: webhookEnabled ? 1 : 0,
			alert_webhook_url: draft.alert_webhook_url || '',
			alert_dedup_window:
				Number( draft.alert_dedup_window ) || DEDUP_WINDOW_DEFAULT,
		} );
	};

	return (
		<div className="logscope-monitoring-panel">
			<h2 className="logscope-settings-panel__section-title">
				{ __( 'Monitoring & alerts', 'logscope' ) }
			</h2>
			<p className="logscope-settings-panel__section-lead">
				{ __(
					'Periodically scan the debug log for new fatal errors and notify you through the channels below. Off by default — turn this on after configuring at least one channel.',
					'logscope'
				) }
			</p>

			<div className="logscope-monitoring-panel__field">
				<ToggleControl
					label={ __( 'Watch the log and send alerts', 'logscope' ) }
					checked={ enabled }
					onChange={ ( next ) =>
						setSettingsDraft( {
							cron_scan_enabled: next ? 1 : 0,
						} )
					}
					__nextHasNoMarginBottom
				/>
				{ enabled && (
					<p
						className="logscope-monitoring-panel__status"
						data-testid="cron-status-line"
					>
						{ statusLine() }
					</p>
				) }
				{ errors.master && (
					<div style={ { marginTop: 10 } }>
						<Notice status="error" isDismissible={ false }>
							{ errors.master }
						</Notice>
					</div>
				) }
			</div>

			{ enabled && (
				<>
					<div className="logscope-monitoring-panel__field">
						<TextControl
							label={ __(
								'Scan interval (minutes)',
								'logscope'
							) }
							type="number"
							value={ String(
								draft.cron_scan_interval_minutes ?? ''
							) }
							min={ SCAN_INTERVAL_MIN }
							max={ SCAN_INTERVAL_MAX }
							onChange={ ( next ) =>
								setSettingsDraft( {
									cron_scan_interval_minutes:
										next === '' ? '' : Number( next ),
								} )
							}
							help={ __(
								'How often the log is checked. A repeating fatal will not email you per scan — see Dedup window below. Minimum 1 minute, maximum 24 hours (1440).',
								'logscope'
							) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						{ errors.cron_scan_interval_minutes && (
							<div style={ { marginTop: 10 } }>
								<Notice status="error" isDismissible={ false }>
									{ errors.cron_scan_interval_minutes }
								</Notice>
							</div>
						) }
					</div>

					<h3 className="logscope-monitoring-panel__channels-heading">
						{ __( 'Channels', 'logscope' ) }
					</h3>

					<div className="logscope-monitoring-panel__field">
						<ToggleControl
							label={ __( 'Email', 'logscope' ) }
							checked={ emailEnabled }
							onChange={ ( next ) =>
								setSettingsDraft( {
									alert_email_enabled: next ? 1 : 0,
								} )
							}
							__nextHasNoMarginBottom
						/>
						{ emailEnabled && (
							<>
								<TextControl
									label={ __(
										'Recipient email address',
										'logscope'
									) }
									type="email"
									value={ draft.alert_email_to || '' }
									onChange={ ( next ) =>
										setSettingsDraft( {
											alert_email_to: next,
										} )
									}
									help={ __(
										'Where alert emails are sent. Uses your site email transport (wp_mail), so SMTP plugins apply.',
										'logscope'
									) }
									autoComplete="off"
									spellCheck={ false }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								{ errors.alert_email_to && (
									<div style={ { marginTop: 10 } }>
										<Notice
											status="error"
											isDismissible={ false }
										>
											{ errors.alert_email_to }
										</Notice>
									</div>
								) }
							</>
						) }
					</div>

					<div className="logscope-monitoring-panel__field">
						<ToggleControl
							label={ __( 'Webhook', 'logscope' ) }
							checked={ webhookEnabled }
							onChange={ ( next ) =>
								setSettingsDraft( {
									alert_webhook_enabled: next ? 1 : 0,
								} )
							}
							__nextHasNoMarginBottom
						/>
						{ webhookEnabled && (
							<>
								<TextControl
									label={ __( 'Webhook URL', 'logscope' ) }
									type="url"
									value={ draft.alert_webhook_url || '' }
									onChange={ ( next ) =>
										setSettingsDraft( {
											alert_webhook_url: next,
										} )
									}
									help={ __(
										'Receives a JSON POST per fatal. Must start with https:// (or http://). Use the logscope/webhook_payload filter to reshape for Slack, Discord, or Teams.',
										'logscope'
									) }
									autoComplete="off"
									spellCheck={ false }
									__next40pxDefaultSize
									__nextHasNoMarginBottom
								/>
								{ errors.alert_webhook_url && (
									<div style={ { marginTop: 10 } }>
										<Notice
											status="error"
											isDismissible={ false }
										>
											{ errors.alert_webhook_url }
										</Notice>
									</div>
								) }
							</>
						) }
					</div>

					<div className="logscope-monitoring-panel__field">
						<TextControl
							label={ __( 'Dedup window (seconds)', 'logscope' ) }
							type="number"
							value={ String( draft.alert_dedup_window ?? '' ) }
							min={ DEDUP_WINDOW_MIN }
							onChange={ ( next ) =>
								setSettingsDraft( {
									alert_dedup_window:
										next === '' ? '' : Number( next ),
								} )
							}
							help={ __(
								'Minimum gap between repeat alerts of the same error. If a fatal keeps firing across many scans, this controls how often Logscope re-notifies you. Default 1800 (30 min); minimum 60.',
								'logscope'
							) }
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
						{ errors.alert_dedup_window && (
							<div style={ { marginTop: 10 } }>
								<Notice status="error" isDismissible={ false }>
									{ errors.alert_dedup_window }
								</Notice>
							</div>
						) }
					</div>

					<div className="logscope-monitoring-panel__test-row">
						<Button
							variant="secondary"
							onClick={ handleSendTest }
							isBusy={ isSendingTestAlert }
							disabled={ isSendingTestAlert || ! anyChannel }
						>
							{ __( 'Send test alert', 'logscope' ) }
						</Button>
					</div>

					{ alertTestError && (
						<Notice status="warning" isDismissible={ false }>
							{ sprintf(
								/* translators: %s is the underlying error message. */
								__(
									'Could not send test alert: %s',
									'logscope'
								),
								alertTestError
							) }
						</Notice>
					) }

					{ alertTestResults && (
						<ul className="logscope-monitoring-panel__results">
							{ alertTestResults.map( ( result ) => (
								<li key={ result.dispatcher }>
									<Notice
										status={ outcomeStatus(
											result.outcome
										) }
										isDismissible={ false }
									>
										<strong>{ result.dispatcher }</strong>
										{ ' — ' }
										{ outcomeLabel( result.outcome ) }
									</Notice>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
		</div>
	);
}
