<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\ModuleLoader\Container;
use ReflectionMethod;

/**
 * Tests for async deploy command naming and status polling.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\Staging
 */
class StagingDeployWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Staging instance under test.
	 *
	 * @var Staging
	 */
	private $staging;

	/**
	 * Temporary production directory for deploy result files.
	 *
	 * @var string|null
	 */
	private $temp_production_dir;

	/**
	 * Set up staging instance and restore options after each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->staging = new Staging( $this->create_container_mock() );
	}

	/**
	 * Remove temporary directories created during tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null !== $this->temp_production_dir ) {
			$this->recursive_rmdir( $this->temp_production_dir );
			$this->temp_production_dir = null;
		}

		delete_option( 'staging_config' );
		parent::tearDown();
	}

	/**
	 * Create a container mock for Staging.
	 *
	 * @return Container
	 */
	private function create_container_mock() {
		$container = $this->getMockBuilder( Container::class )
			->disableOriginalConstructor()
			->getMock();

		$container->method( 'set' )->willReturn( null );
		$container->method( 'computed' )->willReturnCallback(
			function ( $callback ) {
				return $callback;
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
	 * Invoke a protected method on the staging instance.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 *
	 * @return mixed
	 */
	private function invokeProtected( $method, array $args = array() ) {
		$reflection = new ReflectionMethod( Staging::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->staging, $args );
	}

	/**
	 * Deploy CLI names are recognized before shell escaping.
	 *
	 * @return void
	 */
	public function test_is_deploy_command_matches_cli_names() {
		$this->assertTrue( $this->invokeProtected( 'isDeployCommand', array( 'deploy_db' ) ) );
		$this->assertTrue( $this->invokeProtected( 'isDeployCommand', array( 'deploy_files' ) ) );
		$this->assertTrue( $this->invokeProtected( 'isDeployCommand', array( 'deploy_files_db' ) ) );
		$this->assertFalse( $this->invokeProtected( 'isDeployCommand', array( 'clone' ) ) );
	}

	/**
	 * Full shell argument strings must not be treated as deploy command names.
	 *
	 * @return void
	 */
	public function test_is_deploy_command_rejects_shell_command_string() {
		$shell_command = "'deploy_db' 'token123' '/var/www/html' '/var/www/html/staging/1234'";
		$this->assertFalse( $this->invokeProtected( 'isDeployCommand', array( $shell_command ) ) );
	}

	/**
	 * REST deploy types map to short CLI command names used for polling.
	 *
	 * @return void
	 */
	public function test_get_deploy_command_for_type() {
		$this->assertSame( 'deploy_db', $this->invokeProtected( 'getDeployCommandForType', array( 'db' ) ) );
		$this->assertSame( 'deploy_files', $this->invokeProtected( 'getDeployCommandForType', array( 'files' ) ) );
		$this->assertSame( 'deploy_files_db', $this->invokeProtected( 'getDeployCommandForType', array( 'all' ) ) );
	}

	/**
	 * Polling matches persisted deploy result by CLI command name, not shell string.
	 *
	 * @return void
	 */
	public function test_get_deploy_command_status_matches_stored_command_name() {
		$this->temp_production_dir = get_temp_dir() . 'staging-deploy-test-' . wp_rand();
		wp_mkdir_p( $this->temp_production_dir . '/nfd-private' );

		update_option(
			'staging_config',
			array(
				'production_dir' => $this->temp_production_dir,
				'staging_dir'    => $this->temp_production_dir . '/staging',
				'production_url' => 'https://test.local',
				'staging_url'    => 'https://test.local/staging',
			)
		);

		$this->invokeProtected(
			'writeDeployResult',
			array(
				array(
					'status'     => 'success',
					'command'    => 'deploy_files',
					'started_at' => time(),
					'message'    => 'Files deployed successfully.',
				),
			)
		);

		$status = $this->invokeProtected( 'getDeployCommandStatus', array( 'deploy_files' ) );

		$this->assertSame( 'success', $status['status'] );
		$this->assertSame( 'deploy_files', $status['command'] );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
