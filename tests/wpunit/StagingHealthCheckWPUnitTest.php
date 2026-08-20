<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\ModuleLoader\Container;

/**
 * Tests for StagingHealthCheck repair behavior.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\StagingHealthCheck
 */
class StagingHealthCheckWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Container mock.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->container = $this->create_container_mock();
		delete_option( 'staging_config' );
		delete_option( 'staging_environment' );
		delete_transient( StagingHealthCheck::get_notice_transient_key() );
	}

	/**
	 * Tear down options.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( 'staging_config' );
		delete_option( 'staging_environment' );
		delete_transient( StagingHealthCheck::get_notice_transient_key() );
		parent::tearDown();
	}

	/**
	 * Create container mock for Staging.
	 *
	 * @return Container
	 */
	private function create_container_mock() {
		$container = $this->getMockBuilder( Container::class )
			->disableOriginalConstructor()
			->getMock();

		$container->method( 'set' )->willReturn( null );
		$container->method( 'computed' )->willReturnCallback(
			function ( $callable ) {
				return $callable;
			}
		);

		$plugin = (object) array(
			'url'      => 'https://test.local/',
			'dir'      => dirname( dirname( dirname( __DIR__ ) ) ) . '/',
			'basename' => 'bluehost/bluehost-wordpress-plugin.php',
			'id'       => 'bluehost',
			'name'     => 'Bluehost',
		);
		$container->method( 'plugin' )->willReturn( $plugin );

		return $container;
	}

	/**
	 * Orphaned staging_config is removed when staging_dir is missing.
	 *
	 * @return void
	 */
	public function test_orphan_cleanup_deletes_missing_staging_dir_config() {
		update_option(
			'staging_config',
			array(
				'creation_date'  => 'Jan 1, 2025',
				'production_dir' => ABSPATH,
				'production_url' => site_url(),
				'staging_dir'    => ABSPATH . 'staging/9999/',
				'staging_url'    => site_url( '/staging/9999' ),
			)
		);
		update_option( 'staging_environment', 'production' );

		$staging  = new Staging( $this->container );
		$health   = new StagingHealthCheck( $staging );
		$repaired = $health->maybe_repair();

		$this->assertTrue( $repaired );
		$this->assertFalse( get_option( 'staging_config' ) );
		$this->assertTrue( (bool) get_transient( StagingHealthCheck::get_notice_transient_key() ) );
	}

	/**
	 * Swapped URLs in staging_config are corrected on production.
	 *
	 * @return void
	 */
	public function test_fixes_swapped_urls_on_production() {
		$staging_dir = ABSPATH . 'staging/1111/';
		wp_mkdir_p( $staging_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		touch( $staging_dir . 'wp-config.php' );

		update_option(
			'staging_config',
			array(
				'creation_date'  => 'Jan 1, 2025',
				'production_dir' => $staging_dir,
				'production_url' => site_url( '/staging/1111' ),
				'staging_dir'    => ABSPATH,
				'staging_url'    => untrailingslashit( site_url() ),
			)
		);

		$staging  = new Staging( $this->container );
		$health   = new StagingHealthCheck( $staging );
		$repaired = $health->maybe_repair();

		$this->assertTrue( $repaired );
		$config = get_option( 'staging_config' );
		$this->assertFalse( StagingPath::config_has_swapped_urls( $config ) );
		$this->assertSame( $staging_dir, $config['staging_dir'] );

		wp_delete_file( $staging_dir . 'wp-config.php' );
		if ( is_dir( $staging_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $staging_dir );
		}
		if ( is_dir( ABSPATH . 'staging' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( ABSPATH . 'staging' );
		}
	}

	/**
	 * No repair when production config is already valid and dir exists.
	 *
	 * @return void
	 */
	public function test_no_repair_when_config_valid() {
		$staging_dir = ABSPATH . 'staging/2222/';
		wp_mkdir_p( $staging_dir );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		touch( $staging_dir . 'wp-config.php' );

		update_option(
			'staging_config',
			array(
				'creation_date'  => 'Jan 1, 2025',
				'production_dir' => StagingPath::normalize_trailing_slash( ABSPATH ),
				'production_url' => untrailingslashit( site_url() ),
				'staging_dir'    => $staging_dir,
				'staging_url'    => untrailingslashit( site_url() ) . '/staging/2222',
			)
		);
		update_option( 'staging_environment', 'production' );

		$staging  = new Staging( $this->container );
		$health   = new StagingHealthCheck( $staging );
		$repaired = $health->maybe_repair();

		$this->assertFalse( $repaired );

		wp_delete_file( $staging_dir . 'wp-config.php' );
		if ( is_dir( $staging_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $staging_dir );
		}
		if ( is_dir( ABSPATH . 'staging' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( ABSPATH . 'staging' );
		}
	}
}
