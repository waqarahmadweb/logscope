/**
 * Single source of truth for the cross-component keyboard-shortcut bus.
 *
 * App emits a `logscope:shortcut` CustomEvent whose `detail` is one of the
 * `SHORTCUT.*` literals; LogViewer and FilterBar subscribe and act on the
 * events that target their DOM. Keeping the constants in their own module
 * (rather than re-exporting from App) avoids the bidirectional import
 * App ↔ leaf-component that would otherwise rely on ESM hoisting to work.
 */
export const SHORTCUT_EVENT = 'logscope:shortcut';

export const SHORTCUT = {
	FOCUS_SEARCH: 'focus-search',
	TOGGLE_GROUPED: 'toggle-grouped',
	TOGGLE_TAIL: 'toggle-tail',
};
