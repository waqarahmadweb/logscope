/**
 * Loading skeleton primitives. Replace the bare `<Spinner />` previously
 * shown on initial fetch — the spinner gave no sense of layout, so the
 * page looked broken before the first byte arrived. Skeleton rows hint
 * at the eventual list/form shape and respect `prefers-reduced-motion`
 * via the stylesheet.
 */
import { __ } from '@wordpress/i18n';

export function ListSkeleton( { rows = 6 } ) {
	return (
		<div
			className="logscope-viewer logscope-viewer--loading"
			role="status"
			aria-live="polite"
			aria-label={ __( 'Loading log entries…', 'logscope' ) }
		>
			{ Array.from( { length: rows } ).map( ( _, i ) => (
				<div
					key={ i }
					className="logscope-skeleton logscope-skeleton--row"
					aria-hidden="true"
				/>
			) ) }
		</div>
	);
}

export function FormSkeleton() {
	return (
		<div
			className="logscope-settings-panel"
			role="status"
			aria-live="polite"
			aria-label={ __( 'Loading settings…', 'logscope' ) }
		>
			<div className="logscope-settings-panel__stack">
				<div>
					<div
						className="logscope-skeleton logscope-skeleton--label"
						aria-hidden="true"
					/>
					<div
						className="logscope-skeleton logscope-skeleton--field"
						aria-hidden="true"
					/>
				</div>
				<div>
					<div
						className="logscope-skeleton logscope-skeleton--label"
						aria-hidden="true"
					/>
					<div
						className="logscope-skeleton logscope-skeleton--field"
						aria-hidden="true"
					/>
				</div>
			</div>
		</div>
	);
}
