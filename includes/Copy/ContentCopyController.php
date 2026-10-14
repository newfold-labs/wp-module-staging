<?php
/**
 * Orchestrates the stepped, chunked content-deploy (staging -> production) over many short requests.
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

namespace NewfoldLabs\WP\Module\Staging\Copy;

use NewfoldLabs\WP\Module\Staging\Staging;
use NewfoldLabs\WP\Module\Staging\StagingCommandLock;

/**
 * Drives one deploy-files content copy through four phases -- setup -> copy -> finalize -> done -- doing
 * at most one bounded unit of work per advance() call, so a poll loop (the REST advance route or the
 * WP-cron fallback) can finish an arbitrarily large deploy without any single request running long.
 *
 * The two bash bookends run through Staging's existing command path (token handshake, env, escaping):
 *   - setup    -> `deploy_content_setup`    (WordPress core + php-ai-client; sweeps stale -new/-old)
 *   - finalize -> `deploy_content_finalize` (atomic promote of the finished -new tree over production)
 *
 * Between them, CopyEngine builds the `-new` hardlink-delta tree in bursts. Phase and progress are
 * persisted under nfd-private so a crashed/closed request resumes instead of restarting, and every
 * transition is mirrored into Staging's deploy-result file so the legacy `/staging/deploy` GET status
 * poller and a recorded failure both keep working.
 */
class ContentCopyController {

	/**
	 * Running the bash `deploy_content_setup` bookend (core download, php-ai-client, stale sweep).
	 */
	const PHASE_SETUP = 'setup';

	/**
	 * Driving CopyEngine to build the `-new` delta tree in bursts.
	 */
	const PHASE_COPY = 'copy';

	/**
	 * Running the bash `deploy_content_core` bookend (WordPress core download + php-ai-client), after
	 * the copy finishes and immediately before the swap. Its own phase so finalize stays an instant,
	 * cannot-time-out rename -- see deploy_content_core in lib/.staging.
	 */
	const PHASE_CORE = 'core';

	/**
	 * Running the bash `deploy_content_finalize` bookend (atomic promote).
	 */
	const PHASE_FINALIZE = 'finalize';

	/**
	 * Advance() calls without a successful advance for this many seconds mark a job abandoned, so a
	 * crashed/closed deploy stops blocking later staging operations forever. Generous on purpose: an
	 * active deploy rewrites the phase-state file every burst, so only a truly stalled job trips it.
	 */
	const STALE_AFTER_SECONDS = 3600;

	/**
	 * Deploy finished successfully.
	 */
	const PHASE_DONE = 'done';

	/**
	 * Deploy failed; the result file records why.
	 */
	const PHASE_ERROR = 'error';

	/**
	 * The deploy command name this copy reports as, so it shares the legacy result/log plumbing.
	 */
	const DEPLOY_COMMAND = 'deploy_files';

	/**
	 * Staging instance used to resolve paths, run bash verbs, and write the result file.
	 *
	 * @var Staging
	 */
	private $staging;

	/**
	 * Callable that runs a bash verb and returns its array|WP_Error response.
	 *
	 * @var callable
	 */
	private $verb_runner;

	/**
	 * Lazily constructed copy engine.
	 *
	 * @var CopyEngine|null
	 */
	private $engine;

	/**
	 * Lazily constructed staging-lock accessor.
	 *
	 * @var StagingCommandLock|null
	 */
	private $lock;

	/**
	 * Construct a controller bound to a Staging instance.
	 *
	 * @param Staging       $staging     Staging instance (resolves dirs, runs verbs, writes result).
	 * @param callable|null $verb_runner Optional override for running a bash verb; defaults to
	 *                                   Staging::runContentDeployVerb (used by tests to inject).
	 */
	public function __construct( Staging $staging, $verb_runner = null ) {
		$this->staging     = $staging;
		$this->verb_runner = is_callable( $verb_runner )
			? $verb_runner
			: array( $staging, 'runContentDeployVerb' );
	}

	/**
	 * Absolute job directory for the engine's manifest/cursor/lock and this controller's phase file.
	 *
	 * @return string
	 */
	private function jobDir() {
		return trailingslashit( $this->staging->getProductionDir() ) . 'nfd-private/copy-deploy-files';
	}

