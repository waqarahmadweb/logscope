/**
 * One prompt for every mute path (single group, bulk groups, bulk
 * entries). `window.prompt` doubles as the confirmation: Cancel returns
 * null and the caller mutes nothing, OK returns the (possibly empty)
 * reason. A bespoke <Modal> would add portal + focus-trap wiring for no
 * extra user value; reasons get edited at length in the Muted panel.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * @param {number} count How many signatures are about to be muted.
 * @return {string|null} Reason text, or null when the user cancelled.
 */
export default function promptMuteReason( count ) {
	if (
		typeof window === 'undefined' ||
		typeof window.prompt !== 'function'
	) {
		return '';
	}
	const message =
		count > 1
			? sprintf(
					/* translators: %d is the number of signatures about to be muted. */
					_n(
						'Mute %d signature? Optional reason (visible in Settings → Muted signatures):',
						'Mute %d signatures? Optional reason (visible in Settings → Muted signatures):',
						count,
						'logscope'
					),
					count
			  )
			: __(
					'Optional reason for muting this signature (visible in Settings → Muted signatures):',
					'logscope'
			  );
	// eslint-disable-next-line no-alert -- The native prompt is the chosen confirm+reason dialog (see header).
	return window.prompt( message, '' );
}
