/**
 * Top-N signatures table. Each row is clickable: clicking dispatches
 * a Logs-tab navigation pre-populated with the clicked signature's
 * severity + a regex anchored on the message prefix.
 *
 * The regex is built by escaping a leading slice of `sample`, not by
 * trying to recover the original normalised shape — the server's
 * normaliser is lossy (numbers, paths, hex addresses are masked) and
 * round-tripping it from the client would either re-introduce that
 * lossiness here or require a parallel implementation. A literal
 * prefix of the sample message is enough to catch the same class of
 * error in practice while keeping the click-through readable in the
 * FilterBar — admins can refine from there.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';

import { STORE_KEY } from '../../store';
import { severityLabel, severityTone } from '../../utils/severity';

const PREFIX_CHARS = 50;

function regexForSignature( sample ) {
	const trimmed = String( sample || '' ).trim();
	if ( trimmed === '' ) {
		return '';
	}
	const prefix = trimmed.slice( 0, PREFIX_CHARS );
	// Escape every PCRE metacharacter so the literal text is searched as-is.
	return prefix.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

export default function TopSignaturesTable( { rows } ) {
	const { setFilters, setActiveTab } = useDispatch( STORE_KEY );

	if ( ! Array.isArray( rows ) || rows.length === 0 ) {
		return (
			<p className="logscope-stats__status" role="status">
				{ __( 'No signatures in this range.', 'logscope' ) }
			</p>
		);
	}

	const handleSelect = ( row ) => {
		setFilters( {
			severity: [ row.severity ],
			q: regexForSignature( row.sample ),
		} );
		setActiveTab( 'logs' );
		if ( typeof window !== 'undefined' ) {
			// The hashchange listener in App.jsx mirrors this back into the
			// store; setting it explicitly here keeps the back button honest.
			window.location.hash = 'logs';
		}
	};

	return (
		<table className="logscope-stats__top-table">
			<caption className="screen-reader-text">
				{ __(
					'Top signatures in this range. Click a row to view matching log entries.',
					'logscope'
				) }
			</caption>
			<thead>
				<tr>
					<th scope="col" className="logscope-stats__top-rank">
						{ '#' }
					</th>
					<th scope="col" className="logscope-stats__top-sev">
						{ __( 'Severity', 'logscope' ) }
					</th>
					<th scope="col">{ __( 'Sample', 'logscope' ) }</th>
					<th scope="col" className="logscope-stats__top-table-count">
						{ __( 'Count', 'logscope' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row, idx ) => {
					const tone = severityTone( row.severity );
					return (
						// Plain <tr> (no role/tabIndex): overriding row
						// semantics with role="button" collapsed the cells
						// for AT and repeated one identical label N times.
						// The real control is the per-row button below; the
						// row-level onClick just widens the mouse target.
						<tr
							key={ row.signature }
							className="logscope-stats__top-row"
							onClick={ () => handleSelect( row ) }
						>
							<td className="logscope-stats__top-rank">
								<span className="logscope-stats__top-rank-num">
									{ '#' + ( idx + 1 ) }
								</span>
							</td>
							<td>
								<span
									className={ `logscope-pill logscope-pill--${ tone }` }
								>
									<span
										className={ `logscope-pill__dot logscope-pill__dot--${ tone }` }
										aria-hidden="true"
									/>
									{ severityLabel( row.severity ) }
								</span>
							</td>
							<td className="logscope-stats__top-table-msg">
								<button
									type="button"
									className="logscope-stats__top-msg-btn"
									onClick={ ( e ) => {
										e.stopPropagation();
										handleSelect( row );
									} }
									aria-label={ sprintf(
										/* translators: 1: severity label, 2: sample error message. */
										__(
											'View matching %1$s entries in Logs: %2$s',
											'logscope'
										),
										severityLabel( row.severity ),
										row.sample
									) }
								>
									{ row.sample }
								</button>
							</td>
							<td className="logscope-stats__top-table-count">
								{ Number( row.count || 0 ).toLocaleString() }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