	/**
	 * Absolute path to the persisted phase-state file.
	 *
	 * @return string
	 */
	private function statePath() {
		return $this->jobDir() . '/phase-state.json';
	}

	/**
	 * Resolve the CopyEngine config for a deploy-files content copy.
	 *
	 * @return array
	 */
	public function engineConfig() {
		$production = untrailingslashit( $this->staging->getProductionDir() );
		$staging    = untrailingslashit( $this->staging->getStagingDir() );

		return array(
			'source_root'    => $staging . '/wp-content',
			'dest_root'      => $production . '/wp-content',
			'dest_suffix'    => '-new',
			'link_dest_root' => $production . '/wp-content',
			'job_dir'        => $this->jobDir(),
		);
	}

	/**
	 * Lazily build (and reuse) the copy engine.
	 *
	 * @return CopyEngine
	 */
	private function engine() {
		if ( null === $this->engine ) {
			$this->engine = new CopyEngine( $this->engineConfig() );
		}

		return $this->engine;
	}

	/**
	 * Lazily build (and reuse) a handle on the staging command mutex.
	 *
	 * Not the same lock as controllerLockPath(), which only serializes two concurrent advance() calls
	 * of THIS job. This one is the site-wide mutex shared with lib/.staging, and it is what keeps a
	 * clone, create, destroy or legacy deploy from running against the same trees while this job is
	 * mid-copy.
	 *
	 * @return StagingCommandLock
	 */
	private function lock() {
		if ( null === $this->lock ) {
			$this->lock = new StagingCommandLock( $this->staging->getProductionDir() );
		}

		return $this->lock;
	}

	/**
	 * This job's staging-lock token: the identity its three bash bookends present to lib/.staging's
	 * lock_check so all of them, and the PHP copy bursts between them, share one hold on the mutex.
	 *
	 * @param array $state Current state.
	 *
	 * @return string
	 */
	private function jobToken( array $state ) {
		// String-checked rather than cast: phase-state.json is read back with json_decode, so a
		// truncated or hand-edited file can put an array or object here, and casting that would raise
		// an "Array to string conversion" notice -- printed into the REST response body, where it
		// breaks the JSON the poll loop parses. An unusable token reads as no token, which fails
		// closed (every path that needs one refuses without it).
		return isset( $state['lock_token'] ) && is_string( $state['lock_token'] ) ? $state['lock_token'] : '';
	}

	/**
	 * Absolute path to the controller's advisory lock file.
	 *
	 * @return string
	 */
	private function controllerLockPath() {
		return $this->jobDir() . '/controller.lock';
	}

	/**
	 * Do at most one bounded unit of work (one bash verb OR one engine burst) and return progress.
	 *
	 * The whole call runs under a non-blocking advisory lock, so a second caller -- a duplicate REST
	 * poll, the cron fallback, or a second browser tab -- can never run a phase transition concurrently
	 * (e.g. a setup that `rm -rf`s `-new` while another call's copy burst is filling it). A caller that
	 * cannot take the lock just reports current progress.
	 *
	 * @param bool $restart When true, discard any prior job (engine cursor/manifest + phase state) and
	 *                      start fresh. The UI passes this on the first advance of a new deploy so a
	 *                      previous run's terminal 'done'/'error' state does not short-circuit it.
	 *
	 * @return array { status: running|success|error, progress: array, phase: string, message?: string }
	 */
	public function advance( $restart = false ) {
		$dir = $this->jobDir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return array(
				'status'   => 'error',
				'phase'    => self::PHASE_ERROR,
				'progress' => array(),
				'message'  => __( 'Could not create the content copy job directory.', 'wp-module-staging' ),
			);
		}

