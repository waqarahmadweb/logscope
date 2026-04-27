/**
 * Keyboard shortcuts reference. Opened by `?` (handled in the global
 * keyboard hook) or by clicking the toolbar help button. Plain
 * `Modal` from @wordpress/components provides focus trapping, escape-to-
 * close, and aria-labelledby out of the box.
 */
import { Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const SHORTCUTS = [
	{ keys: [ '/' ], description: 'Focus the regex search field' },
	{ keys: [ 'g' ], description: 'Toggle grouped view' },
	{ keys: [ 't' ], description: 'Toggle tail mode' },
	{ keys: [ '?' ], description: 'Open this help dialog' },
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
						<dd>{ describe( shortcut.description ) }</dd>
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

// Inline so xgettext / make-pot picks up each user-facing string at a
// __() call site rather than a runtime lookup.
function describe( token ) {
	switch ( token ) {
		case 'Focus the regex search field':
			return __( 'Focus the regex search field', 'logscope' );
		case 'Toggle grouped view':
			return __( 'Toggle grouped view', 'logscope' );
		case 'Toggle tail mode':
			return __( 'Toggle tail mode', 'logscope' );
		case 'Open this help dialog':
			return __( 'Open this help dialog', 'logscope' );
		default:
			return token;
	}
}
