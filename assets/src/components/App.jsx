/**
 * Top-level Logscope React app. Renders a two-tab shell (Logs, Settings)
 * with URL-hash routing so refreshing or sharing a URL preserves the tab.
 *
 * The viewer / filter / settings panel components land in later phases;
 * this file is intentionally thin so 6.3 ships a working mount point
 * without dragging future-phase code in.
 */
import { useEffect } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import { STORE_KEY } from '../store';
import LogViewer from './LogViewer';

const TABS = [
	{ name: 'logs', title: __( 'Logs', 'logscope' ) },
	{ name: 'settings', title: __( 'Settings', 'logscope' ) },
];

const VALID_TAB_NAMES = TABS.map( ( tab ) => tab.name );

function readTabFromHash() {
	if ( typeof window === 'undefined' ) {
		return 'logs';
	}
	const raw = ( window.location.hash || '' ).replace( /^#/, '' );
	return VALID_TAB_NAMES.includes( raw ) ? raw : 'logs';
}

export default function App() {
	const activeTab = useSelect(
		( select ) => select( STORE_KEY ).getActiveTab(),
		[]
	);
	const { setActiveTab } = useDispatch( STORE_KEY );

	// Sync hash → store on mount + on hashchange. Two-way binding stays
	// shallow: the store is the source of truth; the hash mirrors it.
	useEffect( () => {
		const sync = () => setActiveTab( readTabFromHash() );
		sync();
		window.addEventListener( 'hashchange', sync );
		return () => window.removeEventListener( 'hashchange', sync );
	}, [ setActiveTab ] );

	const handleSelect = ( tabName ) => {
		setActiveTab( tabName );
		if ( typeof window !== 'undefined' ) {
			window.location.hash = tabName;
		}
	};

	return (
		<div className="logscope-app">
			<TabPanel
				className="logscope-tabs"
				activeClass="is-active"
				tabs={ TABS }
				initialTabName={ activeTab }
				onSelect={ handleSelect }
			>
				{ ( tab ) => <TabContent name={ tab.name } /> }
			</TabPanel>
		</div>
	);
}

function TabContent( { name } ) {
	if ( name === 'logs' ) {
		return <LogViewer />;
	}
	if ( name === 'settings' ) {
		return (
			<p className="logscope-placeholder">
				{ __( 'Settings panel arrives in Phase 8.', 'logscope' ) }
			</p>
		);
	}
	return null;
}
