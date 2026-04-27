/**
 * Returns `value` after it has been stable for `delayMs` milliseconds.
 * Used by the FilterBar to keep regex-search keystrokes from firing a
 * REST query on every character (300ms is the AC).
 */
import { useEffect, useState } from '@wordpress/element';

export default function useDebouncedValue( value, delayMs ) {
	const [ debounced, setDebounced ] = useState( value );

	useEffect( () => {
		const handle = setTimeout( () => setDebounced( value ), delayMs );
		return () => clearTimeout( handle );
	}, [ value, delayMs ] );

	return debounced;
}
