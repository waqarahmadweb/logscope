<?php
/**
 * Plugin Name:       Logscope — Debug Log Viewer for WordPress
 * Plugin URI:        https://example.com/logscope
 * Description:       Stream, filter, and group your WordPress debug log without leaving wp-admin. Free forever, GPL v2.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Your Name
 * Author URI:        https://example.com
 * License:           GPL v2 or later
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

add_action( 'plugins_loaded', array( \Logscope\Plugin::class, 'boot' ), 5 );
