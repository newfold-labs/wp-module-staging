<?php

namespace NewfoldLabs\WP\Module\Staging;

require_once __DIR__ . '/StagingWPUnitTest.php';

/**
 * Tests for the pre-staging WP-CLI bootstrap probe.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\StagingBootstrapCheck
 */
class StagingBootstrapCheckWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Directory passed to the probe. Also where the truncated log is written.
	 *
	 * @var string
	 */
	private $directory = '';

	/**
	 * Create a temp directory and clear the exec mock.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$this->directory = trailingslashit( get_temp_dir() ) . 'nfd-bootstrap-check-' . wp_rand( 1000, 9999 );
		wp_mkdir_p( $this->directory );

		StagingWPUnitTest::$exec_responses    = array();
		StagingWPUnitTest::$executed_commands = array();
	}

	/**
	 * Remove the temp directory and clear the exec mock.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->remove_directory( $this->directory );
		StagingWPUnitTest::$exec_responses    = array();
		StagingWPUnitTest::$executed_commands = array();
		parent::tearDown();
	}

	/**
	 * Exit 0 is a pass even when the ok token is not on its own line.
	 *
	 * @return void
	 */
	public function test_exit_zero_passes_when_output_is_not_a_clean_ok_line() {
		$this->queue( 0, array( "\xEF\xBB\xBFok" ) );
		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory ) );

		$this->queue( 0, array( 'foook' ) );
		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory ) );
	}

	/**
	 * A timeout is not a fatal and must not block the staging command.
	 *
	 * @return void
	 */
	public function test_timeout_fails_open() {
		$this->queue( StagingBootstrapCheck::TIMEOUT_EXIT, array( 'timed out' ) );

		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory ) );
		$this->assertStringContainsString( '[WARN] [bootstrap_check] status=124', $this->log_contents() );
	}

	/**
	 * A database error is left to the staging script instead of being blamed on a plugin.
	 *
	 * @return void
	 */
	public function test_database_error_fails_open() {
		$this->queue( 1, array( 'Error: Error establishing a database connection.' ) );

		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory ) );
		$this->assertStringContainsString( '[WARN] [bootstrap_check] status=1', $this->log_contents() );
	}

	/**
	 * A light probe skips regular plugins and themes and still drops the object cache.
	 *
	 * @return void
	 */
	public function test_light_probe_skips_regular_plugins() {
		$this->queue( 0, array( 'ok' ) );

		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory, false ) );
		$this->assertStringContainsString( '--skip-plugins --skip-themes', StagingWPUnitTest::$executed_commands[0] );
		$this->assertStringContainsString( 'wp_cache_delete', StagingWPUnitTest::$executed_commands[1] );
	}

	/**
	 * A missing wp binary fails open, and the probe looks in /usr/local/bin.
	 *
	 * @return void
	 */
	public function test_missing_wp_fails_open_and_command_includes_local_bin() {
		$this->queue( StagingBootstrapCheck::MISSING_EXIT, array( 'wp: command not found' ) );

		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory ) );
		$this->assertStringContainsString( 'PATH="$PATH:/usr/local/bin"', StagingWPUnitTest::$executed_commands[0] );
		$this->assertStringNotContainsString( '--skip-plugins', StagingWPUnitTest::$executed_commands[0] );
		$this->assertCount( 1, StagingWPUnitTest::$executed_commands );
	}

	/**
	 * A directory that is not there does not launch the probe.
	 *
	 * @return void
	 */
	public function test_missing_directory_fails_open() {
		$this->assertTrue( ( new StagingBootstrapCheck() )->check( $this->directory . '/missing' ) );
		$this->assertSame( array(), StagingWPUnitTest::$executed_commands );
	}

	/**
	 * The shutdown marker names the plugin, including a single-file plugin path.
	 *
	 * @return void
	 */
	public function test_fatal_names_the_plugin() {
		$this->queue(
			1,
			array(
				'There has been a critical error on this website.',
				'NFD_BOOTSTRAP_FATAL: syntax error, unexpected identifier "define" in /home/site/wp-content/plugins/bluehost-wordpress-plugin/bluehost-wordpress-plugin.php:36',
			)
		);

		$result = ( new StagingBootstrapCheck() )->check( $this->directory );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( StagingBootstrapCheck::ERROR_CODE, $result->get_error_code() );
		$this->assertStringContainsString( 'bluehost-wordpress-plugin', $result->get_error_message() );
		$this->assertStringContainsString( '[ERROR] [bootstrap_check] status=1', $this->log_contents() );
		$this->assertStringContainsString( 'WP_DISABLE_FATAL_ERROR_HANDLER', StagingWPUnitTest::$executed_commands[0] );
		$this->assertStringContainsString( 'wp_cache_delete', StagingWPUnitTest::$executed_commands[1] );
		$this->assertStringContainsString( '--skip-plugins', StagingWPUnitTest::$executed_commands[1] );
		$this->assertStringNotContainsString( 'enable_loading_object_cache_dropin', StagingWPUnitTest::$executed_commands[1] );
	}

	/**
	 * A create_function() deprecation must not hide a different fatal.
	 *
	 * @return void
	 */
	public function test_create_function_notice_does_not_override_another_fatal() {
		$output = implode(
			"\n",
			array(
				'Deprecated: Function create_function() is deprecated in /var/www/wp-content/plugins/plugin-a/plugin-a.php on line 10',
				'NFD_BOOTSTRAP_FATAL: Uncaught Error: Call to undefined function foo() in /var/www/wp-content/plugins/plugin-b/plugin-b.php:20',
			)
		);

		$message = ( new StagingBootstrapCheck() )->parse_failure( $output );

		$this->assertStringContainsString( 'plugin-b', $message );
		$this->assertStringNotContainsString( 'plugin-a', $message );
		$this->assertStringNotContainsString( 'create_function()', $message );
	}

	/**
	 * A create_function() fatal still names the theme.
	 *
	 * @return void
	 */
	public function test_create_function_on_the_fatal_line_names_the_theme() {
		$output  = 'Fatal error: Uncaught Error: Call to undefined function create_function() in /var/www/wp-content/themes/old-theme/functions.php:12';
		$message = ( new StagingBootstrapCheck() )->parse_failure( $output );

		$this->assertStringContainsString( 'old-theme', $message );
		$this->assertStringContainsString( 'create_function()', $message );
	}

	/**
	 * Cannot redeclare still names both plugins on the fatal line.
	 *
	 * @return void
	 */
	public function test_redeclare_names_both_plugins() {
		$output  = 'Fatal error: Cannot redeclare React\\Promise\\resolve() (previously declared in /var/www/wp-content/plugins/plugin-a/vendor/react/promise/src/functions.php:18) in /var/www/wp-content/plugins/plugin-b/vendor/react/promise/src/functions.php:18';
		$message = ( new StagingBootstrapCheck() )->parse_failure( $output );

		$this->assertStringContainsString( 'plugin-a', $message );
		$this->assertStringContainsString( 'plugin-b', $message );
	}

	/**
	 * A fatal under mu-plugins is not described as a normal plugin.
	 *
	 * @return void
	 */
	public function test_mu_plugin_has_its_own_message() {
		$output  = 'NFD_BOOTSTRAP_FATAL: syntax error in /var/www/wp-content/mu-plugins/host-loader.php:4';
		$message = ( new StagingBootstrapCheck() )->parse_failure( $output );

		$this->assertStringContainsString( 'must-use plugin "host-loader"', $message );
		$this->assertStringContainsString( 'mu-plugins', $message );
	}

	/**
	 * Queue one mocked exec() result.
	 *
	 * @param int      $status Process exit status.
	 * @param string[] $output Captured output lines.
	 * @return void
	 */
	private function queue( $status, array $output ) {
		StagingWPUnitTest::$exec_responses[] = array(
			'status' => $status,
			'output' => $output,
		);
		StagingWPUnitTest::$exec_responses[] = array(
			'status' => 0,
			'output' => array(),
		);
	}

	/**
	 * Contents of the probe log, or an empty string when it was not written.
	 *
	 * @return string
	 */
	private function log_contents() {
		$file = $this->directory . '/nfd-private/' . StagingBootstrapCheck::LOG_FILE;
		if ( ! is_readable( $file ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file );

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Delete a directory created for one test.
	 *
	 * @param string $directory Absolute path.
	 * @return void
	 */
	private function remove_directory( $directory ) {
		if ( '' === $directory || ! is_dir( $directory ) ) {
			return;
		}

		$items = scandir( $directory );
		if ( ! is_array( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $directory . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
				continue;
			}

			wp_delete_file( $path );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $directory );
	}
}
