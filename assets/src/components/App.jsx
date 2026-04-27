/**
 * Top-level Logscope React app. Renders a two-tab shell (Logs, Settings)
 * with URL-hash routing so refreshing or sharing a URL preserves the tab.
 *
 * Tabs are controlled by the `logscope/core` store rather than
 * `<TabPanel initialTabName>` because the latter only seeds the active
 * tab once — a back/forward navigation that mutates `location.hash`
 * wouldn't repaint. The store is the single source of truth; the URL
 * hash mirrors it via a one-way listener so the back button works.
 *
 * The toast host and the global keyboard shortcuts (Phase 11) live here
 * so they apply across both tabs. The shortcut handlers route through
 * `LogscopeShortcutBus` (a window-level event emitter) — the LogViewer
 * subscribes to the focus-search and toggle events so they remain a
 * concern of the component that owns the affected DOM, while keeping
 * the global key listener (which has to live above the tab switch) free
 * of knowledge about specific refs.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import { STORE_KEY } from '../store';
import LogViewer from './LogViewer';
import SettingsPanel from './SettingsPanel';
import ToastHost from './ToastHost';
import HelpModal from './HelpModal';
import useKeyboardShortcuts from '../hooks/useKeyboardShortcuts';

const TABS = [
	{ name: 'logs', title: __( 'Logs', 'logscope' ) },
	{ name: 'settings', title: __( 'Settings', 'logscope' ) },
];

const VALID_TAB_NAMES = TABS.map( ( tab ) => tab.name );

export const SHORTCUT_EVENT = 'logscope:shortcut';

function readTabFromHash() {
	if ( typeof window === 'undefined' ) {
		return 'logs';
	}
	const raw = ( window.location.hash || '' ).replace( /^#/, '' );
	return VALID_TAB_NAMES.includes( raw ) ? raw : 'logs';
}

function emitShortcut( name ) {
	if ( typeof window === 'undefined' ) {
		return;
	}
	window.dispatchEvent( new CustomEvent( SHORTCUT_EVENT, { detail: name } ) );
}

export default function App() {
	const activeTab = useSelect(
		( select ) => select( STORE_KEY ).getActiveTab(),
		[]
	);
	const { setActiveTab } = useDispatch( STORE_KEY );
	const [ helpOpen, setHelpOpen ] = useState( false );

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
			setActiveTab( tabName );
			return;
		}
		window.location.hash = tabName;
	};

	// Shortcuts that target the Logs view ensure the tab is active first
	// — pressing `g` or `t` from the Settings tab should still do the
	// expected thing, not silently drop the keystroke.
	const ensureLogsTab = useCallback( () => {
		if ( activeTab !== 'logs' ) {
			handleSelect( 'logs' );
		}
	}, [ activeTab ] );

	useKeyboardShortcuts( {
		onFocusSearch: useCallback( () => {
			ensureLogsTab();
			emitShortcut( 'focus-search' );
		}, [ ensureLogsTab ] ),
		onToggleGrouped: useCallback( () => {
			ensureLogsTab();
			emitShortcut( 'toggle-grouped' );
		}, [ ensureLogsTab ] ),
		onToggleTail: useCallback( () => {
			ensureLogsTab();
			emitShortcut( 'toggle-tail' );
		}, [ ensureLogsTab ] ),
		onShowHelp: useCallback( () => setHelpOpen( true ), [] ),
	} );

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
							tabIndex={ isActive ? 0 : -1 }
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
				<span style={ { flex: 1 } } />
				<Button
					variant="tertiary"
					size="small"
					onClick={ () => setHelpOpen( true ) }
					aria-label={ __( 'Keyboard shortcuts', 'logscope' ) }
				>
					{ __( 'Shortcuts (?)', 'logscope' ) }
				</Button>
			</div>
			<div
				className="logscope-tabs__panel"
				role="tabpanel"
				aria-live="polite"
			>
				<TabContent name={ activeTab } />
			</div>
			<ToastHost />
			{ helpOpen && <HelpModal onClose={ () => setHelpOpen( false ) } /> }
		</div>
	);
}

function TabContent( { name } ) {
	if ( name === 'logs' ) {
		return <LogViewer />;
	}
	if ( name === 'settings' ) {
		return <SettingsPanel />;
	}
	return null;
}
