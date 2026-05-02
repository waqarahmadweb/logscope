/**
 * Stats tab: range + bucket controls, a KPI tile row, a hero volume
 * chart paired with the severity-mix panel on the right, and a full-
 * width Top error signatures table at the bottom. The tab refetches
 * whenever range or bucket change. Stats are the ground truth of error
 * volume across the window — they intentionally ignore the Logs
 * FilterBar; click-through from the top-N table is the bridge that
 * populates the FilterBar with a signature-shaped query and switches
 * to the Logs tab.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';

import { STORE_KEY } from '../../store';
import BreakdownBar from './BreakdownBar';
import KpiGrid from './KpiGrid';
import TopSignaturesTable from './TopSignaturesTable';
import VolumeChart from './VolumeChart';

const RANGES = [
	{ value: '24h', label: __( 'Last 24 hours', 'logscope' ) },
	{ value: '7d', label: __( 'Last 7 days', 'logscope' ) },
	{ value: '30d', label: __( 'Last 30 days', 'logscope' ) },
];

const BUCKETS = [
	{ value: 'hour', label: __( 'Hour', 'logscope' ) },
	{ value: 'day', label: __( 'Day', 'logscope' ) },
];

function Seg( { options, value, onChange, ariaLabel } ) {
	return (
		<div className="logscope-seg" role="tablist" aria-label={ ariaLabel }>
			{ options.map( ( opt ) => (
				<button
					key={ opt.value }
					type="button"
					role="tab"
					aria-selected={ value === opt.value }
					className={
						'logscope-seg__btn' +
						( value === opt.value ? ' logscope-seg__btn--on' : '' )
					}
					onClick={ () => onChange( opt.value ) }
				>
					{ opt.label }
				</button>
			) ) }
		</div>
	);
}

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
				<div className="logscope-stats__group">
					<span className="logscope-stats__legend">
						{ __( 'Range', 'logscope' ) }
					</span>
					<Seg
						options={ RANGES }
						value={ range }
						onChange={ setStatsRange }
						ariaLabel={ __( 'Range', 'logscope' ) }
					/>
				</div>
				<div className="logscope-stats__group">
					<span className="logscope-stats__legend">
						{ __( 'Bucket', 'logscope' ) }
					</span>
					<Seg
						options={ BUCKETS }
						value={ bucket }
						onChange={ setStatsBucket }
						ariaLabel={ __( 'Bucket', 'logscope' ) }
					/>
				</div>
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
	const totals = data.totals || {};
	const buckets = data.buckets || [];
	const granularity = data.bucket || 'day';
	const total = Object.values( totals ).reduce(
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
			<KpiGrid totals={ totals } buckets={ buckets } />
			<div className="logscope-stats__hero-row">
				<div className="logscope-stats__panel logscope-stats__panel--chart">
					<VolumeChart
						buckets={ buckets }
						totals={ totals }
						granularity={ granularity }
					/>
				</div>
				<div className="logscope-stats__panel logscope-stats__panel--mix">
					<BreakdownBar totals={ totals } />
				</div>
			</div>
			<div className="logscope-stats__panel logscope-stats__panel--top">
				<div className="logscope-stats__panel-head">
					<h2 className="logscope-stats__panel-title">
						{ __( 'Top error signatures', 'logscope' ) }
					</h2>
					<span className="logscope-stats__panel-meta">
						{ __( 'Click row to filter Logs', 'logscope' ) }
					</span>
				</div>
				<TopSignaturesTable rows={ data.top || [] } />
			</div>
		</div>
	);
}
