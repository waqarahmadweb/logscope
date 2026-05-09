/**
 * Renders the `grouped=true` payload from `GET /logs`: one row per
 * signature with severity pill, count, file:line, sample message, and a
 * first-seen / last-seen window. Each row is expandable to show the full
 * sample message and a copy-to-clipboard for `file:line`.
 *
 * Phase 16.5 added a per-row checkbox + a header "Select all" + a bulk
 * action bar with "Mute selected" and "Export selected" (the latter
 * builds a CSV blob client-side from the already-fetched group rows
 * rather than firing a separate request — the data is right here).
 *
 * Groups are rendered as a plain list rather than a virtualized one: the
 * server caps a page at 500 rows (and ships 50 by default), so DOM size
 * is bounded. Variable row heights from the expanded panel would also
 * push us into a measuring virtualizer, which the bundle does not yet
 * carry. If the per-page ceiling ever climbs, swap in `react-window`'s
 * variable-size variant — the row component is already isolated.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import { severityLabel, severityTone } from '../../utils/severity';
import { formatEntryTimestamp } from '../../utils/formatTimestamp';
import buildFilterParams from '../../utils/filterParams';

export default function GroupedView() {
	const { groups, filters, isSavingMutes, perPage } = useSelect(
		( select ) => {
			const store = select( STORE_KEY );
			return {
				groups: store.getLogs(),
				filters: store.getFilters(),
				isSavingMutes: store.isSavingMutes(),
				perPage: store.getLogsPerPage(),
			};
		},
		[]
	);
	const { bulkMuteSignatures, fetchLogs } = useDispatch( STORE_KEY );

	const [ selected, setSelected ] = useState( () => new Set() );

	// Prune the selection whenever the visible groups change so a
	// signature that disappeared (mute applied, filter changed, page
	// flipped) does not stay in the set and resurface stale state if
	// the same signature reappears later.
	useEffect( () => {
		setSelected( ( prev ) => {
			const visible = new Set( groups.map( ( g ) => g.signature ) );
			const next = new Set();
			prev.forEach( ( sig ) => {
				if ( visible.has( sig ) ) {
					next.add( sig );
				}
			} );
			return next.size === prev.size ? prev : next;
		} );
	}, [ groups ] );

	const allSelected = useMemo( () => {
		if ( groups.length === 0 ) {
			return false;
		}
		return groups.every( ( g ) => selected.has( g.signature ) );
	}, [ groups, selected ] );

	const someSelected = selected.size > 0;

	const toggleOne = ( signature, on ) => {
		setSelected( ( prev ) => {
			const next = new Set( prev );
			if ( on ) {
				next.add( signature );
			} else {
				next.delete( signature );
			}
			return next;
		} );
	};

	const toggleAll = ( on ) => {
		if ( on ) {
			setSelected( new Set( groups.map( ( g ) => g.signature ) ) );
		} else {
			setSelected( new Set() );
		}
	};

	const onMuteSelected = async () => {
		if ( selected.size === 0 || isSavingMutes ) {
			return;
		}
		await bulkMuteSignatures( Array.from( selected ), '' );
		// Refetch the grouped page so the muted groups drop out of
		// view immediately, satisfying the AC's "all 3 disappear from
		// view" expectation. We are inside GroupedView so the page is
		// always in grouped mode here.
		fetchLogs( {
			page: 1,
			per_page: perPage,
			grouped: true,
			...buildFilterParams( filters ),
		} );
		setSelected( new Set() );
	};

	const onExportSelected = () => {
		if ( selected.size === 0 ) {
			return;
		}
		const rows = groups.filter( ( g ) => selected.has( g.signature ) );
		downloadGroupsCsv( rows );
	};

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
		<div className="logscope-grouped-wrapper">
			<div
				className={
					'logscope-grouped__header' +
					( someSelected ? ' logscope-grouped__header--active' : '' )
				}
				role="region"
				aria-label={ __( 'Bulk actions', 'logscope' ) }
			>
				<label className="logscope-grouped__select-all">
					<input
						type="checkbox"
						checked={ allSelected }
						// Indeterminate marker for partial selection; React
						// does not have a JSX prop for it so we set it via
						// the DOM ref callback.
						ref={ ( node ) => {
							if ( node ) {
								node.indeterminate =
									someSelected && ! allSelected;
							}
						} }
						onChange={ ( e ) => toggleAll( e.target.checked ) }
						aria-label={ __(
							'Select all visible groups',
							'logscope'
						) }
					/>
					<span>
						{ someSelected
							? sprintf(
									/* translators: %d is the number of selected groups. */
									_n(
										'%d selected',
										'%d selected',
										selected.size,
										'logscope'
									),
									selected.size
							  )
							: __( 'Select all', 'logscope' ) }
					</span>
				</label>
				{ someSelected && (
					<>
						<span
							className="logscope-grouped__header-sep"
							aria-hidden="true"
						>
							·
						</span>
						<div className="logscope-grouped__bulk-actions">
							<Button
								variant="secondary"
								disabled={ isSavingMutes }
								onClick={ onMuteSelected }
							>
								🔕 { __( 'Mute', 'logscope' ) }
							</Button>
							<Button
								variant="secondary"
								onClick={ onExportSelected }
							>
								⤓ { __( 'Export', 'logscope' ) }
							</Button>
						</div>
						<button
							type="button"
							className="logscope-grouped__cancel"
							onClick={ () => setSelected( new Set() ) }
						>
							{ __( 'Clear selection', 'logscope' ) }
						</button>
					</>
				) }
			</div>
			<ul className="logscope-grouped" role="list">
				{ groups.map( ( group ) => (
					<GroupRow
						key={ group.signature }
						group={ group }
						isSelected={ selected.has( group.signature ) }
						onToggleSelected={ ( on ) =>
							toggleOne( group.signature, on )
						}
					/>
				) ) }
			</ul>
		</div>
	);
}