		$lock = fopen( $this->controllerLockPath(), 'cb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $lock ) {
			return array(
				'status'   => 'error',
				'phase'    => self::PHASE_ERROR,
				'progress' => array(),
				'message'  => __( 'Could not open the content copy lock.', 'wp-module-staging' ),
			);
		}
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock );
			return $this->status(); // Another advance() is in flight; report without mutating.
		}

		try {
			if ( $restart ) {
				$this->resetLocked();
			} elseif ( $this->jobExists() && $this->isStale() ) {
				// A non-restart advance for a job that has not progressed in the stale window: it was
				// abandoned (a tab asleep through a laptop suspend, say). Resuming it would run the copy
				// against a tree another operation (clone/destroy) may have since mutated. Refuse and mark
				// it failed; the client must start a fresh deploy (restart=true), which reseeds cleanly.
				$stale          = $this->loadState( false );
				$stale['phase'] = self::PHASE_ERROR;
				$stale['message'] = __( 'The deploy was abandoned and timed out. Start it again.', 'wp-module-staging' );
				$this->saveState( $stale );
				return $this->envelope( 'error', $stale );
			}

			$state = $this->loadState( true );

			switch ( $state['phase'] ) {
				case self::PHASE_SETUP:
					return $this->runSetup( $state );
				case self::PHASE_COPY:
					return $this->runCopyBurst( $state );
				case self::PHASE_CORE:
					return $this->runCore( $state );
				case self::PHASE_FINALIZE:
					return $this->runFinalize( $state );
				case self::PHASE_ERROR:
					return $this->envelope( 'error', $state );
				case self::PHASE_DONE:
				default:
					return $this->envelope( 'success', $state );
			}
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * Discard any prior job so a new deploy starts clean. Clears the engine's cursor/manifest/lock and
	 * the phase-state file together, so the next setup sweeps stale `-new`/`-old` and the engine reseeds
	 * rather than resuming a previous run's cursor against a swept tree. Call only under the lock.
	 *
	 * @return void
	 */
	private function resetLocked() {
		/*
		 * Release BEFORE the state file goes, because the state file holds the only record of the
		 * token. Deleting it first stranded the lock permanently: no later call could name the token,
		 * so nothing could ever release it and the site waited out the full TTL. That was reachable
		 * from an ordinary retry -- the UI resends `restart` after a gateway timeout, and a reload or
		 * a second tab does the same -- so the common case of "the first poll took too long" cost the
		 * user half an hour of refusals.
		 *
		 * A restart is also exactly when this is safe: the caller is abandoning the old job, so there
		 * is nothing of ours left that the lock protects. releaseIfOurs still declines a lock that
		 * records a live bash pid, which is the one case where something IS still running.
		 */
		if ( is_file( $this->statePath() ) ) {
			$this->lock()->releaseIfOurs( $this->jobToken( $this->loadState( false ) ) );
		}

		$this->engine()->cleanup();
		if ( is_file( $this->statePath() ) ) {
			wp_delete_file( $this->statePath() );
		}
	}

	/**
	 * Report current phase/progress without advancing.
	 *
	 * @return array
	 */
	public function status() {
		if ( ! $this->jobExists() ) {
			return array(
				'status'   => 'idle',
				'phase'    => 'idle',
				'progress' => array(),
			);
		}

		$state = $this->loadState( false );

		return $this->envelope( $this->phaseStatus( $state['phase'] ), $state );
	}

	/**
	 * Whether a job exists and has not yet reached a terminal phase.
	 *
	 * @return bool
	 */
	public function isInProgress() {
		if ( ! $this->jobExists() ) {
			return false;
		}

		$state = $this->loadState( false );

		$active = in_array(
			$state['phase'],
			array( self::PHASE_SETUP, self::PHASE_COPY, self::PHASE_CORE, self::PHASE_FINALIZE ),
			true
		);
		if ( ! $active ) {
			return false;
		}

		// An abandoned job (crashed/closed before reaching a terminal phase) would otherwise block every
		// later staging operation forever, since nothing ever flips its phase to done/error. Treat a job
		// that has not advanced within the stale window as dead, not in flight.
		return ! $this->isStale();
	}

	/**
	 * Whether a job's phase-state file has not been touched within the stale window. advance() rewrites
	 * it on every burst (writeRunning/saveState), so an older mtime means no progress for that long.
	 * Only meaningful when a job exists.
	 *
	 * @return bool
	 */
	private function isStale() {
		$mtime = @filemtime( $this->statePath() );
		return false !== $mtime && $mtime < ( time() - self::STALE_AFTER_SECONDS );
	}

	/**
	 * Phase 1: run the bash setup bookend, then move to the copy phase.
	 *
	 * @param array $state Current state.
	 *
	 * @return array
	 */
	private function runSetup( $state ) {
		// Start the engine from a clean slate every time setup runs. A support engineer who deletes the
		// phase-state file (but not the engine's cursor) would otherwise fall back to the setup phase
		// while a STALE cursor still points mid-tree; the copy phase would then resume from it and
		// promote only the tail of the tree over production. cleanup() removes the cursor/manifest so the
		// engine reseeds, matching the stale -new/-old sweep the bash setup bookend does on the files.
		$this->engine()->cleanup();

		$this->writeRunning( $state );

		$response = $this->callVerb( 'deploy_content_setup', $state );
		if ( $this->verbFailed( $response ) ) {
			return $this->fail( $state, $this->verbMessage( $response, __( 'Content deploy setup failed.', 'wp-module-staging' ) ) );
		}

		$state['phase'] = self::PHASE_COPY;
		$this->saveState( $state );
		$this->writeRunning( $state );

		return $this->envelope( 'running', $state );
	}

	/**
	 * Confirm this job still holds the staging mutex, and build the failure envelope if it does not.
	 *
	 * Every phase that is about to act on the trees has to ask, not just the copy. lib/.staging's
	 * lock_check cannot answer it for us: presented with no lock file at all it simply acquires one,
	 * so a verb could not tell "we have held this all along" from "we lost it, and whatever took it
	 * has since finished and released". The difference matters. The failure that motivates this: the
	 * last copy burst moves the job to the core phase, the poll driver then stalls for longer than
	 * the lock TTL but less than the job's stale window (a suspended laptop, a starved cron), a clone
	 * takes the lock, rewrites staging from production, finishes and releases -- and the core and
	 * finalize phases would then promote a `-new` tree built from the pre-clone staging content over
	 * production, because the clone only ever touched staging and left the `-new` tree standing.
	 *
	 * Either operation answers it, because both only succeed on a lock that exists and still carries
	 * this job's token -- false while a thief holds it and false after a thief has released it.
	 *
	 * WHICH ONE MATTERS. $refresh also WRITES, and the value it writes records pid 0, meaning "no
	 * single process owns this right now". Only the copy phase may say that: it is the one phase with
	 * nothing of the job's running between calls, and the refresh is also what keeps the lock from
	 * ageing out across a long copy. The phases that invoke a bash verb must NOT write, because the
	 * pid already in the lock may belong to a verb of this same job that is still running -- a
	 * finalize whose PHP request was killed by the FPM timeout while the bash process carried on
	 * swapping directories. That pid is the only thing standing between the next poll and a SECOND
	 * concurrent finalize: lock_check refuses to reclaim a lock whose recorded process is alive, and
	 * zeroing it here would tell it there is nobody to wait for, mid-swap. So core and finalize check
	 * read-only and leave the pid alone; the verb's own lock_check refreshes the timestamp a moment
	 * later anyway.
	 *
	 * @param array $state   Current state.
	 * @param array $failure Set to the failure envelope when this returns false.
	 * @param bool  $refresh Whether to also push the lock's timestamp forward (copy phase only).
	 *
	 * @return bool
	 */
	private function holdsLockOrFail( $state, &$failure, $refresh ) {
		$token = $this->jobToken( $state );
		$held  = $refresh ? $this->lock()->refresh( $token ) : $this->lock()->holds( $token );

		if ( $held ) {
			return true;
		}

		$failure = $this->fail(
			$state,
			__( 'The deploy lost its lock to another staging operation and was stopped before changing production. Start the deploy again.', 'wp-module-staging' )
		);

		return false;
	}

	/**
	 * Phase 2: run one CopyEngine burst. Still reports running when the copy completes, because the
	 * core-download and finalize bookends have not run yet, so the poll driver keeps calling advance().
	 *
	 * @param array $state Current state.
	 *
	 * @return array
	 */
	private function runCopyBurst( $state ) {
		// Every burst re-asserts the mutex, which is also what keeps it alive: nothing of ours runs
		// between bursts, so without a refresh here the lock setup took would age past its TTL
		// mid-copy and lib/.staging would hand the mutex to the next operation that asked -- which
		// would then mutate the very trees this job is enumerating and writing into.
		if ( ! $this->holdsLockOrFail( $state, $failure, true ) ) {
			return $failure;
		}

		$result            = $this->engine()->advance();
		$state['progress'] = isset( $result['progress'] ) ? $result['progress'] : array();

		if ( 'error' === $result['status'] ) {
			return $this->fail( $state, isset( $result['message'] ) ? $result['message'] : __( 'Copying content files failed.', 'wp-module-staging' ) );
		}

		if ( 'success' === $result['status'] ) {
			$state['phase'] = self::PHASE_CORE;
		}

		$this->saveState( $state );
		$this->writeRunning( $state );

		return $this->envelope( 'running', $state );
	}

	/**
	 * Phase 2.5: run the bash core-download bookend (WordPress core + php-ai-client), then move to
	 * finalize. Kept distinct from finalize so finalize is only the instant swap -- see
	 * deploy_content_core in lib/.staging. Still reports running so the poll driver continues.
	 *
	 * @param array $state Current state.
	 *
	 * @return array
	 */
	private function runCore( $state ) {
		// Read-only: this phase invokes a bash verb, so it must not overwrite the pid in the
		// lock. See holdsLockOrFail.
		if ( ! $this->holdsLockOrFail( $state, $failure, false ) ) {
			return $failure;
		}

		$response = $this->callVerb( 'deploy_content_core', $state );
		if ( $this->verbFailed( $response ) ) {
			return $this->fail( $state, $this->verbMessage( $response, __( 'Preparing the WordPress core for deploy failed.', 'wp-module-staging' ) ) );
		}

		$state['phase'] = self::PHASE_FINALIZE;
		$this->saveState( $state );
		$this->writeRunning( $state );

		return $this->envelope( 'running', $state );
	}

	/**
	 * Phase 3: run the bash finalize bookend, clean up the engine, mark done.
	 *
	 * @param array $state Current state.
	 *
	 * @return array
	 */
	private function runFinalize( $state ) {
		/*
		 * A finalize this job already ran may have SUCCEEDED without this phase ever being told.
		 *
		 * deploy_content_finalize swaps, releases the mutex, and only THEN deletes the `-old` asides,
		 * which on a normal deploy is the only step whose cost scales with the site's file count (the
		 * sweep in deploy_content_setup is the other, and only after an abandoned attempt). The point of that
		 * ordering is that the request blocking on it is free to die: by then production is live and
		 * correct. But the request dying is also what stops saveState(PHASE_DONE) below from running,
		 * so the job is still recorded as `finalize`, and the next poll re-enters here.
		 *
		 * Without this check, that poll found the lock gone (the verb released it) and reported "the
		 * deploy lost its lock and was stopped BEFORE changing production" -- on a deploy that had
		 * completed. That is the exact symptom the release-then-delete ordering was built to remove,
		 * reintroduced on the PHP side.
		 */
		if ( $this->swapAlreadyCompleted( $state ) ) {
			return $this->finishLocked( $state );
		}

		// Read-only: this phase invokes a bash verb, so it must not overwrite the pid in the
		// lock. See holdsLockOrFail.
		if ( ! $this->holdsLockOrFail( $state, $failure, false ) ) {
			return $failure;
		}

		/*
		 * Recorded BEFORE the verb runs, and durably, because the process that learns the answer may
		 * not survive to report it. It is what lets the check above tell "a finalize of ours ran and
		 * the trees are gone because it promoted them" from "the copy phase produced nothing to
		 * promote in the first place". Both look identical on disk.
		 */
		if ( empty( $state['finalize_attempted'] ) ) {
			$state['finalize_attempted'] = true;
			$this->saveState( $state );
		}

		$response = $this->callVerb( 'deploy_content_finalize', $state );
		if ( $this->verbFailed( $response ) ) {
			// An earlier finalize of this same job may have got further than the one we just tried.
			if ( $this->swapAlreadyCompleted( $state ) ) {
				return $this->finishLocked( $state );
			}

			/*
			 * lock_check refuses to reclaim a lock whose recorded process is still alive, and reports
			 * it with the same customer-facing message as every other refusal. Here that process is
			 * OUR OWN orphaned verb, still swapping: the job is progressing, not broken, so the poll
			 * driver has to be told to come back. advance()'s stale window bounds this, so a verb that
			 * hangs for ever still ends as a failure rather than polling for ever.
			 */
			if ( $this->lock()->heldByOwnLiveProcess( $this->jobToken( $state ) ) ) {
				$this->writeRunning( $state );

				return $this->envelope( 'running', $state );
			}

			return $this->fail( $state, $this->verbMessage( $response, __( 'Finalizing the content deploy failed.', 'wp-module-staging' ) ) );
		}

		return $this->finishLocked( $state );
	}

	/**
	 * Whether the swap this job was running has already finished, leaving nothing to promote.
	 *
	 * Only ever true once a finalize has actually been invoked (see `finalize_attempted`): a job whose
	 * staging source had no content directories at all would otherwise have no `-new` trees from the
	 * start and would be reported as a completed swap without the verb ever running, which would also
	 * skip releasing the mutex.
	 *
	 * "Nothing to promote" is the same test bash itself uses to decide a directory is already done
	 * (see finalize_new_content_dirs), and the states it could be confused with do not arise: a verb
	 * killed mid-swap leaves the later directories' `-new` in place AND leaves its lock behind, and a
	 * verb that failed and rolled back restores `-new` from each directory it had promoted.
	 *
	 * @param array $state Current state.
	 *
	 * @return bool
	 */
	private function swapAlreadyCompleted( array $state ) {
		if ( empty( $state['finalize_attempted'] ) ) {
			return false;
		}

		$config = $this->engineConfig();
		foreach ( CopyEngine::DEFAULT_ROOTS as $dir ) {
			if ( is_dir( $config['dest_root'] . '/' . $dir . $config['dest_suffix'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Record a completed deploy. Shared by the normal path and by the resumption check above, so a
	 * deploy finished by an orphaned verb is reported exactly like one finished in place.
	 *
	 * @param array $state Current state.
	 *
	 * @return array
	 */
	private function finishLocked( array $state ) {
		$this->engine()->cleanup();

		/*
		 * Normally the verb has already released the mutex by this point. It has not if the swap
		 * completed but the verb was killed before its release, so this is a best-effort tidy-up:
		 * releaseIfOurs only acts on a lock that is ours, records no bash pid, and is far enough
		 * inside the TTL that nobody may concurrently steal it.
		 */
		$this->lock()->releaseIfOurs( $this->jobToken( $state ) );

		$state['phase'] = self::PHASE_DONE;
		$this->saveState( $state );
		$this->writeSuccess( $state );

		return $this->envelope( 'success', $state );
	}

	/**
	 * Invoke a bash verb through the configured runner, handing it this job's lock token.
	 *
	 * @param string $verb  Verb name.
	 * @param array  $state Current state, which carries this job's lock token.
	 *
	 * @return array|\WP_Error
	 */
	private function callVerb( $verb, array $state ) {
		return call_user_func( $this->verb_runner, $verb, $this->jobToken( $state ) );
	}

	/**
	 * Whether a verb response indicates failure.
	 *
	 * @param mixed $response Verb response.
	 *
	 * @return bool
	 */
	private function verbFailed( $response ) {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		return is_array( $response ) && isset( $response['status'] ) && 'error' === $response['status'];
	}

	/**
	 * Extract a human message from a failed verb response.
	 *
	 * @param mixed  $response Verb response.
	 * @param string $default  Fallback message.
	 *
	 * @return string
	 */
	private function verbMessage( $response, $default ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		if ( is_array( $response ) && ! empty( $response['message'] ) ) {
			return (string) $response['message'];
		}

		return $default;
	}

	/**
	 * Record a failure: mark the error phase and persist it to the result file.
	 *
	 * @param array  $state   Current state.
	 * @param string $message Failure message.
	 *
	 * @return array
	 */
	private function fail( $state, $message ) {
		$state['phase']   = self::PHASE_ERROR;
		$state['message'] = $message;
		$this->saveState( $state );

		/*
		 * Give the mutex back. Every bash failure path releases through error(), but a failure on the
		 * PHP side -- an unreadable manifest, a full disk, a directory the account cannot enter --
		 * had no release at all, so the lock the setup verb took stayed until its TTL ran out and
		 * every staging operation on the site was refused for up to half an hour over a deploy that
		 * was already over.
		 *
		 * releaseIfOurs is the right tool rather than an unlink because it refuses anything it cannot
		 * prove is free to delete: a lock carrying another token, one recording a bash pid (a verb may
		 * still be in flight under it, including an orphaned setup whose `rm -rf` is still running),
		 * or one close enough to the TTL that lib/.staging may concurrently decide it is abandoned.
		 */
		$this->lock()->releaseIfOurs( $this->jobToken( $state ) );

		$this->staging->recordDeployResult(
			array(
				'status'     => 'error',
				'command'    => self::DEPLOY_COMMAND,
				'started_at' => $state['started_at'],
				'message'    => $message,
			)
		);

		return $this->envelope( 'error', $state, $message );
	}

	/**
	 * Write a running snapshot to the shared deploy-result file.
	 *
	 * @param array $state Current state.
	 *
	 * @return void
	 */
	private function writeRunning( $state ) {
		$this->staging->recordDeployResult(
			array(
				'status'     => 'running',
				'command'    => self::DEPLOY_COMMAND,
				'started_at' => $state['started_at'],
				'message'    => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
				'progress'   => isset( $state['progress'] ) ? $state['progress'] : array(),
			)
		);
	}

	/**
	 * Write the final success result to the shared deploy-result file.
	 *
	 * @param array $state Current state.
	 *
	 * @return void
	 */
	private function writeSuccess( $state ) {
		$this->staging->recordDeployResult(
			array(
				'status'     => 'success',
				'command'    => self::DEPLOY_COMMAND,
				'started_at' => $state['started_at'],
				'message'    => __( 'Files deployed successfully.', 'wp-module-staging' ),
				'progress'   => isset( $state['progress'] ) ? $state['progress'] : array(),
			)
		);
	}

	/**
	 * Build the standard return envelope from the current state.
	 *
	 * @param string $status  running|success|error|idle.
	 * @param array  $state   Current state.
	 * @param string $message Optional message override.
	 *
	 * @return array
	 */
	private function envelope( $status, $state, $message = '' ) {
		$out = array(
			'status'   => $status,
			'phase'    => $state['phase'],
			'progress' => isset( $state['progress'] ) ? $state['progress'] : array(),
		);

		if ( '' !== $message ) {
			$out['message'] = $message;
		} elseif ( ! empty( $state['message'] ) ) {
			$out['message'] = $state['message'];
		}

		return $out;
	}

	/**
	 * Map a phase to its outward status.
	 *
	 * @param string $phase Phase name.
	 *
	 * @return string
	 */
	private function phaseStatus( $phase ) {
		switch ( $phase ) {
			case self::PHASE_DONE:
				return 'success';
			case self::PHASE_ERROR:
				return 'error';
			default:
				return 'running';
		}
	}

	/**
	 * Whether a persisted job-state file exists.
	 *
	 * @return bool
	 */
	private function jobExists() {
		return is_file( $this->statePath() );
	}

	/**
	 * Load the phase state, optionally creating a fresh job file if none exists.
	 *
	 * @param bool $create_if_missing When true, seed and persist a fresh setup-phase state.
	 *
	 * @return array
	 */
	private function loadState( $create_if_missing ) {
		$path = $this->statePath();
		if ( is_file( $path ) ) {
			$raw     = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded = $raw ? json_decode( $raw, true ) : null;
			if ( is_array( $decoded ) ) {
				return array_merge( $this->defaultState(), $decoded );
			}
		}

		$state = $this->defaultState();
		if ( $create_if_missing ) {
			$this->saveState( $state );
		}

		return $state;
	}

	/**
	 * The initial phase state for a fresh job.
	 *
	 * @return array
	 */
	private function defaultState() {
		return array(
			'phase'      => self::PHASE_SETUP,
			'started_at' => time(),
			'progress'   => array(),
			'message'    => '',
			'lock_token' => self::newLockToken(),
		);
	}

	/**
	 * Mint a staging-lock token: 32 hex characters, from a generator that never calls into
	 * WordPress. The token has to be alphanumeric because it travels as a shell argument and as a
	 * field in a colon-delimited lock value; see Staging::randomToken for why wp_generate_password
	 * is the wrong tool for it.
	 *
	 * @return string
	 */
	private static function newLockToken() {
		return Staging::randomToken();
	}

	/**
	 * Persist the phase state atomically (temp file + rename).
	 *
	 * @param array $state State to persist.
	 *
	 * @return void
	 */
	private function saveState( array $state ) {
		$dir = $this->jobDir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$json = wp_json_encode( $state );
		if ( false === $json ) {
			return;
		}

		$tmp = $this->statePath() . '.tmp-' . getmypid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) ) {
			return;
		}
		if ( ! rename( $tmp, $this->statePath() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
			wp_delete_file( $tmp );
		}
	}
}
