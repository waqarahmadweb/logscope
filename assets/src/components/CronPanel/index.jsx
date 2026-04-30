/**
 * Scheduled-scan settings section — toggle + interval input + read-only
 * "Last scan: …" status row. Mirrors AlertsPanel's structure so the
 * cron fields share the same draft / save / dirty-tracking state as
 * log_path, tail_interval, and the alert fields. Status numbers
 * (last_scanned_at, last_scanned_dispatched) come through the page-
 * load bootstrap rather than a polling endpoint — save the form to
 * pick up the latest tick.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl, ToggleControl } from '@wordpress/components';

import { STORE_KEY } from '../../store';

const INTERVAL_MIN = 1;
const INTERVAL_MAX = 1440;

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

export default function CronPanel() {
	const { draft } = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			draft: store.getSettingsDraft(),
		};
	}, [] );

	const { setSettingsDraft } = useDispatch( STORE_KEY );

	if ( ! draft ) {
		return null;
	}

	const enabled = Number( draft.cron_scan_enabled ) === 1;

	return (
		<div className="logscope-cron-panel">
			<h3 className="logscope-cron-panel__heading">
				{ __( 'Scheduled scan', 'logscope' ) }
			</h3>
			<p className="logscope-cron-panel__intro">
				{ __(
					'Periodically scan the log for new fatals and feed them to your alert backends. Off by default — enable when alerts are configured.',
					'logscope'
				) }
			</p>

			<section className="logscope-cron-panel__section">
				<ToggleControl
					label={ __( 'Enable scheduled scan', 'logscope' ) }
					checked={ enabled }
					onChange={ ( next ) =>
						setSettingsDraft( {
							cron_scan_enabled: next ? 1 : 0,
						} )
					}
					__nextHasNoMarginBottom
				/>
			</section>

			{ enabled && (
				<section className="logscope-cron-panel__section">
					<TextControl
						label={ __( 'Scan interval (minutes)', 'logscope' ) }
						type="number"
						value={ String(
							draft.cron_scan_interval_minutes ?? ''
						) }
						min={ INTERVAL_MIN }
						max={ INTERVAL_MAX }
						onChange={ ( next ) =>
							setSettingsDraft( {
								cron_scan_interval_minutes:
									next === '' ? '' : Number( next ),
							} )
						}
						help={ __(
							'How often to scan. Minimum 1 minute, maximum 24 hours (1440).',
							'logscope'
						) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</section>
			) }

			<p
				className="logscope-cron-panel__status"
				data-testid="cron-status-line"
			>
				{ statusLine() }
			</p>
		</div>
	);
}
