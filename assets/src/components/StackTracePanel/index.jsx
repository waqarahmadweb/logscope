/**
 * Renders the parsed stack frames attached to a log entry. Each frame's
 * `file:line` is clickable and copies the exact `path/to/file.php:123`
 * string (Phase 7.3 AC) so an admin can paste it into their editor.
 *
 * The Phase 18.7 layout is a 4-column grid — index, source tag,
 * file:line, call — so the file/line column lines up vertically across
 * frames the eye reads as a hierarchy from the innermost frame (#0) to
 * the call site. Each frame is color-coded by origin (plugin / theme /
 * mu-plugin / core) via `frameSource`, mirroring the server-side
 * `SourceClassifier` so admins can scan for the row that's actually
 * theirs to fix vs. core glue.
 *
 * Frames without a file (`{main}`, `[internal function]`) are still
 * rendered but the click target is suppressed — there's nothing useful
 * to copy. The panel is parent-owned (the row component does not hold
 * its own expanded state) because react-window recycles row components
 * on scroll; the store keeps `expandedTraces` map keyed by the entry's
 * `raw` field so toggle state survives recycling.
 */
import { useCallback, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import frameSource from '../../utils/frameSource';

const COPY_FEEDBACK_MS = 1500;

const SOURCE_LABEL = {
	plugin: __( 'plugin', 'logscope' ),
	theme: __( 'theme', 'logscope' ),
	'mu-plugin': __( 'mu-plugin', 'logscope' ),
	core: __( 'core', 'logscope' ),
	unknown: __( 'other', 'logscope' ),
};

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

	const source = useMemo( () => frameSource( frame.file ), [ frame.file ] );

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

	const tagTitle = source.slug
		? sprintf(
				/* translators: 1: source category (plugin / theme / mu-plugin / core); 2: plugin or theme folder slug. */
				__( '%1$s — %2$s', 'logscope' ),
				SOURCE_LABEL[ source.kind ],
				source.slug
		  )
		: SOURCE_LABEL[ source.kind ];

	return (
		<li
			className={ `logscope-trace__frame logscope-trace__frame--${ source.kind }` }
		>
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
			<span
				className={ `logscope-trace__source logscope-trace__source--${ source.kind }` }
				title={ tagTitle }
			>
				{ source.slug ? source.slug : SOURCE_LABEL[ source.kind ] }
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
			<span className="logscope-trace__call" title={ callLabel }>
				{ callLabel }
			</span>
		</li>
	);
}
