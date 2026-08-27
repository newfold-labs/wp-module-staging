<?php

namespace NewfoldLabs\WP\Module\Staging;

use function NewfoldLabs\WP\ModuleLoader\container;

require_once __DIR__ . '/StagingExecMock.php';

/**
 * Tests the staging command handshake, output parsing, and cache resync.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\Staging
 */
class StagingWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Fake exec responses consumed by the namespaced exec() replacement.
	 *
	 * @var array
	 */
	public static $exec_responses = array();

	/**
	 * Commands received by the namespaced exec() replacement.
	 *
	 * @var array
	 */
	public static $executed_commands = array();

	/**
	 * Staging instance under test.
	 *
	 * @var Staging
	 */
	private $staging;

	/**
	 * Configure the module container and reset command state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$module_root = dirname( dirname( dirname( __DIR__ ) ) );
		$plugin      = (object) array(
			'url'      => 'https://test.local/',
			'dir'      => $module_root . '/',
			'basename' => 'bluehost/bluehost-wordpress-plugin.php',
			'id'       => 'bluehost',
			'name'     => 'Bluehost',
		);
		$container   = container();
		$container->set( 'plugin', $plugin );

		self::$exec_responses    = array();
		self::$executed_commands = array();
		wp_using_ext_object_cache( false );
		delete_option( 'staging_auth_token' );
		delete_option( 'staging_config' );

		$this->staging = new Staging( $container );
	}

	/**
	 * Restore cache mode and remove options created by a test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_using_ext_object_cache( false );
		delete_option( 'staging_auth_token' );
		delete_option( 'staging_config' );
		parent::tearDown();
	}

	/**
	 * The option-backed token retains the transient's five-minute lifetime.
	 *
	 * @return void
	 */
	public function test_auth_token_ttl_is_five_minutes() {
		$this->assertSame( 300, Staging::AUTH_TOKEN_TTL );
	}

	/**
	 * Cache eviction is skipped when WordPress is not using a drop-in.
	 *
	 * @return void
	 */
	public function test_resync_cache_is_noop_without_external_object_cache() {
		wp_cache_set( 'staging_config', 'cached', 'options' );

		$this->resync_cache();

		$this->assertSame( 'cached', wp_cache_get( 'staging_config', 'options' ) );
	}

	/**
	 * Every option shared with the shell script is evicted from a live cache.
	 *
	 * @return void
	 */
	public function test_resync_cache_evicts_all_shared_option_keys() {
		$keys = array(
			'staging_auth_token',
			'staging_config',
			'staging_environment',
			'nfd_coming_soon',
			'alloptions',
			'notoptions',
		);

		wp_using_ext_object_cache( true );
		foreach ( $keys as $key ) {
			wp_cache_set( $key, 'stale', 'options' );
		}

		$this->resync_cache();

		foreach ( $keys as $key ) {
			$this->assertFalse( wp_cache_get( $key, 'options' ), "Cache key {$key} was not evicted." );
		}
	}

	/**
	 * Unknown commands fail before a token is persisted or a process is run.
	 *
	 * @return void
	 */
	public function test_invalid_command_does_not_create_token_or_execute_script() {
		$result = $this->run_command( 'not-a-command' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_command', $result->get_error_code() );
		$this->assertFalse( get_option( 'staging_auth_token' ) );
		$this->assertSame( array(), self::$executed_commands );
	}

	/**
	 * The token exists only while exec runs and includes an absolute expiry.
	 *
	 * @return void
	 */
	public function test_command_persists_expiring_token_only_during_execution() {
		$started = time();
		$stored  = null;
		$this->queue_exec_response(
			array( '{"status":"success"}' ),
			0,
			function () use ( &$stored ) {
				$stored = get_option( 'staging_auth_token' );
			}
		);

		$result = $this->run_command( 'compat_check' );

		$this->assertSame( array( 'status' => 'success' ), $result );
		$this->assertIsString( $stored );
		$stored = (string) $stored;
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}\.[0-9]+$/', $stored );
		list( $token, $expiry ) = explode( '.', $stored, 2 );
		$this->assertSame( 32, strlen( $token ) );
		$this->assertGreaterThanOrEqual( $started + Staging::AUTH_TOKEN_TTL, (int) $expiry );
		$this->assertLessThanOrEqual( time() + Staging::AUTH_TOKEN_TTL, (int) $expiry );
		$this->assertFalse( get_option( 'staging_auth_token' ) );
	}

	/**
	 * Notices and trailing noise do not hide the last valid status response.
	 *
	 * @return void
	 */
	public function test_command_parses_last_valid_json_status_line_amid_noise() {
		$this->queue_exec_response(
			array(
				'PHP Warning: cache backend unavailable',
				'{"status":"error","message":"earlier response"}',
				'{"status":"success","load_page":"https://example.test"}',
				'trailing shutdown noise',
			)
		);

		$result = $this->run_command( 'compat_check' );

		$this->assertSame(
			array(
				'status'    => 'success',
				'load_page' => 'https://example.test',
			),
			$result
		);
	}

	/**
	 * JSON objects without a status key are not staging responses.
	 *
	 * @return void
	 */
	public function test_command_rejects_output_without_valid_status_response() {
		$this->queue_exec_response( array( 'notice', '{"message":"missing status"}', '' ) );

		$result = $this->run_command( 'compat_check' );

		$this->assertWPError( $result );
		$this->assertSame( 'json_decode', $result->get_error_code() );
		$this->assertFalse( get_option( 'staging_auth_token' ) );
	}

	/**
	 * Script error messages are returned as WP_Error instances.
	 *
	 * @return void
	 */
	public function test_command_converts_error_response_to_wp_error() {
		$this->queue_exec_response( array( '{"status":"error","message":"Unable to authenticate the action."}' ) );

		$result = $this->run_command( 'compat_check' );

		$this->assertWPError( $result );
		$this->assertSame( 'error_response', $result->get_error_code() );
		$this->assertSame( 'Unable to authenticate the action.', $result->get_error_message() );
	}

	/**
	 * A non-zero process exit overrides a success line emitted before cleanup.
	 *
	 * @return void
	 */
	public function test_nonzero_exit_status_overrides_success_response() {
		$this->queue_exec_response( array( '{"status":"success"}' ), 1 );

		$result = $this->run_command( 'compat_check' );

		$this->assertWPError( $result );
		$this->assertSame( 'error_response', $result->get_error_code() );
		$this->assertStringContainsString( 'did not complete', $result->get_error_message() );
	}

	/**
	 * A structured script error remains authoritative on a non-zero exit.
	 *
	 * @return void
	 */
	public function test_nonzero_exit_status_preserves_structured_error_message() {
		$this->queue_exec_response( array( '{"status":"error","message":"Specific failure"}' ), 2 );

		$result = $this->run_command( 'compat_check' );

		$this->assertWPError( $result );
		$this->assertSame( 'Specific failure', $result->get_error_message() );
	}

	/**
	 * Normal commands resync stale keys both before and after the subprocess.
	 *
	 * @return void
	 */
	public function test_normal_command_resyncs_cache_before_and_after_execution() {
		wp_using_ext_object_cache( true );
		wp_cache_set( 'staging_config', 'before', 'options' );
		$cache_value_during_exec = null;
		$this->queue_exec_response(
			array( '{"status":"success"}' ),
			0,
			function () use ( &$cache_value_during_exec ) {
				$cache_value_during_exec = wp_cache_get( 'staging_config', 'options' );
				wp_cache_set( 'staging_environment', 'written-by-script', 'options' );
			}
		);

		$result = $this->run_command( 'create' );

		$this->assertSame( array( 'status' => 'success' ), $result );
		$this->assertNotSame( 'before', $cache_value_during_exec );
		$this->assertFalse( wp_cache_get( 'staging_environment', 'options' ) );
	}

	/**
	 * Compatibility checks avoid cache eviction on both sides of exec.
	 *
	 * @return void
	 */
	public function test_compatibility_check_does_not_resync_object_cache() {
		wp_using_ext_object_cache( true );
		wp_cache_set( 'staging_environment', 'keep-me', 'options' );
		$this->queue_exec_response( array( '{"status":"success"}' ) );

		$result = $this->run_command( 'compat_check' );

		$this->assertSame( array( 'status' => 'success' ), $result );
		$this->assertSame( 'keep-me', wp_cache_get( 'staging_environment', 'options' ) );
	}

	/**
	 * Queue one response for the namespaced exec replacement.
	 *
	 * @param array         $output   Process output lines.
	 * @param int           $status   Process exit status.
	 * @param callable|null $callback Callback invoked while the token is live.
	 *
	 * @return void
	 */
	private function queue_exec_response( $output, $status = 0, $callback = null ) {
		self::$exec_responses[] = array(
			'output'   => $output,
			'status'   => $status,
			'callback' => $callback,
		);
	}

	/**
	 * Invoke Staging::runCommand() for a focused command test.
	 *
	 * @param string     $command Command name.
	 * @param array|null $args    Additional command arguments.
	 *
	 * @return array|\WP_Error
	 */
	private function run_command( $command, $args = null ) {
		$method = new \ReflectionMethod( Staging::class, 'runCommand' );
		$method->setAccessible( true );

		return $method->invoke( $this->staging, $command, $args );
	}

	/**
	 * Invoke Staging::resync_object_cache().
	 *
	 * @return void
	 */
	private function resync_cache() {
		$method = new \ReflectionMethod( Staging::class, 'resync_object_cache' );
		$method->setAccessible( true );
		$method->invoke( $this->staging );
	}
}
