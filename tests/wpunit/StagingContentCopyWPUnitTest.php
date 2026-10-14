<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\Module\Staging\Copy\ContentCopyController;
use function NewfoldLabs\WP\ModuleLoader\container;

// The namespaced exec() shadow (and the static response/command queues it reads) live with
// StagingWPUnitTest; load both so the stepped deploy's bash verbs hit the shadow, not a real shell.
require_once __DIR__ . '/StagingWPUnitTest.php';
require_once __DIR__ . '/StagingExecMock.php';

/**
 * Tests the stepped, chunked content-copy deploy (ContentCopyController + the new bash verbs).
 *
 * Exercises the whole deploy-files vertical slice end to end against a tiny real fixture tree: the
 * phase machine advances setup -> copy -> finalize -> done, the CopyEngine builds the `-new` delta
 * tree in PHP, the two bash bookends run through Staging::runCommand (so the namespaced exec() shadow
 * intercepts them), and the shared deploy-result file surfaces completion through the legacy status
 * path. The finalize verb is asserted to run only AFTER the copy has produced the `-new` tree.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\Copy\ContentCopyController
 * @covers \NewfoldLabs\WP\Module\Staging\Staging
 */
class StagingContentCopyWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * A pid that cannot belong to a running process, above the pid_max ceiling of both Linux
	 * (4194304) and macOS (99998).
	 *
	 * simulate_bash_lock records this rather than getmypid(): a real verb has EXITED by the time
	 * exec() returns, so the lock a later phase finds always carries a departed pid. A live one is
	 * the single state lock_check refuses to reclaim, so recording it would make the fixture model
	 * something bash never produces.
	 */
	const DEPARTED_VERB_PID = 99999999;

	/**
	 * Staging instance under test.
	 *
	 * @var Staging
	 */
	private $staging;

	/**
	 * Temporary production directory (holds nfd-private, the `-new` tree, and the result file).
	 *
	 * @var string|null
	 */
	private $temp_production_dir;

	/**
	 * Temporary staging directory (the copy source).
	 *
	 * @var string|null
	 */
	private $temp_staging_dir;

	/**
	 * Configure the module container, reset exec state, and build a Staging instance.
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

		StagingWPUnitTest::$exec_responses    = array();
		StagingWPUnitTest::$executed_commands = array();
		wp_using_ext_object_cache( false );
		delete_option( 'staging_auth_token' );
		delete_option( 'staging_config' );

		$this->staging = new Staging( $container );
	}

	/**
	 * Remove temporary directories and options created during a test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( array( $this->temp_production_dir, $this->temp_staging_dir ) as $dir ) {
			if ( null !== $dir ) {
				$this->recursive_rmdir( $dir );
			}
		}
		$this->temp_production_dir = null;
		$this->temp_staging_dir    = null;

		StagingWPUnitTest::$exec_responses    = array();
		StagingWPUnitTest::$executed_commands = array();
		delete_option( 'staging_auth_token' );
		delete_option( 'staging_config' );
		parent::tearDown();
	}

	/**
	 * A deploy-files job advances setup -> copy -> core -> finalize -> done against a real fixture tree.
	 *
	 * @return void
	 */
	public function test_content_copy_advances_through_all_phases_and_builds_new_tree() {
		$this->set_up_fixture_tree();

		$uploads_new_file = $this->temp_production_dir . '/wp-content/uploads-new/a.txt';

		// Record, at the instant each bash verb runs, whether the copied `-new` tree exists yet. This
		// is what proves ordering: setup runs before any copy, core and finalize run only after it
		// completes.
		$setup_saw_new_tree    = null;
		$core_saw_new_tree     = null;
		$finalize_saw_new_tree = null;

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response(
				'Content deploy setup complete.',
				function () use ( &$setup_saw_new_tree, $uploads_new_file ) {
					$setup_saw_new_tree = file_exists( $uploads_new_file );
				}
			),
			$this->verb_response(
				'Content deploy core download complete.',
				function () use ( &$core_saw_new_tree, $uploads_new_file ) {
					$core_saw_new_tree = file_exists( $uploads_new_file );
				}
			),
			$this->verb_response(
				'Files deployed successfully.',
				function () use ( &$finalize_saw_new_tree, $uploads_new_file ) {
					$finalize_saw_new_tree = file_exists( $uploads_new_file );
				}
			),
		);

		$controller = new ContentCopyController( $this->staging );

		// Drive the poll loop exactly as the REST/cron driver would: one bounded unit per call.
		$phases_seen = array();
		$result      = array( 'status' => 'running' );
		$guard       = 0;
		do {
			$result        = $controller->advance();
			$phases_seen[] = $result['phase'];
			++$guard;
		} while ( 'running' === $result['status'] && $guard < 100 );

		// Terminal state.
		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( ContentCopyController::PHASE_DONE, $result['phase'] );
		$this->assertLessThan( 100, $guard, 'The job should finish well within the poll guard.' );

		// The phase machine passed through copy, core, and finalize on its way to done.
		$this->assertContains( ContentCopyController::PHASE_COPY, $phases_seen );
		$this->assertContains( ContentCopyController::PHASE_CORE, $phases_seen );
		$this->assertContains( ContentCopyController::PHASE_FINALIZE, $phases_seen );

		// The CopyEngine built the `-new` delta tree in PHP (staging content, under the -new suffix).
		$this->assertFileExists( $uploads_new_file );
		$this->assertSame( 'staged-a', file_get_contents( $uploads_new_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertFileExists( $this->temp_production_dir . '/wp-content/themes-new/t.txt' );

		// Exactly the three bash bookends ran, in order, through the normal command path.
		$this->assertCount( 3, StagingWPUnitTest::$executed_commands );
		$this->assertStringContainsString( 'deploy_content_setup', StagingWPUnitTest::$executed_commands[0] );
		$this->assertStringContainsString( 'deploy_content_core', StagingWPUnitTest::$executed_commands[1] );
		$this->assertStringContainsString( 'deploy_content_finalize', StagingWPUnitTest::$executed_commands[2] );

		// Ordering: setup ran before any copy; core and finalize ran only after the copy built the tree.
		$this->assertFalse( $setup_saw_new_tree, 'Setup must run before the copy phase builds the -new tree.' );
		$this->assertTrue( $core_saw_new_tree, 'Core download must run only after the copy completes.' );
		$this->assertTrue( $finalize_saw_new_tree, 'Finalize must run only after the copy completes.' );
	}

	/**
	 * Status reflects progress during the copy phase and completion afterwards, and the shared
	 * deploy-result file lets the legacy deploy status path report the finished job.
	 *
	 * @return void
	 */
	public function test_status_reflects_progress_and_feeds_legacy_deploy_status() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response( 'Files deployed successfully.' ),
		);

		$controller = new ContentCopyController( $this->staging );

		// Before anything runs there is no job.
		$this->assertSame( 'idle', $controller->status()['status'] );

		// First unit runs setup and moves into the copy phase.
		$after_setup = $controller->advance();
		$this->assertSame( 'running', $after_setup['status'] );

		// A read-only status during the run exposes the phase and a progress array with a percent.
		$mid = $controller->status();
		$this->assertSame( 'running', $mid['status'] );
		$this->assertArrayHasKey( 'progress', $mid );

		// Drive to completion.
		$result = $after_setup;
		$guard  = 0;
		while ( 'running' === $result['status'] && $guard < 100 ) {
			$result = $controller->advance();
			++$guard;
		}

		$this->assertSame( 'success', $result['status'] );

		// status() now reports success with a fully copied progress reading.
		$final = $controller->status();
		$this->assertSame( 'success', $final['status'] );
		$this->assertSame( ContentCopyController::PHASE_DONE, $final['phase'] );
		$this->assertArrayHasKey( 'percent', $final['progress'] );
		$this->assertSame( 100, $final['progress']['percent'] );

		// The legacy GET /staging/deploy?type=files status path reads the same result file.
		$legacy = $this->staging->getDeployStatus( 'files' );
		$this->assertSame( 'success', $legacy['status'] );
		$this->assertSame( 'deploy_files', $legacy['command'] );
	}

	/**
	 * An unsupported copy type is rejected before any work starts.
	 *
	 * @return void
	 */
	public function test_unsupported_copy_type_is_rejected() {
		$this->set_up_fixture_tree();

		$result = $this->staging->advanceContentCopy( 'deploy_db' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_copy_type', $result->get_error_code() );
	}

	/**
	 * The engine config resolves the deploy-files roots and the `-new` hardlink-delta suffix.
	 *
	 * @return void
	 */
	public function test_engine_config_targets_wp_content_with_new_suffix() {
		$this->set_up_fixture_tree();

		$controller = new ContentCopyController( $this->staging );
		$config     = $controller->engineConfig();

		$this->assertSame( $this->temp_staging_dir . '/wp-content', $config['source_root'] );
		$this->assertSame( $this->temp_production_dir . '/wp-content', $config['dest_root'] );
		$this->assertSame( '-new', $config['dest_suffix'] );
		$this->assertSame( $this->temp_production_dir . '/wp-content', $config['link_dest_root'] );
		$this->assertSame( $this->temp_production_dir . '/nfd-private/copy-deploy-files', $config['job_dir'] );
	}

	/**
	 * Create disposable production + staging trees and a small content fixture under staging.
	 *
	 * @return void
	 */
	private function set_up_fixture_tree() {
		$this->temp_production_dir = get_temp_dir() . 'staging-copy-prod-' . wp_rand();
		$this->temp_staging_dir    = $this->temp_production_dir . '/staging';

		wp_mkdir_p( $this->temp_production_dir . '/nfd-private' );
		foreach ( array( 'uploads', 'themes', 'plugins' ) as $root ) {
			wp_mkdir_p( $this->temp_production_dir . '/wp-content/' . $root );
			wp_mkdir_p( $this->temp_staging_dir . '/wp-content/' . $root );
		}

		file_put_contents( $this->temp_staging_dir . '/wp-content/uploads/a.txt', 'staged-a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->temp_staging_dir . '/wp-content/themes/t.txt', 'staged-theme' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->temp_staging_dir . '/wp-content/plugins/p.txt', 'staged-plugin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		update_option(
			'staging_config',
			array(
				'production_dir' => $this->temp_production_dir,
				'staging_dir'    => $this->temp_staging_dir,
				'production_url' => 'https://test.local',
				'staging_url'    => 'https://test.local/staging',
			)
		);
		$this->staging->getConfig( false );
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
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
	/**
	 * Absolute path to the staging command lock, where lib/.staging and StagingCommandLock both
	 * expect it.
	 *
	 * @return string
	 */
	private function lock_path() {
		return $this->temp_production_dir . '/nfd-private/nfd-staging-command.lock';
	}

	/**
	 * Read the lock's fields, or null when no lock exists.
	 *
	 * @return array|null { timestamp: int, token: string, pid: int }
	 */
	private function read_lock() {
		if ( ! is_file( $this->lock_path() ) ) {
			return null;
		}

		$fields = explode( ':', (string) file_get_contents( $this->lock_path() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return array(
			'timestamp' => isset( $fields[1] ) ? (int) $fields[1] : 0,
			'token'     => isset( $fields[2] ) ? $fields[2] : '',
			'pid'       => isset( $fields[3] ) ? (int) $fields[3] : 0,
		);
	}

	/**
	 * Write the lock exactly as another staging operation would.
	 *
	 * @param string $token Token to write.
	 * @param int    $age   Seconds ago the lock was last refreshed.
	 * @param int    $pid   Pid to record as the holder.
	 *
	 * @return void
	 */
	private function plant_lock( $token, $age = 0, $pid = 0 ) {
		wp_mkdir_p( dirname( $this->lock_path() ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->lock_path(), 'active:' . ( time() - $age ) . ':' . $token . ':' . $pid );
	}

	/**
	 * The lock token a verb was invoked with. It is appended last by runCommand, and every argument
	 * goes through escapeshellarg, so the final single-quoted value is the token.
	 *
	 * @param string $command Full shell command the exec() shadow captured.
	 *
	 * @return string
	 */
	private function token_from_command( $command ) {
		if ( ! preg_match_all( "/'([^']*)'/", (string) $command, $matches ) ) {
			return '';
		}

		return (string) end( $matches[1] );
	}

	/**
	 * Stand in for what lib/.staging's lock_check does with the staging mutex, so the mocked verbs
	 * leave the lock in the state the real ones would: setup takes it under the job's token and holds
	 * it (STAGING_LOCK_KEEP), core reclaims and refreshes the same lock, finalize releases it as the
	 * last step of the job.
	 *
	 * The pid it records is deliberately one that cannot be running. A real verb records its own pid
	 * and has EXITED by the time exec() returns, so the lock a later phase finds carries a DEAD pid.
	 * Recording this process's pid instead would leave a live one, which is the one state lock_check
	 * refuses to reclaim -- so the fixture would be modelling the case real bash never produces.
	 *
	 * @param string $command Full shell command the exec() shadow captured.
	 *
	 * @return void
	 */
	private function simulate_bash_lock( $command ) {
		if ( false !== strpos( $command, 'deploy_content_finalize' ) ) {
			if ( is_file( $this->lock_path() ) ) {
				wp_delete_file( $this->lock_path() );
			}

			return;
		}

		$this->plant_lock( $this->token_from_command( $command ), 0, self::DEPARTED_VERB_PID );
	}

	/**
	 * A successful mocked bash verb that also maintains the staging lock the way the real one does.
	 *
	 * @param string        $message JSON message the verb prints.
	 * @param callable|null $extra   Optional extra assertion callback, given the command string.
	 *
	 * @return array
	 */
	private function verb_response( $message, $extra = null ) {
		return array(
			'output'   => array( '{"status":"success","message":"' . $message . '"}' ),
			'status'   => 0,
			'callback' => function ( $command ) use ( $extra ) {
				$this->simulate_bash_lock( $command );
				if ( is_callable( $extra ) ) {
					$extra( $command );
				}
			},
		);
	}
}
