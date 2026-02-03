<?php

namespace NewfoldLabs\WP\Module\Staging;

/**
 * Module loading wpunit tests.
 *
 * @coversNothing
 */
class ModuleLoadingWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Verify WordPress factory is available.
	 *
	 * @return void
	 */
	public function test_wordpress_factory_available() {
		$this->assertTrue( function_exists( 'get_option' ) );
		$this->assertNotEmpty( get_option( 'blogname' ) );
	}

	/**
	 * Verify add_action exists (bootstrap uses it).
	 *
	 * @return void
	 */
	public function test_wordpress_hooks_available() {
		$this->assertTrue( function_exists( 'add_action' ) );
		$this->assertTrue( function_exists( 'add_filter' ) );
	}

	/**
	 * Verify Staging classes exist.
	 *
	 * @return void
	 */
	public function test_staging_classes_exist() {
		$this->assertTrue( class_exists( Staging::class ) );
		$this->assertTrue( class_exists( StagingApi::class ) );
		$this->assertTrue( class_exists( StagingFeature::class ) );
		$this->assertTrue( class_exists( Constants::class ) );
		$this->assertTrue( class_exists( StagingMenu::class ) );
	}

	/**
	 * Verify Staging page slug constant.
	 *
	 * @return void
	 */
	public function test_staging_page_slug() {
		$this->assertSame( 'nfd-staging', Staging::PAGE_SLUG );
	}
}
