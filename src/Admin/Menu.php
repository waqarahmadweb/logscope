<?php
/**
 * Registers the Tools → Logscope submenu page.
 *
 * @package Logscope
 */

declare(strict_types=1);

namespace Logscope\Admin;

use Logscope\Support\Capabilities;

/**
 * Adds Logscope under the core "Tools" menu. Tools is the right home for
 * a debug-log viewer: it's a diagnostic utility, not a content type or a
 * settings-only screen. The capability is the Logscope-specific
 * `logscope_manage` so site owners can grant viewer access without
 * granting full `manage_options`.
 */
final class Menu {

	/**
	 * Submenu slug. Doubles as the `$_GET['page']` value WordPress uses to
	 * route the request, and as the suffix of the screen id
	 * (`tools_page_logscope`) the asset loader keys off in step 6.2.
	 */
	public const PAGE_SLUG = 'logscope';

	/**
	 * The menu page hook returned by `add_submenu_page()` after the menu
	 * is registered. Captured so the asset loader can compare against
	 * `get_current_screen()->id` without re-deriving the slug.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Renderer for the page body.
	 *
	 * @var PageRenderer
	 */
	private PageRenderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param PageRenderer $renderer Page body renderer.
	 */
	public function __construct( PageRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Returns the screen hook captured at registration time, or an empty
	 * string if `register()` has not run yet.
	 *
	 * @return string
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Registers the submenu page. Intended to fire on `admin_menu`.
	 *
	 * @return void
	 */
	public function register(): void {
		$hook = add_submenu_page(
			'tools.php',
			__( 'Logscope', 'logscope' ),
			__( 'Logscope', 'logscope' ),
			Capabilities::required(),
			self::PAGE_SLUG,
			array( $this->renderer, 'render' )
		);

		if ( is_string( $hook ) ) {
			$this->hook_suffix = $hook;
		}
	}
}
