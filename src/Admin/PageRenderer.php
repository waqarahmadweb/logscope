<?php
/**
 * Renders the wp-admin host page that mounts the Logscope React app.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Admin;

/**
 * The PHP side of the admin screen is intentionally bare: a heading for
 * a11y / SEO scrapers and a single mount node. Everything else — tabs,
 * filters, viewer — is rendered by React on top of this skeleton. Keeping
 * the HTML this thin means a JS bundle failure surfaces as a visible
 * empty page rather than a half-rendered hybrid that's harder to debug.
 */
final class PageRenderer {

	/**
	 * DOM id the React app mounts into. Kept in sync with the JS entry in
	 * {@see assets/src/index.js}; if you rename one, rename the other.
	 */
	public const ROOT_ELEMENT_ID = 'logscope-root';

	/**
	 * Outputs the host markup for the React app. Called by WordPress as the
	 * submenu page renderer registered by {@see Menu}.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Logscope', 'logscope' ); ?></h1>
			<div id="<?php echo esc_attr( self::ROOT_ELEMENT_ID ); ?>"></div>
		</div>
		<?php
	}
}
