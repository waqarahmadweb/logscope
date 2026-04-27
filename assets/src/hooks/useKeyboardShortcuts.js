/**
 * Global keyboard shortcut handler.
 *
 *   /  focus the regex search input
 *   g  toggle grouped/list view
 *   t  toggle tail mode
 *   ?  open the help modal
 *
 * Ignored when focus is in an input/textarea/select/contenteditable so
 * the user can type a literal `g` or `?` into the regex field without
 * the global handler stealing it. Also ignored when a modifier key is
 * held — `Ctrl+T` is the browser's "new tab", we have no business
 * intercepting it.
 */
import { useEffect } from '@wordpress/element';

const EDITABLE_TAGS = [ 'INPUT', 'TEXTAREA', 'SELECT' ];

function isTypingTarget( target ) {
	if ( ! target ) {
		return false;
	}
	if ( EDITABLE_TAGS.includes( target.tagName ) ) {
		return true;
	}
	if ( target.isContentEditable ) {
		return true;
	}
	return false;
}

export default function useKeyboardShortcuts( {
	onFocusSearch,
	onToggleGrouped,
	onToggleTail,
	onShowHelp,
} ) {
	useEffect( () => {
		if ( typeof document === 'undefined' ) {
			return undefined;
		}
		const handler = ( event ) => {
			if ( event.ctrlKey || event.metaKey || event.altKey ) {
				return;
			}
			if ( isTypingTarget( event.target ) ) {
				// `?` from inside an input is a literal character we must not
				// intercept. The other keys are alphanumeric and obviously
				// belong to the input.
				return;
			}
			switch ( event.key ) {
				case '/':
					event.preventDefault();
					onFocusSearch?.();
					break;
				case 'g':
					event.preventDefault();
					onToggleGrouped?.();
					break;
				case 't':
					event.preventDefault();
					onToggleTail?.();
					break;
				case '?':
					event.preventDefault();
					onShowHelp?.();
					break;
				default:
					break;
			}
		};
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
	}, [ onFocusSearch, onToggleGrouped, onToggleTail, onShowHelp ] );
}
