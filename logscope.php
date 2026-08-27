<?php
/**
 * Plugin Name:       Logscope
 * Plugin URI:        https://github.com/waqarahmadweb/logscope
 * Description:       Stream, filter, and group your WordPress debug log without leaving wp-admin. Free forever, GPL v2.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Waqar Ahmad
 * Author URI:        https://github.com/waqarahmadweb
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       logscope
 * Domain Path:       /languages
 *
 * @package Logscope
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOGSCOPE_PLUGIN_FILE', __FILE__ );

// The distribution zip bundles a --no-dev vendor/ (see bin/build-zip.ps1),
// but a raw git clone has none until `composer install` runs. Fail with a
// visible admin notice instead of a fatal so a reviewer testing from the
// clone gets a diagnosable message rather than a white screen.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Logscope is missing its autoloader. Run "composer install" in the plugin directory, or reinstall from the packaged plugin zip.', 'logscope' );
			echo '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( \Logscope\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Logscope\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Logscope\Plugin::class, 'boot' ), 5 );
