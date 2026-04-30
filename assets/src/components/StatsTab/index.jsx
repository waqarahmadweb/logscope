/**
 * Stats tab: range + bucket controls and the dashboard panels.
 *
 * The tab refetches whenever range or bucket change. Stats are the
 * ground truth of error volume across the window — they intentionally
 * ignore the Logs FilterBar; click-through from the top-N table is
 * the bridge that populates the FilterBar with a signature-shaped
 * query and switches to the Logs tab.
 *
 * 15.3 (this file) is the scaffold: range/bucket toggles, the fetch
 * pipeline, the loading + error + empty states. 15.4 adds the
 * sparkline grid; 15.5 adds the top-N table; 15.6 adds the breakdown
 * bar. Each follow-up plugs into the same `data` payload this tab
 * already fetches, so the store + REST surface only need to land
 * once.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import BreakdownBar from './BreakdownBar';
import SparklineGrid from './SparklineGrid';
import TopSignaturesTable from './TopSignaturesTable';

const RANGES = [
	{ value: '24h', label: __( 'Last 24 hours', 'logscope' ) },
	{ value: '7d', label: __( 'Last 7 days', 'logscope' ) },
	{ value: '30d', label: __( 'Last 30 days', 'logscope' ) },
];

const BUCKETS = [
	{ value: 'hour', label: __( 'Hour', 'logscope' ) },
	{ value: 'day', label: __( 'Day', 'logscope' ) },
];

export default function StatsTab() {
	const { range, bucket, data, isLoading, loadError } = useSelect(
		( s ) => ( {
			range: s( STORE_KEY ).getStatsRange(),
			bucket: s( STORE_KEY ).getStatsBucket(),
			data: s( STORE_KEY ).getStatsData(),
			isLoading: s( STORE_KEY ).isLoadingStats(),
			loadError: s( STORE_KEY ).getStatsLoadError(),
		} ),
		[]
	);
	const { setStatsRange, setStatsBucket, fetchStats } =
		useDispatch( STORE_KEY );

	useEffect( () => {
		fetchStats();
	}, [ range, bucket, fetchStats ] );

	return (
		<div className="logscope-stats">
			<div className="logscope-stats__controls">
				<fieldset className="logscope-stats__group">
					<legend className="logscope-stats__legend">
						{ __( 'Range', 'logscope' ) }
					</legend>
					{ RANGES.map( ( r ) => (
						<Button
							key={ r.value }
							variant={
								range === r.value ? 'primary' : 'secondary'
							}
							size="small"
							onClick={ () => setStatsRange( r.value ) }
							aria-pressed={ range === r.value }
						>
							{ r.label }
						</Button>
					) ) }
				</fieldset>
				<fieldset className="logscope-stats__group">
					<legend className="logscope-stats__legend">
						{ __( 'Bucket', 'logscope' ) }
					</legend>
					{ BUCKETS.map( ( b ) => (
						<Button
							key={ b.value }
							variant={
								bucket === b.value ? 'primary' : 'secondary'
							}
							size="small"
							onClick={ () => setStatsBucket( b.value ) }
							aria-pressed={ bucket === b.value }
						>
							{ b.label }
						</Button>
					) ) }
				</fieldset>
			</div>

			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }

			{ isLoading && ! data && (
				<p className="logscope-stats__status" role="status">
					{ __( 'Loading stats…', 'logscope' ) }
				</p>
			) }

			{ data && <StatsSummary data={ data } /> }
		</div>
	);
}

function StatsSummary( { data } ) {
	const total = Object.values( data.totals || {} ).reduce(
		( sum, n ) => sum + Number( n || 0 ),
		0
	);

	if ( total === 0 ) {
		return (
			<p className="logscope-stats__status" role="status">
				{ __( 'No log entries in this range.', 'logscope' ) }
			</p>
		);
	}

	return (
		<div className="logscope-stats__summary">
			<p>
				{ __( 'Entries in range:', 'logscope' ) }{ ' ' }
				<strong>{ total }</strong>
			</p>
			<BreakdownBar totals={ data.totals || {} } />
			<SparklineGrid
				buckets={ data.buckets || [] }
				totals={ data.totals || {} }
			/>
			<TopSignaturesTable rows={ data.top || [] } />
		</div>
	);
}
