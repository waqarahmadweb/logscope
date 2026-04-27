<?php
/**
 * Enqueues the React bundle on the Logscope admin screen.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Admin;

use Logscope\REST\RestController;
use Logscope\Support\Capabilities;

/**
 * Enqueues `assets/build/index.js` + `.css` only on the Logscope screen.
 * The `@wordpress/scripts` toolchain emits a sibling `index.asset.php`
 * file declaring the runtime's WordPress script dependencies and a
 * cache-busting version hash; we read it instead of hand-maintaining a
 * dep list, so adding `@wordpress/components` to a React file
 * automatically wires `wp-components` into the enqueue.
 *
 * The screen-id gate is the difference between "loads on every wp-admin
 * page" (a wp.org reviewer flag) and "loads only where it's needed" — so
 * the gate stays tight rather than accepting a wildcard.
 */
final class AssetLoader {

	/**
	 * Script + style handle. WordPress uses one handle for both because
	 * `@wordpress/scripts` emits the JS and CSS in the same build under
	 * the same name; sharing the handle keeps the enqueue/dequeue
	 * surface small.
	 */
	public const HANDLE = 'logscope-app';

	/**
	 * JS object name `wp_localize_script` writes into the page. The React
	 * client reads window.LogscopeAdmin to discover the REST root, nonce,
	 * caps, and i18n strings.
	 */
	public const LOCALIZE_OBJECT = 'LogscopeAdmin';

	/**
	 * Captures the hook suffix to gate the enqueue on the right screen.
	 *
	 * @var Menu
	 */
	private Menu $menu;

	/**
	 * Constructor.
	 *
	 * @param Menu $menu Submenu registrar; provides the screen hook to gate on.
	 */
	public function __construct( Menu $menu ) {
		$this->menu = $menu;
	}

	/**
	 * Enqueue callback. Receives the current admin hook suffix from
	 * WordPress (e.g. `tools_page_logscope`) and returns early on every
	 * other screen so unrelated wp-admin pages stay byte-for-byte
	 * identical.
	 *
	 * @param string $hook_suffix The admin hook suffix WordPress passes to
	 *                            `admin_enqueue_scripts` callbacks.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->menu->hook_suffix() ) {
			return;
		}

		$plugin_file = defined( 'LOGSCOPE_PLUGIN_FILE' ) ? (string) constant( 'LOGSCOPE_PLUGIN_FILE' ) : '';
		if ( '' === $plugin_file ) {
			return;
		}

		$plugin_dir = plugin_dir_path( $plugin_file );
		$plugin_url = plugin_dir_url( $plugin_file );

		$asset_file = $plugin_dir . 'assets/build/index.asset.php';
		$script_url = $plugin_url . 'assets/build/index.js';
		$style_url  = $plugin_url . 'assets/build/index.css';

		// `@wordpress/scripts` writes index.asset.php on every successful
		// build. If it's missing we either ran before the first build or
		// shipped a broken zip — either way, bail rather than enqueue a
		// script the runtime cannot resolve. The renderer's empty
		// `#logscope-root` then serves as the visible failure mode.
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] )
			? $asset['dependencies']
			: array();
		$version      = isset( $asset['version'] ) && is_string( $asset['version'] )
			? $asset['version']
			: '';

		wp_enqueue_script(
			self::HANDLE,
			$script_url,
			$dependencies,
			$version,
			true
		);

		// CSS may legitimately be absent (a pure-JS build emits no
		// stylesheet) — only enqueue when the file exists to avoid a 404
		// on every page load.
		$style_path = $plugin_dir . 'assets/build/index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$style_url,
				array(),
				$version
			);
		}

		wp_set_script_translations( self::HANDLE, 'logscope' );

		wp_localize_script(
			self::HANDLE,
			self::LOCALIZE_OBJECT,
			$this->localized_payload()
		);
	}

	/**
	 * Builds the bootstrap payload exposed to the React app. Kept out of
	 * `enqueue()` so it can be unit-tested without an `admin_enqueue_scripts`
	 * round-trip.
	 *
	 * @return array<string, mixed>
	 */
	public function localized_payload(): array {
		return array(
			'restUrl'   => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/' ) ),
			'restRoot'  => esc_url_raw( rest_url() ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'canManage' => Capabilities::has_manage_cap(),
		);
	}
}
