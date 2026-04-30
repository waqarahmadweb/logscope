/**
 * Renders the `grouped=true` payload from `GET /logs`: one row per
 * signature with severity pill, count, file:line, sample message, and a
 * first-seen / last-seen window. Each row is expandable to show the full
 * sample message and a copy-to-clipboard for `file:line`.
 *
 * Groups are rendered as a plain list rather than a virtualized one: the
 * server caps a page at 500 rows (and ships 50 by default), so DOM size
 * is bounded. Variable row heights from the expanded panel would also
 * push us into a measuring virtualizer, which the bundle does not yet
 * carry. If the per-page ceiling ever climbs, swap in `react-window`'s
 * variable-size variant — the row component is already isolated.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';

import { STORE_KEY } from '../../store';
import { severityLabel, severityTone } from '../../utils/severity';

export default function GroupedView() {
	const groups = useSelect( ( select ) => select( STORE_KEY ).getLogs(), [] );

	if ( groups.length === 0 ) {
		return (
			<div className="logscope-empty">
				<p>
					{ __(
						'No grouped errors match the current filters.',
						'logscope'
					) }
				</p>
			</div>
		);
	}

	return (
		<ul className="logscope-grouped" role="list">
			{ groups.map( ( group ) => (
				<GroupRow key={ group.signature } group={ group } />
			) ) }
		</ul>
	);
}

function GroupRow( { group } ) {
	const isExpanded = useSelect(
		( select ) => select( STORE_KEY ).isGroupExpanded( group.signature ),
		[ group.signature ]
	);
	const isSavingMutes = useSelect(
		( select ) => select( STORE_KEY ).isSavingMutes(),
		[]
	);
	const { toggleGroupExpanded, muteSignature } = useDispatch( STORE_KEY );

	const tone = severityTone( group.severity );
	const fileLine =
		group.file && group.line
			? `${ group.file }:${ group.line }`
			: group.file || '';

	const onMute = ( event ) => {
		event.stopPropagation();
		// `window.prompt` is the smallest modal that satisfies the AC's
		// "ask for an optional reason." A bespoke <Modal> would require
		// portal wiring + focus traps without changing user value here;
		// the management panel is where reasons get edited at length.
		const reason = window.prompt(
			__(
				'Optional reason for muting this signature (visible in Settings → Muted signatures):',
				'logscope'
			),
			''
		);
		if ( reason === null ) {
			return;
		}
		muteSignature( group.signature, reason );
	};

	return (
		<li
			className={ `logscope-grouped__row logscope-grouped__row--${ tone }` }
		>
			<button
				type="button"
				className="logscope-grouped__summary"
				aria-expanded={ isExpanded }
				onClick={ () => toggleGroupExpanded( group.signature ) }
			>
				<span
					className={ `logscope-pill logscope-pill--${ tone }` }
					aria-label={ severityLabel( group.severity ) }
				>
					{ severityLabel( group.severity ) }
				</span>
				<span
					className="logscope-grouped__count"
					aria-label={ sprintf(
						/* translators: %d is the number of times this error occurred. */
						_n(
							'%d occurrence',
							'%d occurrences',
							group.count,
							'logscope'
						),
						group.count
					) }
				>
					{ '×' + group.count }
				</span>
				<span className="logscope-grouped__message">
					{ group.sample_message }
				</span>
				{ fileLine && (
					<span className="logscope-grouped__file">{ fileLine }</span>
				) }
				<span className="logscope-grouped__chevron" aria-hidden="true">
					{ isExpanded ? '▾' : '▸' }
				</span>
			</button>
			<button
				type="button"
				className="logscope-grouped__mute"
				onClick={ onMute }
				disabled={ isSavingMutes }
				aria-label={ __( 'Mute this signature', 'logscope' ) }
			>
				{ __( 'Mute', 'logscope' ) }
			</button>
			{ isExpanded && (
				<div
					className="logscope-grouped__detail"
					role="region"
					aria-label={ __( 'Group details', 'logscope' ) }
				>
					<dl>
						<dt>{ __( 'First seen', 'logscope' ) }</dt>
						<dd>{ group.first_seen || '—' }</dd>
						<dt>{ __( 'Last seen', 'logscope' ) }</dt>
						<dd>{ group.last_seen || '—' }</dd>
						{ fileLine && (
							<>
								<dt>{ __( 'Location', 'logscope' ) }</dt>
								<dd>
									<code>{ fileLine }</code>
								</dd>
							</>
						) }
					</dl>
					<pre className="logscope-grouped__sample">
						{ group.sample_message }
					</pre>
				</div>
			) }
		</li>
	);
}
