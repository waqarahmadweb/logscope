<?php
/**
 * PHPUnit bootstrap. Loads Composer autoload so tests can resolve both
 * Logscope production classes and Brain Monkey's WordPress function mocks.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
