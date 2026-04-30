/**
 * Muted signatures management panel — lists every entry in the
 * `MuteStore` with an "Unmute" affordance, fetched on mount through
 * the new `mutes` slice. Lives at the bottom of the Settings tab so
 * an admin who muted a noisy signature from the Logs tab has a
 * predictable place to undo it.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';

import { STORE_KEY } from '../../store';

export default function MutedSignaturesPanel() {
	const { items, isLoading, isSaving, loadError } = useSelect(
		( select ) => ( {
			items: select( STORE_KEY ).getMutes(),
			isLoading: select( STORE_KEY ).isLoadingMutes(),
			isSaving: select( STORE_KEY ).isSavingMutes(),
			loadError: select( STORE_KEY ).getMutesLoadError(),
		} ),
		[]
	);
	const { fetchMutes, unmuteSignature } = useDispatch( STORE_KEY );

	useEffect( () => {
		fetchMutes();
	}, [ fetchMutes ] );

	return (
		<section className="logscope-muted-panel">
			<h3>{ __( 'Muted signatures', 'logscope' ) }</h3>
			<p className="logscope-muted-panel__hint">
				{ __(
					'Muted signatures are hidden from the default Logs view. They still accumulate in the file — unmute to surface them again.',
					'logscope'
				) }
			</p>
			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }
			{ ! isLoading && items.length === 0 && (
				<p className="logscope-muted-panel__empty">
					{ __( 'No muted signatures.', 'logscope' ) }
				</p>
			) }
			{ items.length > 0 && (
				<ul className="logscope-muted-panel__list" role="list">
					{ items.map( ( item ) => (
						<li
							key={ item.signature }
							className="logscope-muted-panel__item"
						>
							<div className="logscope-muted-panel__meta">
								<code className="logscope-muted-panel__sig">
									{ item.signature }
								</code>
								{ item.reason && (
									<span className="logscope-muted-panel__reason">
										{ item.reason }
									</span>
								) }
							</div>
							<Button
								variant="secondary"
								isDestructive
								disabled={ isSaving }
								onClick={ () =>
									unmuteSignature( item.signature )
								}
							>
								{ __( 'Unmute', 'logscope' ) }
							</Button>
						</li>
					) ) }
				</ul>
			) }
		</section>
	);
}
