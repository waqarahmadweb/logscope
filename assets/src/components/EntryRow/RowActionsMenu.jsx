/**
 * Floating quick-action menu for a single log entry. Triggered by the
 * row's `⋮` button or by a right-click anywhere on the row.
 *
 * Positioned with `position: fixed` at viewport coordinates so the
 * popover escapes react-window's `overflow: auto` scroll container —
 * an absolutely-positioned child would be clipped at the row bounds.
 * The opener is responsible for passing screen-space `(x, y)` from a
 * mouse event or from a button's `getBoundingClientRect()`.
 *
 * Closes on outside click, Escape, scroll, or window resize. We do not
 * try to reposition on layout changes; closing is simpler and avoids
 * the menu drifting onto stale row content.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { createPortal, useEffect, useRef } from '@wordpress/element';

import { STORE_KEY } from '../../store';

const MENU_WIDTH = 220;
const MENU_MARGIN = 8;

/**
 * Joins frame DTOs into a stack-trace block matching the format users
 * see in `debug.log`. Best-effort: not every frame carries every field.
 */
function framesToText( frames ) {
	if ( ! Array.isArray( frames ) || frames.length === 0 ) {
		return '';
	}
	return frames
		.map( ( frame, idx ) => {
			if ( frame?.raw ) {
				return frame.raw;
			}
			const callee =
				frame?.class && frame?.method
					? `${ frame.class }->${ frame.method }`
					: frame?.method || '';
			const where =
				frame?.file && frame?.line
					? `${ frame.file }:${ frame.line }`
					: frame?.file || '';
			return `#${ idx } ${ where } ${ callee }`.trim();
		} )
		.join( '\n' );
}

async function copy( value, dispatchToast, successMessage ) {
	if ( ! value || ! navigator?.clipboard ) {
		dispatchToast( {
			message: __( 'Nothing to copy.', 'logscope' ),
			status: 'warning',
		} );
		return;
	}
	try {
		await navigator.clipboard.writeText( value );
		dispatchToast( { message: successMessage, status: 'success' } );
	} catch ( e ) {
		dispatchToast( {
			message: __( 'Could not copy to clipboard.', 'logscope' ),
			status: 'error',
		} );
	}
}

export default function RowActionsMenu( { entry, position, onClose } ) {
	const menuRef = useRef( null );
	const { pushToast, setFilters } = useDispatch( STORE_KEY );

	useEffect( () => {
		const onDocMouseDown = ( e ) => {
			if ( menuRef.current && ! menuRef.current.contains( e.target ) ) {
				onClose();
			}
		};
		const onKey = ( e ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		// Closing on scroll/resize avoids the menu drifting onto a
		// different row after a virtualised re-render.
		const onLayoutChange = () => onClose();
		document.addEventListener( 'mousedown', onDocMouseDown );
		document.addEventListener( 'keydown', onKey );
		window.addEventListener( 'resize', onLayoutChange );
		window.addEventListener( 'scroll', onLayoutChange, true );
		return () => {
			document.removeEventListener( 'mousedown', onDocMouseDown );
			document.removeEventListener( 'keydown', onKey );
			window.removeEventListener( 'resize', onLayoutChange );
			window.removeEventListener( 'scroll', onLayoutChange, true );
		};
	}, [ onClose ] );

	if ( ! entry || ! position ) {
		return null;
	}

	// Clamp the menu so it never spills off the right or bottom of the
	// viewport. Vertical clamp uses an estimated 280px tall menu — the
	// real height is known after layout but a conservative pre-clamp is
	// good enough to keep all items reachable.
	const vw =
		typeof window !== 'undefined' ? window.innerWidth : MENU_WIDTH * 4;
	const vh = typeof window !== 'undefined' ? window.innerHeight : 600;
	const left = Math.max(
		MENU_MARGIN,
		Math.min( position.x, vw - MENU_WIDTH - MENU_MARGIN )
	);
	const top = Math.max(
		MENU_MARGIN,
		Math.min( position.y, vh - 280 - MENU_MARGIN )
	);

	const path =
		entry.file && entry.line
			? `${ entry.file }:${ entry.line }`
			: entry.file || '';
	const hasTrace = Array.isArray( entry.frames ) && entry.frames.length > 0;

	const act = ( fn ) => {
		fn();
		onClose();
	};

	// Portal to <body> so the fixed-position menu escapes react-window's
	// per-row `transform: translateY(...)`. A transformed ancestor would
	// otherwise become the containing block for fixed descendants (per
	// the CSS spec), which both reanchors the coordinates and lets the
	// list's `overflow: auto` clip the menu.
	if ( typeof document === 'undefined' ) {
		return null;
	}
	return createPortal(
		<div
			ref={ menuRef }
			className="logscope-row-menu"
			role="menu"
			style={ {
				position: 'fixed',
				top,
				left,
				width: MENU_WIDTH,
			} }
		>
			<button
				type="button"
				role="menuitem"
				className="logscope-row-menu__item"
				disabled={ ! entry.raw }
				onClick={ () =>
					act( () =>
						copy(
							entry.raw,
							pushToast,
							__( 'Raw line copied.', 'logscope' )
						)
					)
				}
			>
				{ __( 'Copy raw', 'logscope' ) }
			</button>
			<button
				type="button"
				role="menuitem"
				className="logscope-row-menu__item"
				disabled={ ! entry.message }
				onClick={ () =>
					act( () =>
						copy(
							entry.message,
							pushToast,
							__( 'Message copied.', 'logscope' )
						)
					)
				}
			>
				{ __( 'Copy message', 'logscope' ) }
			</button>
			<button
				type="button"
				role="menuitem"
				className="logscope-row-menu__item"
				disabled={ ! path }
				onClick={ () =>
					act( () =>
						copy(
							path,
							pushToast,
							__( 'Path copied.', 'logscope' )
						)
					)
				}
			>
				{ __( 'Copy file:line', 'logscope' ) }
			</button>
			{ hasTrace && (
				<button
					type="button"
					role="menuitem"
					className="logscope-row-menu__item"
					onClick={ () =>
						act( () =>
							copy(
								framesToText( entry.frames ),
								pushToast,
								__( 'Stack trace copied.', 'logscope' )
							)
						)
					}
				>
					{ __( 'Copy stack trace', 'logscope' ) }
				</button>
			) }
			<button
				type="button"
				role="menuitem"
				className="logscope-row-menu__item"
				onClick={ () =>
					act( () =>
						copy(
							jsonForEntry( entry ),
							pushToast,
							__( 'Entry JSON copied.', 'logscope' )
						)
					)
				}
			>
				{ __( 'Copy as JSON', 'logscope' ) }
			</button>
			<div className="logscope-row-menu__sep" role="separator" />
			<button
				type="button"
				role="menuitem"
				className="logscope-row-menu__item"
				disabled={ ! entry.source }
				onClick={ () =>
					act( () => {
						setFilters( { source: entry.source } );
						pushToast( {
							message: __(
								'Filtered to this source.',
								'logscope'
							),
							status: 'info',
						} );
					} )
				}
			>
				{ __( 'Filter to this source', 'logscope' ) }
			</button>
		</div>,
		document.body
	);
}

/**
 * Strips the internal `_clientId` stamp before serialising — it is a
 * client-side bookkeeping field and would just confuse anyone pasting
 * the JSON into a ticket.
 */
function jsonForEntry( entry ) {
	const { _clientId, ...rest } = entry || {};
	void _clientId;
	return JSON.stringify( rest, null, 2 );
}
