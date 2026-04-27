/**
 * Logscope — React entry point.
 *
 * Mounts the app into the `#logscope-root` node that
 * `Logscope\Admin\PageRenderer` writes. The DOM id is the
 * single contract between PHP and React; the constant
 * `PageRenderer::ROOT_ELEMENT_ID` mirrors this string.
 */
import { createRoot } from '@wordpress/element';

// Importing the store has side effects — register() runs at import time.
import './store';
import App from './components/App';
import './style.scss';

const ROOT_ELEMENT_ID = 'logscope-root';

function mount() {
	const node = document.getElementById( ROOT_ELEMENT_ID );
	if ( ! node ) {
		return;
	}
	createRoot( node ).render( <App /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
