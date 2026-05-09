/**
 * Light / Dark / System theme switch for the Logscope admin shell.
 *
 * Three-state cycle so the user can opt out of either explicit choice
 * and re-defer to the OS / WP admin color scheme. The chosen value
 * persists to localStorage under `logscope:theme`; missing or
 * unreadable storage (incognito with cookies blocked, hardened
 * profiles) falls back to `system` without throwing — the toggle
 * still cycles, the choice just doesn't survive a reload.
 *
 * The applied value is mirrored onto a `data-logscope-theme` attribute
 * on the `.logscope-app` root and on `<html>` so SCSS can target both
 * the in-plugin surface and any portaled overlays (toasts, modals)
 * with the same selector.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'logscope:theme';
const VALID = [ 'system', 'light', 'dark' ];

function readStored() {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		if ( VALID.includes( raw ) ) {
			return raw;
		}
	} catch ( e ) {
		// localStorage can throw in incognito / disabled-storage modes;
		// fall through to the system default.
	}
	return 'system';
}

function writeStored( value ) {
	try {
		window.localStorage.setItem( STORAGE_KEY, value );
	} catch ( e ) {
		// Same fallibility as readStored() — silently ignore.
	}
}

function applyAttribute( value ) {
	if ( typeof document === 'undefined' ) {
		return;
	}
	const root = document.documentElement;
	if ( value === 'system' ) {
		root.removeAttribute( 'data-logscope-theme' );
	} else {
		root.setAttribute( 'data-logscope-theme', value );
	}
}

export default function useTheme() {
	const [ theme, setTheme ] = useState( () => readStored() );

	useEffect( () => {
		applyAttribute( theme );
		writeStored( theme );
	}, [ theme ] );

	// Three-state cycle: system → light → dark → system. Mirrors the
	// pattern most editor / IDE theme toggles use, so the affordance is
	// learnable in one click.
	const cycle = useCallback( () => {
		setTheme( ( prev ) => {
			const idx = VALID.indexOf( prev );
			return VALID[ ( idx + 1 ) % VALID.length ];
		} );
	}, [] );

	return { theme, setTheme, cycle };
}
