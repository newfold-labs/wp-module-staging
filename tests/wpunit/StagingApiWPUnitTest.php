<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\ModuleLoader\Container;

/**
 * Tests for StagingApi and route registration.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\StagingApi
 */
class StagingApiWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Container mock for Staging / StagingApi.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Set up container mock and bootstrap Staging so routes are registered on rest_api_init.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->container = $this->create_container_mock();
		add_action(
			'rest_api_init',
			function () {
				$api = new StagingApi( $this->container );
				$api->register_routes();
			}
		);
		do_action( 'rest_api_init' );
	}

	/**
	 * Create a container mock that supports set(), computed(), and plugin().
	 *
	 * Staging constructor calls set('isStaging', computed(...)) and new Constants($container)
	 * which uses container->plugin()->url. StagingApi only needs the container to construct Staging.
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
	 * Verifies StagingApi routes are registered.
	 *
	 * @return void
	 */
	public function test_register_routes_adds_staging_routes() {
		$server = rest_get_server();
		$this->assertNotNull( $server );
		$routes = $server->get_routes();
		$this->assertArrayHasKey( '/newfold-staging/v1/staging', $routes );
		$this->assertArrayHasKey( '/newfold-staging/v1/staging/clone', $routes );
		$this->assertArrayHasKey( '/newfold-staging/v1/staging/deploy', $routes );
		$this->assertArrayHasKey( '/newfold-staging/v1/staging/switch-to', $routes );
	}

	/**
	 * Verifies checkPermission returns false when user cannot manage_options.
	 *
	 * @return void
	 */
	public function test_check_permission_forbidden_when_not_administrator() {
		wp_set_current_user( 0 );
		$api    = new StagingApi( $this->container );
		$result = $api->checkPermission();
		$this->assertFalse( $result );
	}

	/**
	 * Verifies checkPermission returns true when user can manage_options.
	 *
	 * @return void
	 */
	public function test_check_permission_allowed_for_administrator() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$api    = new StagingApi( $this->container );
		$result = $api->checkPermission();
		$this->assertTrue( $result );
	}

	/**
	 * Verifies getStagingDetails returns expected keys (no staging env).
	 *
	 * @return void
	 */
	public function test_get_staging_details_returns_expected_structure() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$api      = new StagingApi( $this->container );
		$response = $api->getStagingDetails();
		$data     = $response->get_data();
		$expected = array(
			'creationDate',
			'currentEnvironment',
			'productionDir',
			'productionThumbnailUrl',
			'productionUrl',
			'stagingDir',
			'stagingExists',
			'stagingThumbnailUrl',
			'stagingUrl',
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $data, "Response should contain key: {$key}" );
		}
		$this->assertFalse( $data['stagingExists'] );
	}
}
