/**
 * Alerts settings section — email + webhook configuration plus the
 * "Send test alert" button.
 *
 * Rendered as a child of SettingsPanel so the alert fields share the
 * same draft / save / dirty-tracking state as log_path and tail_interval
 * — there's no second form, just one save button at the bottom of the
 * Settings tab.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

import { STORE_KEY } from '../../store';

const DEDUP_WINDOW_MIN = 60;

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

export default function AlertsPanel() {
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

	const emailEnabled = Number( draft.alert_email_enabled ) === 1;
	const webhookEnabled = Number( draft.alert_webhook_enabled ) === 1;
	const anyEnabled = emailEnabled || webhookEnabled;

	const handleSendTest = () => {
		clearAlertTestResults();
		sendTestAlert();
	};

	return (
		<div className="logscope-alerts-panel">
			<h3 className="logscope-alerts-panel__heading">
				{ __( 'Alerts', 'logscope' ) }
			</h3>
			<p className="logscope-alerts-panel__intro">
				{ __(
					'Get notified when new fatal errors hit your debug log. Alerts are deduplicated per signature so a single recurring error does not flood your inbox.',
					'logscope'
				) }
			</p>

			<section className="logscope-alerts-panel__section">
				<ToggleControl
					label={ __( 'Send alerts by email', 'logscope' ) }
					checked={ emailEnabled }
					onChange={ ( next ) =>
						setSettingsDraft( {
							alert_email_enabled: next ? 1 : 0,
						} )
					}
					__nextHasNoMarginBottom
				/>
				{ emailEnabled && (
					<TextControl
						label={ __( 'Recipient email address', 'logscope' ) }
						type="email"
						value={ draft.alert_email_to || '' }
						onChange={ ( next ) =>
							setSettingsDraft( { alert_email_to: next } )
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
				) }
			</section>

			<section className="logscope-alerts-panel__section">
				<ToggleControl
					label={ __( 'Send alerts to a webhook', 'logscope' ) }
					checked={ webhookEnabled }
					onChange={ ( next ) =>
						setSettingsDraft( {
							alert_webhook_enabled: next ? 1 : 0,
						} )
					}
					__nextHasNoMarginBottom
				/>
				{ webhookEnabled && (
					<TextControl
						label={ __( 'Webhook URL', 'logscope' ) }
						type="url"
						value={ draft.alert_webhook_url || '' }
						onChange={ ( next ) =>
							setSettingsDraft( { alert_webhook_url: next } )
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
				) }
			</section>

			<section className="logscope-alerts-panel__section">
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
						'Each alert backend silences a given error signature for this many seconds after a successful send. Minimum 60 seconds.',
						'logscope'
					) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</section>

			<section className="logscope-alerts-panel__section">
				<div className="logscope-alerts-panel__test-row">
					<Button
						variant="secondary"
						onClick={ handleSendTest }
						isBusy={ isSendingTestAlert }
						disabled={ isSendingTestAlert || ! anyEnabled }
					>
						{ __( 'Send test alert', 'logscope' ) }
					</Button>
					{ ! anyEnabled && (
						<span className="logscope-alerts-panel__test-hint">
							{ __(
								'Enable email or webhook above first, then save.',
								'logscope'
							) }
						</span>
					) }
				</div>

				{ alertTestError && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s is the underlying error message. */
							__( 'Could not send test alert: %s', 'logscope' ),
							alertTestError
						) }
					</Notice>
				) }

				{ alertTestResults && (
					<ul className="logscope-alerts-panel__results">
						{ alertTestResults.map( ( result ) => (
							<li key={ result.dispatcher }>
								<Notice
									status={ outcomeStatus( result.outcome ) }
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
			</section>
		</div>
	);
}
