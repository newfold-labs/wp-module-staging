<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\Module\Staging\Copy\ContentCopyController;
use function NewfoldLabs\WP\ModuleLoader\container;

// The namespaced exec() shadow (and the static response/command queues it reads) live with
// StagingWPUnitTest; load both so the stepped deploy's bash verbs hit the shadow, not a real shell.
require_once __DIR__ . '/StagingWPUnitTest.php';
require_once __DIR__ . '/StagingExecMock.php';
require_once __DIR__ . '/StagingCommandLockProbeStub.php';

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
		$this->tear_down_fixture_tree();

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
	 * Every bash bookend of one job presents the SAME token, and it is the one in the phase state.
	 *
	 * This is what makes three separate bash processes one hold on the staging mutex. If each verb
	 * arrived with its own token, each would take and release its own lock, and the trees would be
	 * unprotected between them -- which is the window a clone or a destroy would walk into.
	 *
	 * @return void
	 */
	public function test_every_verb_of_one_job_presents_the_same_lock_token() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response( 'Files deployed successfully.' ),
		);

		$controller = new ContentCopyController( $this->staging );
		$this->drive_to_completion( $controller );

		$tokens = array_map(
			array( $this, 'token_from_command' ),
			StagingWPUnitTest::$executed_commands
		);

		$this->assertCount( 3, $tokens );
		$this->assertCount( 1, array_unique( $tokens ), 'All three verbs must present one token.' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $tokens[0] );

		// And that token is the job's recorded identity, not something minted per call.
		$state = json_decode(
			(string) file_get_contents( $this->temp_production_dir . '/nfd-private/copy-deploy-files/phase-state.json' ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			true
		);
		$this->assertSame( $tokens[0], $state['lock_token'] );
	}

	/**
	 * Another operation taking the mutex mid-job stops the deploy BEFORE it changes production.
	 *
	 * The order of the two assertions is the point. "The job failed" on its own would also be true of
	 * a deploy that promoted a tree built from a source somebody else was rewriting and then reported
	 * an error. What makes this safe is that finalize never ran.
	 *
	 * @return void
	 */
	public function test_a_lock_taken_by_another_operation_stops_the_job_before_finalize() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response( 'Files deployed successfully.' ),
		);

		$controller = new ContentCopyController( $this->staging );
		$controller->advance();   // setup; leaves the lock under this job's token

		// A clone decided our lock was abandoned, cleared it, and took it under its own token.
		$this->plant_lock( 'someoneelsesclonetoken', 0, self::DEPARTED_VERB_PID );

		$result = $controller->advance();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'lost its lock', $result['message'] );

		// Only setup ran. Nothing touched production's content directories.
		$this->assertCount( 1, StagingWPUnitTest::$executed_commands );
		$this->assertStringContainsString( 'deploy_content_setup', StagingWPUnitTest::$executed_commands[0] );

		// And the other operation's lock is still its own: a losing job must not free it on the way out.
		$this->assertSame( 'someoneelsesclonetoken', $this->read_lock()['token'] );
	}

	/**
	 * Each bookend after the copy re-checks the token, so neither core nor finalize runs under
	 * somebody else's lock.
	 *
	 * The copy phase and the bookends take different routes to the same question: the copy calls
	 * refresh() (which writes, and so has to verify the token to avoid clobbering a new holder), while
	 * core and finalize call holds(), which only reads. A holds() that answered "yes" for any lock at
	 * all would leave the two steps that TOUCH PRODUCTION unprotected while the copy phase looked
	 * fully covered, so each bookend is checked on its own here rather than through the copy.
	 *
	 * @return void
	 */
	public function test_each_bookend_after_the_copy_refuses_to_run_under_a_foreign_lock() {
		foreach ( array( ContentCopyController::PHASE_CORE, ContentCopyController::PHASE_FINALIZE ) as $phase ) {
			$this->tear_down_fixture_tree();
			$this->set_up_fixture_tree();
			StagingWPUnitTest::$executed_commands = array();
			StagingWPUnitTest::$exec_responses    = array(
				$this->verb_response( 'Content deploy setup complete.' ),
				$this->verb_response( 'Content deploy core download complete.' ),
				$this->verb_response( 'Files deployed successfully.' ),
			);

			$controller = new ContentCopyController( $this->staging );

			// Advance until the job is ABOUT to run the bookend under test.
			$guard = 0;
			do {
				$result = $controller->advance();
				++$guard;
			} while ( 'running' === $result['status'] && $result['phase'] !== $phase && $guard < 100 );

			$this->assertSame( $phase, $result['phase'], 'Precondition: the job reached ' . $phase . '.' );
			$verbs_before = count( StagingWPUnitTest::$executed_commands );

			// Another operation takes the mutex in the gap before the bookend runs.
			$this->plant_lock( 'someoneelsesclonetoken', 0, self::DEPARTED_VERB_PID );

			$result = $controller->advance();

			$this->assertSame( 'error', $result['status'], $phase . ' must refuse a foreign lock.' );
			$this->assertStringContainsString( 'lost its lock', $result['message'] );
			$this->assertCount(
				$verbs_before,
				StagingWPUnitTest::$executed_commands,
				'No further verb may run: ' . $phase . ' touches production.'
			);
			$this->assertSame( 'someoneelsesclonetoken', $this->read_lock()['token'] );
		}
	}

	/**
	 * When liveness cannot be determined, each caller takes the default that is safe for its own
	 * consequence, and those defaults are opposite.
	 *
	 * The case needs a host with neither the POSIX extension nor /proc, so it is reached here by
	 * substituting the probe. Getting these two backwards is not symmetrical: freeing the mutex under
	 * a live `rm -rf` lets a clone start rewriting the trees it is deleting, while refusing to free it
	 * costs a wait, and reporting `running` for ever hides a hung job where failing it does not.
	 *
	 * @return void
	 */
	public function test_an_undecidable_holder_counts_as_running_for_release_and_as_gone_for_resume() {
		$this->set_up_fixture_tree();

		$lock  = new StagingCommandLockProbeStub( $this->temp_production_dir );
		$token = Staging::randomToken();
		$this->plant_lock( $token, 0, 4242 );

		$this->assertFalse(
			$lock->releaseIfOurs( $token ),
			'An undecidable holder must be treated as running, so the lock is left alone.'
		);
		$this->assertNotNull( $this->read_lock() );

		$this->assertFalse(
			$lock->heldByOwnLiveProcess( $token ),
			'An undecidable holder must not keep a job polling; it is treated as gone.'
		);
	}

	/**
	 * An orphaned SETUP verb is recognised as our own work in progress, not as a broken deploy.
	 *
	 * Setup is the verb most likely to outlive its request, because its stale-`-new` sweep is one
	 * unlink per file. So it is the one whose orphan the next poll collides with, and lock_check
	 * refuses that poll with the same message as any other contention.
	 *
	 * @return void
	 */
	public function test_an_orphaned_setup_verb_reports_running_rather_than_failing() {
		$this->set_up_fixture_tree();

		// The first setup takes the lock; the second poll is refused by the orphan still holding it.
		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
		);
		$controller = new ContentCopyController( $this->staging );
		$controller->advance();

		$token = $this->read_lock()['token'];

		// Rewind to the setup phase, as a killed request would leave it, and put a LIVE holder on the
		// lock to stand in for the orphan still sweeping.
		$state_path     = $this->temp_production_dir . '/nfd-private/copy-deploy-files/phase-state.json';
		$state          = $this->state_on_disk();
		$state['phase'] = ContentCopyController::PHASE_SETUP;
		file_put_contents( $state_path, wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->plant_lock( $token, 0, getmypid() );

		StagingWPUnitTest::$exec_responses = array(
			array(
				'output' => array( '{"status":"error","message":"Staging action is locked by another command."}' ),
				'status' => 0,
			),
		);

		$result = ( new ContentCopyController( $this->staging ) )->advance();

		$this->assertSame( 'running', $result['status'] );
		$this->assertNotSame( ContentCopyController::PHASE_ERROR, $this->state_on_disk()['phase'] );
		$this->assertSame( $token, $this->read_lock()['token'], "The orphan's lock is left to it." );
	}

	/**
	 * Reporting `running` for an orphan must not touch the phase-state file.
	 *
	 * advance()'s stale window is measured from that file's mtime, so refreshing it on every poll
	 * would mean a verb that hangs for ever is polled for ever: the one bound on this branch would be
	 * removed by the branch itself.
	 *
	 * @return void
	 */
	public function test_reporting_running_for_an_orphan_does_not_reset_the_stale_clock() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			array(
				'output' => array( '{"status":"error","message":"Staging action is locked by another command."}' ),
				'status' => 0,
			),
		);

		$controller = new ContentCopyController( $this->staging );

		$result = array(
			'status' => 'running',
			'phase'  => '',
		);
		$guard  = 0;
		while ( 'running' === $result['status'] && ContentCopyController::PHASE_FINALIZE !== $result['phase'] && $guard < 100 ) {
			$result = $controller->advance();
			++$guard;
		}
		$this->assertSame( ContentCopyController::PHASE_FINALIZE, $result['phase'] );

		$state_path = $this->temp_production_dir . '/nfd-private/copy-deploy-files/phase-state.json';

		// Record the attempt first, as a finalize that was killed would have left it. Without this the
		// poll under test makes a legitimate state write of its own (recording the attempt), the mtime
		// moves for that reason, and the assertion below would be measuring the wrong thing.
		$state                       = $this->state_on_disk();
		$state['finalize_attempted'] = true;
		file_put_contents( $state_path, wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Old enough to be distinguishable, young enough that advance() does not call the job stale.
		$aged = time() - 600;
		touch( $state_path, $aged );
		clearstatcache( true, $state_path );

		$this->plant_lock( $this->read_lock()['token'], 0, getmypid() );

		$result = $controller->advance();
		$this->assertSame( 'running', $result['status'] );

		clearstatcache( true, $state_path );
		$this->assertSame(
			$aged,
			filemtime( $state_path ),
			'The stale clock must keep running while an orphan is waited on, or a hung verb is never given up on.'
		);
	}

	/**
	 * A deploy with nothing to copy still runs its finalize verb.
	 *
	 * "No `-new` trees on disk" is how the controller recognises a swap that already happened, and a
	 * staging site with no content directories produces that state from the very start. Reading it as
	 * a completed swap would report success without ever invoking finalize -- and so without releasing
	 * the staging mutex, leaving every later staging operation refused until the lock aged out. The
	 * recorded attempt is what separates the two; this is the case that proves it is needed.
	 *
	 * @return void
	 */
	public function test_a_deploy_with_nothing_to_copy_still_runs_finalize() {
		$this->set_up_fixture_tree();

		// A staging site whose wp-content holds none of the three copied directories.
		foreach ( array( 'uploads', 'themes', 'plugins' ) as $dir ) {
			$this->recursive_rmdir( $this->temp_staging_dir . '/wp-content/' . $dir );
		}

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response( 'Files deployed successfully.' ),
		);

		$controller = new ContentCopyController( $this->staging );
		$result     = $this->drive_to_completion( $controller );

		$this->assertSame( 'success', $result['status'] );
		$this->assertCount( 3, StagingWPUnitTest::$executed_commands );
		$this->assertStringContainsString( 'deploy_content_finalize', StagingWPUnitTest::$executed_commands[2] );
		// The finalize verb is what releases the mutex, so this is the observable consequence.
		$this->assertNull( $this->read_lock(), 'Skipping finalize would leave the site locked.' );
	}

	/**
	 * A swap the job already completed is reported as the success it was, not as a lost lock.
	 *
	 * deploy_content_finalize swaps, releases the mutex, and only THEN deletes the `-old` asides,
	 * because that delete is the one step whose cost scales with the site's file count. The whole
	 * point of the ordering is that the request blocking on it may die. But the request dying is also
	 * what stops the controller recording PHASE_DONE, so the next poll re-enters finalize and finds
	 * the lock gone -- and used to tell the customer the deploy "was stopped before changing
	 * production", on a deploy that had finished.
	 *
	 * The death is simulated the only way it can be in-process: let the verb run to completion, then
	 * rewrite the state file to what was on disk a moment BEFORE the controller got to save
	 * PHASE_DONE. Production is swapped, no `-new` remains, the lock is released. That is exactly the
	 * world the next poll wakes up in.
	 *
	 * @return void
	 */
	public function test_a_swap_finished_by_an_orphaned_verb_is_reported_as_success() {
		$this->set_up_fixture_tree();

		// Read at the instant the verb is invoked. The flag has to be on DISK by then, because the
		// process that would otherwise save it is the one being killed; a flag set only in memory is
		// still written by the success path afterwards, so asserting it at the end proves nothing.
		$attempt_on_disk_when_verb_ran = null;

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response(
				'Files deployed successfully.',
				function () use ( &$attempt_on_disk_when_verb_ran ) {
					$attempt_on_disk_when_verb_ran = $this->state_on_disk()['finalize_attempted'] ?? null;
					$this->simulate_completed_swap();
				}
			),
		);

		$controller = new ContentCopyController( $this->staging );
		$this->drive_to_completion( $controller );
		$this->assertCount( 3, StagingWPUnitTest::$executed_commands );
		$this->assertTrue(
			$attempt_on_disk_when_verb_ran,
			'The attempt must be recorded on disk before the verb runs, or a killed request leaves no evidence of it.'
		);

		// Rewind the record to the instant before PHASE_DONE was saved, leaving the disk as it is.
		$state_path = $this->temp_production_dir . '/nfd-private/copy-deploy-files/phase-state.json';
		$state       = json_decode( (string) file_get_contents( $state_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$state['phase'] = ContentCopyController::PHASE_FINALIZE;
		file_put_contents( $state_path, wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertNull( $this->read_lock(), 'Precondition: the verb released the mutex.' );

		$result = ( new ContentCopyController( $this->staging ) )->advance();

		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( ContentCopyController::PHASE_DONE, $result['phase'] );
		$this->assertCount( 3, StagingWPUnitTest::$executed_commands, 'The verb must not be run a second time.' );

		// And the legacy status path, which is what the UI actually reads, agrees.
		$this->assertSame( 'success', $this->staging->getDeployStatus( 'files' )['status'] );
	}

	/**
	 * A finalize refused because an earlier verb of the SAME job is still running reports `running`.
	 *
	 * lock_check will not reclaim a lock whose recorded process is alive, and it reports that with the
	 * same customer-facing message as every other refusal. When that process is our own orphaned
	 * finalize, still swapping, the job is progressing and the poll driver has to be told to come
	 * back. Treating the refusal as terminal failed a deploy that was about to succeed.
	 *
	 * @return void
	 */
	public function test_a_refusal_by_this_jobs_own_running_verb_reports_running() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			// What lock_check prints for lock_check:held_by_own_live_step.
			array(
				'output' => array( '{"status":"error","message":"Staging action is locked by another command."}' ),
				'status' => 0,
			),
		);

		$controller = new ContentCopyController( $this->staging );

		$result = array(
			'status' => 'running',
			'phase'  => '',
		);
		$guard  = 0;
		while ( 'running' === $result['status'] && ContentCopyController::PHASE_FINALIZE !== $result['phase'] && $guard < 100 ) {
			$result = $controller->advance();
			++$guard;
		}
		$this->assertSame( ContentCopyController::PHASE_FINALIZE, $result['phase'] );

		// The orphan: our own token, and a pid that is certainly alive (this process).
		$token = $this->read_lock()['token'];
		$this->plant_lock( $token, 0, getmypid() );

		$result = $controller->advance();

		$this->assertSame( 'running', $result['status'], 'A job whose own verb is still working is not a failure.' );
		$this->assertSame( ContentCopyController::PHASE_FINALIZE, $result['phase'] );
		// The job is still resumable: nothing recorded it as failed, and the phase state was left
		// alone so advance()'s stale window keeps measuring from the last real progress.
		$this->assertNotSame( ContentCopyController::PHASE_ERROR, $this->state_on_disk()['phase'] );

		// With the same refusal but a DEAD holder, the job does fail: nothing is coming back to finish it.
		StagingWPUnitTest::$exec_responses = array(
			array(
				'output' => array( '{"status":"error","message":"Staging action is locked by another command."}' ),
				'status' => 0,
			),
		);
		$this->plant_lock( $token, 0, self::DEPARTED_VERB_PID );

		$result = $controller->advance();
		$this->assertSame( 'error', $result['status'] );
	}

	/**
	 * A PHP-side failure hands the mutex back instead of leaving the site locked for the TTL.
	 *
	 * Every bash failure path releases through error(), but a failure on the PHP side had no release
	 * at all, so the lock the setup verb took stayed until it aged out and every staging operation on
	 * the site was refused for up to half an hour over a deploy that was already over.
	 *
	 * @return void
	 */
	public function test_a_failed_job_releases_a_lock_it_can_prove_is_its_own() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			array(
				'output' => array( '{"status":"error","message":"Preparing the WordPress core failed."}' ),
				'status' => 0,
			),
		);

		$controller = new ContentCopyController( $this->staging );

		$result = array(
			'status' => 'running',
			'phase'  => '',
		);
		$guard  = 0;
		while ( 'running' === $result['status'] && ContentCopyController::PHASE_CORE !== $result['phase'] && $guard < 100 ) {
			$result = $controller->advance();
			++$guard;
		}
		$this->assertSame( ContentCopyController::PHASE_CORE, $result['phase'] );

		// Pid 0 is what a PHP-side refresh leaves, and the state in which no verb can be in flight.
		$token = $this->read_lock()['token'];
		$this->plant_lock( $token, 0, 0 );

		$result = $controller->advance();

		$this->assertSame( 'error', $result['status'] );
		$this->assertNull( $this->read_lock(), 'A failed job must not leave the mutex held.' );
	}

	/**
	 * A failing job does NOT free a lock whose holder is still running.
	 *
	 * The orphan that matters is a setup verb whose request was killed while its stale-`-new` sweep
	 * carried on: a live `rm -rf` under this job's own token. Freeing the mutex there would let a
	 * clone start rewriting the very trees that sweep is deleting.
	 *
	 * Its own test rather than a second act in the one above, because once a job is in PHASE_ERROR
	 * advance() returns the stored envelope and never reaches fail() again -- so asserting this on a
	 * second advance() of a failed job exercised nothing at all.
	 *
	 * @return void
	 */
	public function test_a_failed_job_leaves_a_lock_whose_holder_is_still_running() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			array(
				'output' => array( '{"status":"error","message":"Preparing the WordPress core failed."}' ),
				'status' => 0,
			),
		);

		$controller = new ContentCopyController( $this->staging );

		$result = array(
			'status' => 'running',
			'phase'  => '',
		);
		$guard  = 0;
		while ( 'running' === $result['status'] && ContentCopyController::PHASE_CORE !== $result['phase'] && $guard < 100 ) {
			$result = $controller->advance();
			++$guard;
		}
		$this->assertSame( ContentCopyController::PHASE_CORE, $result['phase'] );

		// A live process, under our own token: this process stands in for the orphaned verb.
		$token = $this->read_lock()['token'];
		$this->plant_lock( $token, 0, getmypid() );

		$result = $controller->advance();

		// The refusal is read as "still working", so the job is not failed and the lock is not freed.
		$this->assertSame( 'running', $result['status'] );
		$this->assertNotNull( $this->read_lock(), 'A live holder keeps the mutex.' );
		$this->assertSame( $token, $this->read_lock()['token'] );
	}

	/**
	 * Restarting a job releases its lock before discarding the token that identifies it.
	 *
	 * The state file holds the only record of the token, so deleting it first stranded the lock
	 * permanently: nothing could name it afterwards, and the site waited out the full TTL. The UI
	 * resends `restart` after a gateway timeout, and a reload or a second tab does the same, so this
	 * was reachable from an ordinary retry.
	 *
	 * @return void
	 */
	public function test_restarting_a_job_releases_its_lock_before_forgetting_the_token() {
		$this->set_up_fixture_tree();

		// Read at the instant the restarted job's setup verb is invoked, which is the only moment the
		// question is observable: by the time the verb returns it has planted a lock of its own either
		// way. A real lock_check would be REFUSED here by a lock it could not release.
		$lock_when_setup_ran = 'unset';

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response(
				'Content deploy setup complete.',
				null,
				function () use ( &$lock_when_setup_ran ) {
					$lock_when_setup_ran = $this->read_lock();
				}
			),
		);

		$controller = new ContentCopyController( $this->staging );
		$controller->advance();

		// The pid a real setup verb leaves: its own, by then departed. Planting 0 here instead would
		// have been a fixture that cannot occur -- only a PHP copy burst writes 0, and no burst has
		// run yet -- and it hid the fact that the release refused any recorded pid at all, dead or
		// alive, which is exactly the retry path this test is named for.
		$token = $this->read_lock()['token'];
		$this->plant_lock( $token, 0, self::DEPARTED_VERB_PID );

		$controller->advance( true );

		$this->assertNull(
			$lock_when_setup_ran,
			'The restart must release the old lock while it still knows the token; afterwards nothing can name it.'
		);

		$lock = $this->read_lock();
		$this->assertNotNull( $lock, 'The restart runs setup, which takes a lock of its own.' );
		$this->assertNotSame( $token, $lock['token'], 'The old lock must have been released, not inherited.' );
	}

	/**
	 * A lock that has simply VANISHED also stops the job, rather than being re-acquired.
	 *
	 * lib/.staging's lock_check cannot answer this one: presented with no lock file it just takes one,
	 * reporting success. But between two copy bursts there is no process of ours alive, so an absent
	 * lock means another operation held the mutex, finished, and released it -- and whatever it did to
	 * the trees in between, our enumeration predates. The PHP side is what refuses.
	 *
	 * @return void
	 */
	public function test_a_vanished_lock_stops_the_job_rather_than_being_retaken() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response( 'Files deployed successfully.' ),
		);

		$controller = new ContentCopyController( $this->staging );
		$controller->advance();

		wp_delete_file( $this->lock_path() );

		$result = $controller->advance();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'lost its lock', $result['message'] );
		$this->assertCount( 1, StagingWPUnitTest::$executed_commands, 'No bookend may run without the mutex.' );
	}

	/**
	 * The copy phase pushes the lock's timestamp forward on every burst.
	 *
	 * Nothing of this job's runs between bursts, so without the refresh the lock bash took at setup
	 * would age past its TTL mid-copy and lib/.staging would hand the mutex to the next operation that
	 * asked -- which would then rewrite the very trees this job is enumerating into.
	 *
	 * @return void
	 */
	public function test_the_copy_phase_keeps_the_lock_alive_between_bursts() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
			$this->verb_response( 'Content deploy core download complete.' ),
			$this->verb_response( 'Files deployed successfully.' ),
		);

		$controller = new ContentCopyController( $this->staging );
		$controller->advance();   // setup

		// Age the job's own lock as a long copy would, keeping its token.
		$token = $this->read_lock()['token'];
		$this->plant_lock( $token, 900, self::DEPARTED_VERB_PID );
		$this->assertSame( time() - 900, $this->read_lock()['timestamp'], 'Precondition: the lock is stale.' );

		$controller->advance();   // one copy burst

		$lock = $this->read_lock();
		$this->assertSame( $token, $lock['token'], 'The refresh must keep the job token, not replace it.' );
		$this->assertGreaterThan( time() - 10, $lock['timestamp'], 'The copy burst must refresh the lock.' );
		// Pid 0 marks a PHP-side refresh, which is what lets a failed job release its own lock: a bash
		// verb always records its own pid, so a non-zero pid means a verb may still be in flight.
		$this->assertSame( 0, $lock['pid'] );
	}

	/**
	 * A phase-state file whose token is not a string fails closed instead of raising a notice.
	 *
	 * phase-state.json is read back with json_decode, so a truncated or hand-edited file can put an
	 * array here. Casting one to string raises "Array to string conversion", and the notice prints
	 * into the REST response body, where it breaks the JSON the poll loop parses.
	 *
	 * @return void
	 */
	public function test_an_unusable_token_in_the_phase_state_fails_closed() {
		$this->set_up_fixture_tree();

		StagingWPUnitTest::$exec_responses = array(
			$this->verb_response( 'Content deploy setup complete.' ),
		);

		$controller = new ContentCopyController( $this->staging );
		$controller->advance();   // setup, so a job and a lock exist

		$state_path            = $this->temp_production_dir . '/nfd-private/copy-deploy-files/phase-state.json';
		$state                 = json_decode( (string) file_get_contents( $state_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$state['lock_token']   = array( 'not', 'a', 'token' );
		file_put_contents( $state_path, wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $controller->advance();

		$this->assertSame( 'error', $result['status'] );
		$this->assertCount( 1, StagingWPUnitTest::$executed_commands );
	}

	/**
	 * A malformed token is refused before the verb is ever invoked.
	 *
	 * A colon would forge extra fields in the lock value, and anything non-alphanumeric has no
	 * business being interpolated into a shell argument. The script checks this too; checking here as
	 * well is what turns an opaque mid-deploy failure into a named error.
	 *
	 * @return void
	 */
	public function test_run_content_deploy_verb_refuses_a_malformed_token() {
		$this->set_up_fixture_tree();

		foreach ( array( '', 'has:colons', 'rm -rf /', str_repeat( 'a', 65 ) ) as $bad ) {
			$result = $this->staging->runContentDeployVerb( 'deploy_content_setup', $bad );

			$this->assertWPError( $result, 'Token [' . $bad . '] should be refused.' );
			$this->assertSame( 'invalid_lock_token', $result->get_error_code() );
		}

		$this->assertCount( 0, StagingWPUnitTest::$executed_commands, 'No verb may run under a bad token.' );

		// An unknown verb is refused on its own terms, so the two checks cannot mask each other.
		$result = $this->staging->runContentDeployVerb( 'deploy_db', Staging::randomToken() );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_command', $result->get_error_code() );
	}

	/**
	 * StagingCommandLock frees a lock only when it is provably nobody else's business.
	 *
	 * This is the one place PHP deletes the mutex by path, so each condition is asserted on its own:
	 * our token, pid 0 (no bash verb has run since the last refresh, so none can be in flight), and
	 * comfortably inside the TTL (nobody is entitled to steal it, so it cannot become somebody else's
	 * between the check and the unlink).
	 *
	 * @return void
	 */
	public function test_release_if_ours_refuses_every_lock_it_cannot_prove_is_free_to_delete() {
		$this->set_up_fixture_tree();

		$lock  = new StagingCommandLock( $this->temp_production_dir );
		$token = Staging::randomToken();

		$this->plant_lock( 'someoneelsestoken', 0, 0 );
		$this->assertFalse( $lock->releaseIfOurs( $token ), "Another operation's lock is not ours to free." );
		$this->assertNotNull( $this->read_lock() );

		// A LIVE holder, under our own token: an orphaned verb whose `rm -rf` may still be running.
		$this->plant_lock( $token, 0, getmypid() );
		$this->assertFalse( $lock->releaseIfOurs( $token ), 'A running holder means a verb is in flight.' );
		$this->assertNotNull( $this->read_lock() );

		$this->plant_lock( $token, 1795, 0 );
		$this->assertFalse( $lock->releaseIfOurs( $token ), 'A lock near the TTL may be stolen concurrently.' );
		$this->assertNotNull( $this->read_lock() );

		$this->plant_lock( $token, 0, 0 );
		$this->assertTrue( $lock->releaseIfOurs( $token ), 'Our own fresh PHP-refreshed lock is ours to free.' );
		$this->assertNull( $this->read_lock() );

		// And a DEPARTED verb's pid is released too. Insisting on pid 0 here was the bug: a setup verb
		// that outlived its request leaves its own pid, so the retry could not free the lock and the
		// site refused every staging operation for the full TTL.
		$this->plant_lock( $token, 0, self::DEPARTED_VERB_PID );
		$this->assertTrue( $lock->releaseIfOurs( $token ), "A departed verb's lock is ours to free." );
		$this->assertNull( $this->read_lock() );
	}

	/**
	 * Drive the poll loop to a terminal state, exactly as the REST/cron driver does.
	 *
	 * @param ContentCopyController $controller Controller under test.
	 *
	 * @return array The terminal envelope.
	 */
	private function drive_to_completion( ContentCopyController $controller ) {
		$result = array( 'status' => 'running' );
		$guard  = 0;
		do {
			$result = $controller->advance();
			++$guard;
		} while ( 'running' === $result['status'] && $guard < 100 );

		$this->assertLessThan( 100, $guard, 'The job should finish well within the poll guard.' );

		return $result;
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
	 * Remove the temporary trees. Separate from tearDown so a test that needs a clean tree per case
	 * (see test_each_bookend_after_the_copy_refuses_to_run_under_a_foreign_lock) can reuse it; the
	 * staging dir lives inside the production dir, so the inner one is removed first.
	 *
	 * @return void
	 */
	private function tear_down_fixture_tree() {
		foreach ( array( $this->temp_staging_dir, $this->temp_production_dir ) as $dir ) {
			if ( null !== $dir ) {
				$this->recursive_rmdir( $dir );
			}
		}
		$this->temp_production_dir = null;
		$this->temp_staging_dir    = null;
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
	 * The job's phase state as it currently is ON DISK, which is what survives a killed request.
	 *
	 * @return array
	 */
	private function state_on_disk() {
		$path = $this->temp_production_dir . '/nfd-private/copy-deploy-files/phase-state.json';
		if ( ! is_file( $path ) ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Do to the tree what a real deploy_content_finalize does: promote each `-new` over production,
	 * and leave no `-new` behind. The mocked verbs otherwise only touch the lock, so without this the
	 * `-new` trees would still be on disk and nothing could tell a completed swap from a pending one.
	 *
	 * @return void
	 */
	public function simulate_completed_swap() {
		foreach ( array( 'uploads', 'themes', 'plugins' ) as $dir ) {
			$new  = $this->temp_production_dir . '/wp-content/' . $dir . '-new';
			$live = $this->temp_production_dir . '/wp-content/' . $dir;
			if ( ! is_dir( $new ) ) {
				continue;
			}
			$this->recursive_rmdir( $live );
			rename( $new, $live ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
		}
	}

	/**
	 * A successful mocked bash verb that also maintains the staging lock the way the real one does.
	 *
	 * @param string        $message JSON message the verb prints.
	 * @param callable|null $extra   Optional assertion callback, run AFTER the lock is maintained and
	 *                               given the command string. Use for anything about the verb's
	 *                               effects.
	 * @param callable|null $before  Optional assertion callback, run BEFORE the lock is maintained.
	 *                               Use for anything about the state the verb was HANDED, which the
	 *                               `$extra` hook can no longer see: by then this fixture has already
	 *                               planted the lock a real lock_check would have taken.
	 *
	 * @return array
	 */
	private function verb_response( $message, $extra = null, $before = null ) {
		return array(
			'output'   => array( '{"status":"success","message":"' . $message . '"}' ),
			'status'   => 0,
			'callback' => function ( $command ) use ( $extra, $before ) {
				if ( is_callable( $before ) ) {
					$before( $command );
				}
				$this->simulate_bash_lock( $command );
				if ( is_callable( $extra ) ) {
					$extra( $command );
				}
			},
		);
	}
}
