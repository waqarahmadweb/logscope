<?php
/**
 * Plugin Name:       Logscope — Debug Log Viewer
 * Plugin URI:        https://github.com/waqarahmadweb/logscope
 * Description:       Stream, filter, and group your WordPress debug log without leaving wp-admin. Free forever, GPL v2.
 * Version:           0.18.0
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

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( \Logscope\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Logscope\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Logscope\Plugin::class, 'boot' ), 5 );
