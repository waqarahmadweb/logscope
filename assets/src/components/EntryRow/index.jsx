/**
 * Single row in the virtualized log viewer. Receives the row index +
 * an inline style from react-window (which absolutely-positions the row
 * inside the viewport), plus the shared `items` array via `rowProps`.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const SEVERITY_TO_TONE = {
	'Fatal error': 'fatal',
	'Parse error': 'fatal',
	Warning: 'warning',
	Notice: 'notice',
	Deprecated: 'deprecated',
	'Strict Standards': 'deprecated',
};

export default function EntryRow( { index, style, items } ) {
	const [ expanded, setExpanded ] = useState( false );

	const entry = items[ index ];
	if ( ! entry ) {
		return <div style={ style } aria-hidden="true" />;
	}

	const tone = SEVERITY_TO_TONE[ entry.severity ] || 'unknown';
	const hasTrace =
		Array.isArray( entry.stack_trace ) && entry.stack_trace.length > 0;

	return (
		<div
			className={ `logscope-entry logscope-entry--${ tone }` }
			style={ style }
			role="listitem"
		>
			<span
				className={ `logscope-pill logscope-pill--${ tone }` }
				aria-label={ entry.severity || __( 'Unknown', 'logscope' ) }
			>
				{ entry.severity || __( 'Unknown', 'logscope' ) }
			</span>
			<time
				className="logscope-entry__timestamp"
				dateTime={ entry.timestamp || '' }
			>
				{ entry.timestamp || '' }
			</time>
			<span className="logscope-entry__message">
				{ entry.message || '' }
			</span>
			{ hasTrace && (
				<button
					type="button"
					className="logscope-entry__toggle"
					aria-expanded={ expanded }
					onClick={ () => setExpanded( ( v ) => ! v ) }
				>
					{ expanded
						? __( 'Hide trace', 'logscope' )
						: __( 'Show trace', 'logscope' ) }
				</button>
			) }
		</div>
	);
}
