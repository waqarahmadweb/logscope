<?php
/**
 * Unit tests for the admin PageRenderer.
 *
 * @package Logscope\Tests
 */

declare(strict_types=1);

namespace Logscope\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use Logscope\Admin\PageRenderer;
use Logscope\Tests\TestCase;

final class PageRendererTest extends TestCase {

	public function test_render_outputs_wrap_with_heading_and_root_element(): void {
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		$renderer = new PageRenderer();

		ob_start();
		$renderer->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap">', $html );
		$this->assertStringContainsString( '<h1>Logscope</h1>', $html );
		$this->assertStringContainsString( 'id="' . PageRenderer::ROOT_ELEMENT_ID . '"', $html );
	}

	public function test_root_element_id_constant_matches_react_entry_contract(): void {
		// React entry (assets/src/index.js) mounts on the same id.
		// If you rename one, rename the other.
		$this->assertSame( 'logscope-root', PageRenderer::ROOT_ELEMENT_ID );
	}
}
