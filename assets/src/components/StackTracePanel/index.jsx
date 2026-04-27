/**
 * Renders the parsed stack frames attached to a log entry. Each frame's
 * `file:line` is clickable and copies the exact `path/to/file.php:123`
 * string (Phase 7.3 AC) so an admin can paste it into their editor.
 *
 * Frames without a file (`{main}`, `[internal function]`) are still
 * rendered but the click target is suppressed — there's nothing useful
 * to copy. The panel is parent-owned (the row component does not hold
 * its own expanded state) because react-window recycles row components
 * on scroll; the store keeps `expandedTraces` map keyed by the entry's
 * `raw` field so toggle state survives recycling.
 */
import { useCallback, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const COPY_FEEDBACK_MS = 1500;

export default function StackTracePanel( { frames } ) {
	if ( ! Array.isArray( frames ) || frames.length === 0 ) {
		return null;
	}

	return (
		<ol className="logscope-trace" role="list">
			{ frames.map( ( frame, index ) => (
				<FrameRow key={ index } frame={ frame } index={ index } />
			) ) }
		</ol>
	);
}

function FrameRow( { frame, index } ) {
	const [ copied, setCopied ] = useState( false );
	const target =
		frame.file && frame.line ? `${ frame.file }:${ frame.line }` : null;

	const handleCopy = useCallback( async () => {
		if ( ! target || ! navigator?.clipboard ) {
			return;
		}
		try {
			await navigator.clipboard.writeText( target );
			setCopied( true );
			setTimeout( () => setCopied( false ), COPY_FEEDBACK_MS );
		} catch ( e ) {
			// Clipboard API can reject in iframes / insecure contexts;
			// the row falls back to the read-only label.
		}
	}, [ target ] );

	const callLabel = frame.method
		? `${ frame.class ? frame.class + '::' : '' }${ frame.method }(${
				frame.args || ''
		  })`
		: frame.raw;

	return (
		<li className="logscope-trace__frame">
			<span
				className="logscope-trace__index"
				aria-label={ sprintf(
					/* translators: %d is the frame index. */
					__( 'Frame %d', 'logscope' ),
					index
				) }
			>
				{ '#' + index }
			</span>
			{ target ? (
				<button
					type="button"
					className="logscope-trace__location"
					onClick={ handleCopy }
					title={ __( 'Copy file:line to clipboard', 'logscope' ) }
				>
					<code>{ target }</code>
					{ copied && (
						<span className="logscope-trace__copied" role="status">
							{ __( 'Copied', 'logscope' ) }
						</span>
					) }
				</button>
			) : (
				<span className="logscope-trace__location logscope-trace__location--internal">
					<code>
						{ frame.file || __( '[internal]', 'logscope' ) }
					</code>
				</span>
			) }
			<span className="logscope-trace__call">{ callLabel }</span>
		</li>
	);
}
