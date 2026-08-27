<?php
/**
 * PHPUnit bootstrap. Loads Composer autoload so tests can resolve both
 * Logscope production classes and Brain Monkey's WordPress function mocks.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Every src file opens with `defined( 'ABSPATH' ) || exit;` — without the
// constant, the first autoloaded production class kills PHPUnit silently
// (exit code 0, no failure report). Define it before anything autoloads.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR );
}

// Build-time gate for in-process testing seams (e.g. the download route's
// skip-exit param). Never defined in production, so the seams are inert there.
if ( ! defined( 'LOGSCOPE_RUNNING_TESTS' ) ) {
	define( 'LOGSCOPE_RUNNING_TESTS', true );
}

// Brain Monkey mocks functions but does not stub `WP_Error`. The class
// is used by REST controllers to surface validation and authorization
// failures, so a faithful-enough stand-in lives in the test bootstrap.
if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/Stubs/WP_Error.php';
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	require_once __DIR__ . '/Stubs/WP_REST_Request.php';
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	require_once __DIR__ . '/Stubs/WP_REST_Response.php';
}
