/**
 * Keyboard shortcuts reference. Opened by `?` (handled in the global
 * keyboard hook) or by clicking the toolbar help button. Plain
 * `Modal` from @wordpress/components provides focus trapping, escape-to-
 * close, and aria-labelledby out of the box.
 *
 * The shortcut list inlines `__()` directly into the data array — adding
 * a new entry needs zero plumbing because the call site IS the make-pot
 * extraction site, no centralised translator switch to keep in sync.
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const SHORTCUTS = [
	{
		keys: [ '/' ],
		description: __( 'Focus the regex search field', 'logscope' ),
	},
	{ keys: [ 'g' ], description: __( 'Toggle grouped view', 'logscope' ) },
	{ keys: [ 't' ], description: __( 'Toggle tail mode', 'logscope' ) },
	{ keys: [ '?' ], description: __( 'Open this help dialog', 'logscope' ) },
];

export default function HelpModal( { onClose } ) {
	return (
		<Modal
			title={ __( 'Keyboard shortcuts', 'logscope' ) }
			onRequestClose={ onClose }
			className="logscope-help-modal"
		>
			<dl>
				{ SHORTCUTS.map( ( shortcut ) => (
					<div
						key={ shortcut.keys.join( '+' ) }
						style={ { display: 'contents' } }
					>
						<dt>
							{ shortcut.keys.map( ( key, idx ) => (
								<span key={ key }>
									{ idx > 0 && ' ' }
									<kbd>{ key }</kbd>
								</span>
							) ) }
						</dt>
						<dd>{ shortcut.description }</dd>
					</div>
				) ) }
			</dl>
			<p style={ { marginTop: '16px', fontSize: '12px', opacity: 0.8 } }>
				{ __(
					'Shortcuts are ignored while typing in an input.',
					'logscope'
				) }
			</p>
		</Modal>
	);
}
