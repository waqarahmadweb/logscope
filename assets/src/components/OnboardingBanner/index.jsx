/**
 * Dismissible-per-session banner that surfaces above the FilterBar
 * when the host is missing `WP_DEBUG_LOG`. Detects the condition by
 * reading the `/diagnostics` snapshot the LogViewer fetches on mount,
 * and shows the exact lines an admin needs to add to wp-config.php.
 *
 * Per-session dismissal lives in `sessionStorage` (not the store)
 * because it is purely UI state and should not be replayed across
 * tabs or wp-admin reloads in a different session — closing and
 * reopening wp-admin gives the admin a fresh chance to act on it.
 *
 * Auto-edit of wp-config.php is intentionally out of scope here;
 * the ROADMAP defers it to post-1.0.
 */
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { STORE_KEY } from '../../store';

const DISMISS_KEY = 'logscope:onboarding-dismissed';

function readDismissedFlag() {
	try {
		return (
			typeof window !== 'undefined' &&
			window.sessionStorage &&
			window.sessionStorage.getItem( DISMISS_KEY ) === '1'
		);
	} catch ( e ) {
		// sessionStorage can throw under privacy modes / cross-origin
		// iframes; treat as "not dismissed" rather than crashing the tab.
		return false;
	}
}

function persistDismissedFlag() {
	try {
		if ( typeof window !== 'undefined' && window.sessionStorage ) {
			window.sessionStorage.setItem( DISMISS_KEY, '1' );
		}
	} catch ( e ) {
		// See above — best-effort.
	}
}

export default function OnboardingBanner() {
	const diagnostics = useSelect(
		( select ) => select( STORE_KEY ).getDiagnostics(),
		[]
	);

	const [ dismissed, setDismissed ] = useState( () => readDismissedFlag() );

	useEffect( () => {
		// Re-sync once on mount in case another tab dismissed earlier in
		// the same browser session (sessionStorage is per-tab, so this is
		// only meaningful if a future revision moves to localStorage; the
		// initializer covers the common case).
		setDismissed( readDismissedFlag() );
	}, [] );

	if ( dismissed ) {
		return null;
	}

	// Diagnostics not yet loaded → render nothing rather than flashing
	// a banner that may immediately disappear when the snapshot says
	// `wp_debug_log: true`.
	if ( ! diagnostics ) {
		return null;
	}

	if ( diagnostics.wp_debug_log ) {
		return null;
	}

	const handleDismiss = () => {
		persistDismissedFlag();
		setDismissed( true );
	};

	return (
		<Notice
			status="warning"
			className="logscope-onboarding-banner"
			onRemove={ handleDismiss }
		>
			<p>
				<strong>
					{ __(
						'WordPress debug logging is not enabled.',
						'logscope'
					) }
				</strong>{ ' ' }
				{ __(
					'Logscope reads the file WordPress writes to when WP_DEBUG_LOG is on. Until logging is enabled, no entries will appear here.',
					'logscope'
				) }
			</p>
			<p>
				{ __(
					'Add the following lines to your wp-config.php (above the “That’s all, stop editing!” comment):',
					'logscope'
				) }
			</p>
			<pre className="logscope-onboarding-banner__snippet">
				{
					"define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );"
				}
			</pre>
			<p>
				<a
					href="https://wordpress.org/documentation/article/debugging-in-wordpress/"
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __(
						'WordPress handbook: Debugging in WordPress',
						'logscope'
					) }
				</a>
			</p>
		</Notice>
	);
}
