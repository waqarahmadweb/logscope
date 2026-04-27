/**
 * Shown in place of the virtualized list when there are no entries.
 * Distinguishes "loading" (suppress here, the viewer renders a spinner
 * instead) from "no log file or no matching rows", which is what users
 * see most often on a fresh install before any errors occur.
 */
import { __ } from '@wordpress/i18n';

export default function EmptyState( { error } ) {
	if ( error ) {
		return (
			<div className="logscope-empty logscope-empty--error" role="alert">
				<p>{ __( 'Could not load logs.', 'logscope' ) }</p>
				<p className="logscope-empty__detail">{ error }</p>
			</div>
		);
	}

	return (
		<div className="logscope-empty">
			<p>{ __( 'No log entries to show.', 'logscope' ) }</p>
			<p className="logscope-empty__detail">
				{ __(
					'When WordPress writes to debug.log, entries will appear here.',
					'logscope'
				) }
			</p>
		</div>
	);
}
