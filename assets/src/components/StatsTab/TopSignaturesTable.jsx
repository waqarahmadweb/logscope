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
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { Button } from '@wordpress/components';

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
		return null;
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
					<th scope="col">{ __( 'Severity', 'logscope' ) }</th>
					<th scope="col">{ __( 'Message', 'logscope' ) }</th>
					<th scope="col" className="logscope-stats__top-table-count">
						{ __( 'Count', 'logscope' ) }
					</th>
					<th scope="col" className="screen-reader-text">
						{ __( 'Actions', 'logscope' ) }
					</th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.signature }>
						<td>
							<span
								className={
									'logscope-pill logscope-pill--' +
									severityTone( row.severity )
								}
							>
								{ severityLabel( row.severity ) }
							</span>
						</td>
						<td className="logscope-stats__top-table-msg">
							{ row.sample }
						</td>
						<td className="logscope-stats__top-table-count">
							{ row.count }
						</td>
						<td>
							<Button
								variant="link"
								onClick={ () => handleSelect( row ) }
							>
								{ __( 'View in Logs', 'logscope' ) }
							</Button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