function GroupRow( { group, isSelected, onToggleSelected } ) {
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
			className={ `logscope-grouped__row logscope-grouped__row--${ tone }${
				isExpanded ? ' logscope-grouped__row--expanded' : ''
			}` }
		>
			<input
				type="checkbox"
				className="logscope-grouped__checkbox"
				checked={ isSelected }
				onChange={ ( e ) => onToggleSelected( e.target.checked ) }
				onClick={ ( e ) => e.stopPropagation() }
				aria-label={ sprintf(
					/* translators: %s is the sample error message for the group. */
					__( 'Select group: %s', 'logscope' ),
					group.sample_message
				) }
			/>
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
					<span
						className={
							'logscope-pill__dot logscope-pill__dot--' + tone
						}
						aria-hidden="true"
					/>
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
					<span className="logscope-grouped__file" title={ fileLine }>
						{ fileLine }
					</span>
				) }
			</button>
			<button
				type="button"
				className="logscope-grouped__mute"
				onClick={ onMute }
				disabled={ isSavingMutes }
				title={ __( 'Mute this signature', 'logscope' ) }
				aria-label={ __( 'Mute this signature', 'logscope' ) }
			>
				🔕
			</button>
			{ isExpanded && (
				<div
					className="logscope-grouped__detail"
					role="region"
					aria-label={ __( 'Group details', 'logscope' ) }
				>
					<dl>
						<dt>{ __( 'First seen', 'logscope' ) }</dt>
						<dd>
							{ group.first_seen
								? formatEntryTimestamp( group.first_seen )
								: '—' }
						</dd>
						<dt>{ __( 'Last seen', 'logscope' ) }</dt>
						<dd>
							{ group.last_seen
								? formatEntryTimestamp( group.last_seen )
								: '—' }
						</dd>
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

/**
 * Builds a CSV blob from the selected group rows and triggers a
 * browser download. Client-side because the data is already on the
 * page; round-tripping through the server would not improve fidelity
 * and would add a route surface.
 *
 * @param {Array<object>} rows Selected group payloads from the store.
 */
function downloadGroupsCsv( rows ) {
	const header = [
		'severity',
		'count',
		'signature',
		'sample_message',
		'file',
		'line',
		'first_seen',
		'last_seen',
	];
	const lines = [ header.join( ',' ) ];
	rows.forEach( ( row ) => {
		lines.push(
			[
				csvCell( row.severity ),
				csvCell( row.count ),
				csvCell( row.signature ),
				csvCell( row.sample_message ),
				csvCell( row.file ),
				csvCell( row.line ),
				csvCell( row.first_seen ),
				csvCell( row.last_seen ),
			].join( ',' )
		);
	} );
	const csv = lines.join( '\r\n' ) + '\r\n';

	// Prepend a UTF-8 BOM so Excel auto-detects the encoding instead of
	// rendering UTF-8 byte sequences as Latin-1 mojibake — common pain
	// point with logs that contain non-ASCII characters in messages.
	const blob = new Blob( [ '﻿', csv ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = URL.createObjectURL( blob );

	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = `logscope-groups-${ timestampForFilename() }.csv`;
	document.body.appendChild( anchor );
	anchor.click();
	document.body.removeChild( anchor );
	// Defer the revoke so Safari has time to start the download — same
	// pattern used by file-saver and other CSV exporters.
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

function csvCell( value ) {
	if ( value === undefined || value === null ) {
		return '';
	}
	const str = String( value );
	if ( /[",\r\n]/.test( str ) ) {
		return '"' + str.replace( /"/g, '""' ) + '"';
	}
	return str;
}

function timestampForFilename() {
	const now = new Date();
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return (
		now.getFullYear() +
		pad( now.getMonth() + 1 ) +
		pad( now.getDate() ) +
		'-' +
		pad( now.getHours() ) +
		pad( now.getMinutes() ) +
		pad( now.getSeconds() )
	);
}
