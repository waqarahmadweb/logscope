/**
 * Renders the stack of transient toast messages from the store as
 * @wordpress/components Snackbars. The slice is filled by `pushToast`
 * dispatches across the app — REST failures, copy-to-clipboard
 * confirmations, etc. — and self-prunes via auto-dismiss.
 *
 * Snackbar already wires aria-live for screen readers; the host wrapper
 * only positions the stack and forwards the dismiss callback.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Snackbar } from '@wordpress/components';

import { STORE_KEY } from '../../store';

const DEFAULT_TIMEOUT_MS = 5000;

export default function ToastHost() {
	const toasts = useSelect(
		( select ) => select( STORE_KEY ).getToasts(),
		[]
	);
	const { dismissToast } = useDispatch( STORE_KEY );

	// Single timer-pruning effect — each toast carries its own timestamp;
	// the effect re-arms whenever the queue changes.
	useEffect( () => {
		if ( toasts.length === 0 ) {
			return undefined;
		}
		const timers = toasts.map( ( toast ) => {
			const remaining = Math.max( 0, toast.expiresAt - Date.now() );
			return setTimeout( () => dismissToast( toast.id ), remaining );
		} );
		return () => timers.forEach( clearTimeout );
	}, [ toasts, dismissToast ] );

	if ( toasts.length === 0 ) {
		return null;
	}

	// Snackbar self-announces via its own aria-live region; wrapping
	// another aria-live around it would double-announce on some readers.
	return (
		<div className="logscope-toast-host">
			{ toasts.map( ( toast ) => (
				<Snackbar
					key={ toast.id }
					status={ toast.status || 'info' }
					onRemove={ () => dismissToast( toast.id ) }
				>
					{ toast.message }
				</Snackbar>
			) ) }
		</div>
	);
}

export { DEFAULT_TIMEOUT_MS };
