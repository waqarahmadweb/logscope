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
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf, _n } from '@wordpress/i18n';

import { STORE_KEY } from '../store';
import useTheme from '../hooks/useTheme';
import { SHORTCUT, SHORTCUT_EVENT } from '../shortcuts';
import LogViewer from './LogViewer';
import SettingsPanel from './SettingsPanel';
import StatsTab from './StatsTab';
import ToastHost from './ToastHost';
import HelpModal from './HelpModal';
import useKeyboardShortcuts from '../hooks/useKeyboardShortcuts';

const TABS = [
	{ name: 'logs', title: __( 'Logs', 'logscope' ) },
	{ name: 'stats', title: __( 'Stats', 'logscope' ) },
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

function emitShortcut( name ) {
	if ( typeof window === 'undefined' ) {
		return;
	}
	window.dispatchEvent( new CustomEvent( SHORTCUT_EVENT, { detail: name } ) );
}

export default function App() {
	const { activeTab, logsTotal, items } = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			activeTab: store.getActiveTab(),
			logsTotal: store.getLogsTotal(),
			items: store.getLogs(),
		};
	}, [] );
	const { setActiveTab } = useDispatch( STORE_KEY );
	const [ helpOpen, setHelpOpen ] = useState( false );
	const { theme, cycle: cycleTheme } = useTheme();

	// Live indicator counts: total comes from the API response (matches
	// active filters across pagination); fatalInLoaded counts fatals
	// across the entries the list has actually streamed in. With
	// infinite-scroll loading, this grows toward the true total as the
	// user scrolls — it is intentionally not a global aggregate (the
	// Stats tab owns that view).
	const fatalInLoaded = useMemo(
		() => items.filter( ( i ) => i?.severity === 'fatal' ).length,
		[ items ]
	);

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
			emitShortcut( SHORTCUT.FOCUS_SEARCH );
		}, [ ensureLogsTab ] ),
		onToggleGrouped: useCallback( () => {
			ensureLogsTab();
			emitShortcut( SHORTCUT.TOGGLE_GROUPED );
		}, [ ensureLogsTab ] ),
		onToggleTail: useCallback( () => {
			ensureLogsTab();
			emitShortcut( SHORTCUT.TOGGLE_TAIL );
		}, [ ensureLogsTab ] ),
		onShowHelp: useCallback( () => setHelpOpen( true ), [] ),
	} );

	// WAI-ARIA tabs pattern: Left/Right cycles between tabs, Home / End
	// jump to the first / last. Roving tabIndex below already handles the
	// "tab into the strip" case; this fills in the "move within the
	// strip" expectation that screen-reader users have.
	const handleTabKeyDown = ( event ) => {
		const idx = VALID_TAB_NAMES.indexOf( activeTab );
		if ( idx === -1 ) {
			return;
		}
		let next = null;
		switch ( event.key ) {
			case 'ArrowRight':
				next = VALID_TAB_NAMES[ ( idx + 1 ) % VALID_TAB_NAMES.length ];
				break;
			case 'ArrowLeft':
				next =
					VALID_TAB_NAMES[
						( idx - 1 + VALID_TAB_NAMES.length ) %
							VALID_TAB_NAMES.length
					];
				break;
			case 'Home':
				next = VALID_TAB_NAMES[ 0 ];
				break;
			case 'End':
				next = VALID_TAB_NAMES[ VALID_TAB_NAMES.length - 1 ];
				break;
			default:
				return;
		}
		event.preventDefault();
		handleSelect( next );
		// Move focus to the newly-active tab; a fresh render flips its
		// tabIndex to 0, so document.querySelector picks the right node.
		requestAnimationFrame( () => {
			const el = document.querySelector(
				`[role="tab"][data-logscope-tab="${ next }"]`
			);
			el?.focus();
		} );
	};

	return (
		<div className="logscope-app">
			<header className="logscope-page-head">
				<div className="logscope-page-head__title-block">
					<h1 className="logscope-page-head__title">
						{ __( 'Logscope', 'logscope' ) }
					</h1>
					<p className="logscope-page-head__sub">
						{ __(
							'PHP error log viewer for WordPress.',
							'logscope'
						) }
					</p>
				</div>
				<button
					type="button"
					className="logscope-page-head__theme"
					onClick={ cycleTheme }
					title={ themeLabel( theme ) }
					aria-label={ themeLabel( theme ) }
				>
					{ themeIcon( theme ) }
				</button>
				<div
					className="logscope-page-head__live"
					role="status"
					aria-live="polite"
					title={ __(
						'Entries matching the current filters · fatal count grows as more entries stream in while you scroll.',
						'logscope'
					) }
				>
					<span
						className="logscope-page-head__live-dot"
						aria-hidden="true"
					/>
					<strong>{ logsTotal }</strong>
					<span>
						{ ' ' }
						{ _n( 'entry', 'entries', logsTotal, 'logscope' ) }
					</span>
					{ fatalInLoaded > 0 && (
						<>
							<span className="logscope-page-head__live-sep">
								·
							</span>
							<span className="logscope-page-head__live-fatal">
								{ sprintf(
									/* translators: %d is the number of fatal errors among the entries currently loaded into the view. */
									_n(
										'%d fatal',
										'%d fatal',
										fatalInLoaded,
										'logscope'
									),
									fatalInLoaded
								) }
							</span>
						</>
					) }
				</div>
			</header>
			<div
				className="logscope-tabs"
				role="tablist"
				aria-label={ __( 'Logscope sections', 'logscope' ) }
				onKeyDown={ handleTabKeyDown }
			>
				{ TABS.map( ( tab ) => {
					const isActive = tab.name === activeTab;
					return (
						<button
							key={ tab.name }
							type="button"
							role="tab"
							data-logscope-tab={ tab.name }
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
				<button
					type="button"
					className="logscope-tabs__shortcut-anchor"
					onClick={ () => setHelpOpen( true ) }
					aria-label={ __( 'Keyboard shortcuts', 'logscope' ) }
				>
					{ __( 'Shortcuts (?)', 'logscope' ) }
				</button>
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

function themeIcon( theme ) {
	if ( theme === 'light' ) {
		return '☀';
	}
	if ( theme === 'dark' ) {
		return '☾';
	}
	return '◐';
}

function themeLabel( theme ) {
	if ( theme === 'light' ) {
		return __( 'Theme: Light (click for Dark)', 'logscope' );
	}
	if ( theme === 'dark' ) {
		return __( 'Theme: Dark (click for System)', 'logscope' );
	}
	return __( 'Theme: System (click for Light)', 'logscope' );
}

function TabContent( { name } ) {
	if ( name === 'logs' ) {
		return <LogViewer />;
	}
	if ( name === 'stats' ) {
		return <StatsTab />;
	}
	if ( name === 'settings' ) {
		return <SettingsPanel />;
	}
	return null;
}
