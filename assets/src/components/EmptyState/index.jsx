/**
 * Shown in place of the virtualized list when there are no entries.
 * Distinguishes "loading" (LogViewer renders a skeleton instead) from
 * "REST call failed" (an actionable error) and the everyday no-rows
 * cases. Phase 16.4 expanded the no-rows branch so the copy reflects
 * the underlying reason — the file does not exist, the file exists
 * but is empty, all visible entries are muted out, or filters
 * excluded everything — instead of the generic "no log entries"
 * fallback. Reasons are derived from the same `/diagnostics` snapshot
 * the onboarding banner uses (fetched once on mount in `LogViewer`)
 * and the live mute count, so we never fire a separate request to
 * decide which message to render.
 *
 * The error variant gets `role="alert"` so a screen reader announces it
 * immediately on render; the empty variant uses `role="status"` with a
 * polite live region — observable to AT users without interrupting.
 */
import { __, sprintf } from '@wordpress/i18n';

export default function EmptyState( {
	error,
	filtersActive = false,
	diagnostics = null,
	muteCount = 0,
} ) {
	if ( error ) {
		return (
			<div className="logscope-empty logscope-empty--error" role="alert">
				<p>{ __( 'Could not load logs.', 'logscope' ) }</p>
				<p className="logscope-empty__detail">{ error }</p>
			</div>
		);
	}

	const reason = resolveReason( {
		filtersActive,
		diagnostics,
		muteCount,
	} );

	return (
		<div className="logscope-empty" role="status" aria-live="polite">
			<p>{ reason.headline }</p>
			<p className="logscope-empty__detail">{ reason.detail }</p>
		</div>
	);
}

function resolveReason( { filtersActive, diagnostics, muteCount } ) {
	if ( filtersActive ) {
		return {
			headline: __( 'No entries match the current filters.', 'logscope' ),
			detail: __(
				'Try widening the date range or clearing the regex.',
				'logscope'
			),
		};
	}

	// Without a diagnostics snapshot fall back to the generic copy.
	// LogViewer fetches diagnostics on mount, so this is only the
	// before-first-response case in practice.
	if ( ! diagnostics ) {
		return {
			headline: __( 'No log entries to show.', 'logscope' ),
			detail: __(
				'When WordPress writes to debug.log, entries will appear here.',
				'logscope'
			),
		};
	}

	if ( ! diagnostics.exists ) {
		// Missing path is the most informative thing we can say — name
		// the path the plugin would tail so the admin knows where to
		// look. An empty `log_path` falls through to the generic copy
		// below; that case only happens on a host without WP_CONTENT_DIR
		// defined, which is itself flagged by the onboarding banner.
		if ( diagnostics.log_path ) {
			return {
				headline: __( 'Log file does not yet exist.', 'logscope' ),
				detail: sprintf(
					/* translators: %s is the absolute path the plugin would tail. */
					__(
						'Logscope is watching %s — entries will appear here once WordPress writes its first error.',
						'logscope'
					),
					diagnostics.log_path
				),
			};
		}
	}

	if ( diagnostics.exists && diagnostics.file_size === 0 ) {
		return {
			headline: __( 'Log file is empty.', 'logscope' ),
			detail: __(
				'WordPress has not written to debug.log yet. New errors will appear here as they happen.',
				'logscope'
			),
		};
	}

	if ( diagnostics.exists && diagnostics.file_size > 0 && muteCount > 0 ) {
		// File has bytes on disk but the visible page came back empty
		// and at least one signature is muted — the most likely
		// explanation is that the muted set covers everything in the
		// trailing window. The Mute panel in Settings is the primary
		// unmute affordance.
		return {
			headline: __( 'All recent entries are muted.', 'logscope' ),
			detail: __(
				'Visit the Mute panel under Settings to review or remove muted signatures.',
				'logscope'
			),
		};
	}

	return {
		headline: __( 'No log entries to show.', 'logscope' ),
		detail: __(
			'When WordPress writes to debug.log, entries will appear here.',
			'logscope'
		),
	};
}
