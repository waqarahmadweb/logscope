/**
 * Top-level Logscope React app. Renders a two-tab shell (Logs, Settings)
 * with URL-hash routing so refreshing or sharing a URL preserves the tab.
 *
 * Tabs are controlled by the `logscope/core` store rather than
 * `<TabPanel initialTabName>` because the latter only seeds the active
 * tab once — a back/forward navigation that mutates `location.hash`
 * wouldn't repaint. The store is the single source of truth; the URL
 * hash mirrors it via a one-way listener so the back button works.
 */
import { useEffect } from '@wordpress/element';
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

	// Hash → store, one direction. Click handlers below set the hash
	// only; this listener turns every hash change (click, back button,
	// manual edit) into a single store dispatch.
	useEffect( () => {
		const sync = () => setActiveTab( readTabFromHash() );
		sync();
		window.addEventListener( 'hashchange', sync );
		return () => window.removeEventListener( 'hashchange', sync );
	}, [ setActiveTab ] );

	const handleSelect = ( tabName ) => {
		if ( typeof window === 'undefined' ) {
			return;
		}
		if ( window.location.hash.replace( /^#/, '' ) === tabName ) {
			// No hashchange will fire — dispatch directly so a re-click
			// of the active tab is still a no-op rather than a stale read.
			setActiveTab( tabName );
			return;
		}
		window.location.hash = tabName;
	};

	return (
		<div className="logscope-app">
			<div
				className="logscope-tabs"
				role="tablist"
				aria-label={ __( 'Logscope sections', 'logscope' ) }
			>
				{ TABS.map( ( tab ) => {
					const isActive = tab.name === activeTab;
					return (
						<button
							key={ tab.name }
							type="button"
							role="tab"
							aria-selected={ isActive }
							className={
								'logscope-tabs__tab' +
								( isActive
									? ' logscope-tabs__tab--active'
									: '' )
							}
							onClick={ () => handleSelect( tab.name ) }
						>
							{ tab.title }
						</button>
					);
				} ) }
			</div>
			<div
				className="logscope-tabs__panel"
				role="tabpanel"
				aria-live="polite"
			>
				<TabContent name={ activeTab } />
			</div>
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
