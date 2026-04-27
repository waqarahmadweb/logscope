/**
 * Shown in place of the virtualized list when there are no entries.
 * Distinguishes "loading" (LogViewer renders a skeleton instead) from
 * "no log file or no matching rows" (the everyday case on a fresh
 * install) and "REST call failed" (an actionable error).
 *
 * The error variant gets `role="alert"` so a screen reader announces it
 * immediately on render; the empty variant uses `role="status"` with a
 * polite live region — observable to AT users without interrupting.
 */
import { __ } from '@wordpress/i18n';

export default function EmptyState( { error, filtersActive = false } ) {
	if ( error ) {
		return (
			<div className="logscope-empty logscope-empty--error" role="alert">
				<p>{ __( 'Could not load logs.', 'logscope' ) }</p>
				<p className="logscope-empty__detail">{ error }</p>
			</div>
		);
	}

	return (
		<div className="logscope-empty" role="status" aria-live="polite">
			<p>
				{ filtersActive
					? __( 'No entries match the current filters.', 'logscope' )
					: __( 'No log entries to show.', 'logscope' ) }
			</p>
			<p className="logscope-empty__detail">
				{ filtersActive
					? __(
							'Try widening the date range or clearing the regex.',
							'logscope'
					  )
					: __(
							'When WordPress writes to debug.log, entries will appear here.',
							'logscope'
					  ) }
			</p>
		</div>
	);
}
