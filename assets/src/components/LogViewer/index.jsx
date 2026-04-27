/**
 * Virtualized log viewer. Wraps react-window's `<List>` so a 5k-entry
 * (or 50k-entry) result set scrolls at 60fps without the DOM growing
 * linearly with the result set.
 *
 * The fixed row height is intentional for the Phase 6 shell: variable
 * heights require measuring each row, which doubles the rendering cost
 * and pushes the "show trace" panel into a second virtualizer. We'll
 * revisit if/when 7.3 reveals a UX problem with the truncated message.
 */
import { useEffect } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Spinner } from '@wordpress/components';
import { List } from 'react-window';

import { STORE_KEY } from '../../store';
import EntryRow from '../EntryRow';
import EmptyState from '../EmptyState';

const ROW_HEIGHT = 48;
const LIST_HEIGHT = 600;

export default function LogViewer() {
	const { items, isLoading, error } = useSelect( ( select ) => {
		const store = select( STORE_KEY );
		return {
			items: store.getLogs(),
			isLoading: store.isLoadingLogs(),
			error: store.getLogsError(),
		};
	}, [] );

	const { fetchLogs } = useDispatch( STORE_KEY );

	useEffect( () => {
		fetchLogs();
	}, [ fetchLogs ] );

	if ( isLoading && items.length === 0 ) {
		return (
			<div className="logscope-viewer logscope-viewer--loading">
				<Spinner />
			</div>
		);
	}

	if ( items.length === 0 ) {
		return <EmptyState error={ error } />;
	}

	return (
		<div
			className="logscope-viewer"
			role="list"
			aria-busy={ isLoading ? 'true' : 'false' }
		>
			<List
				className="logscope-viewer__list"
				rowCount={ items.length }
				rowHeight={ ROW_HEIGHT }
				rowComponent={ EntryRow }
				rowProps={ { items } }
				style={ { height: LIST_HEIGHT } }
			/>
		</div>
	);
}
