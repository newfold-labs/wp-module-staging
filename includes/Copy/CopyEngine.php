<?php
/**
 * Chunked, resumable copy of a WordPress content tree, advanced in short bursts.
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

namespace NewfoldLabs\WP\Module\Staging\Copy;

/**
 * Replaces the single long `rsync` that clone and deploy used to run with an engine that advances one
 * bounded burst per call, checkpoints, and resumes. Each call does at most `budget_seconds` of work,
 * so nothing ever runs long enough to trip the per-process CPU ceiling or load the box, and no
 * long-lived detached worker is needed: a REST "advance" endpoint (driven by the UI poll loop) and a
 * WP-cron fallback call advance() repeatedly until it reports done.
 *
 * One engine serves both flows; they differ only in config:
 *   - clone  (production -> new staging): dest_suffix '',     link_dest_root null, no finalize.
 *   - deploy (staging -> production):     dest_suffix '-new', link_dest_root = production wp-content,
 *                                         then the caller runs the bash `finalize_new_content_dirs`
 *                                         atomic swap over the finished `-new` tree.
 *
 * The engine itself is pure PHP; WordPress-specific wiring (REST, cron, options) lives in the caller.
 */
class CopyEngine {

	/**
	 * Default per-burst time budget, in seconds. Well under any FPM request timeout and the 120 CPU-s
	 * per-process cap; file copy is I/O-bound so a burst spends little CPU.
	 */
	const DEFAULT_BUDGET = 10.0;

	/**
	 * Consecutive copy errors on a single file before the job is declared failed.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Resolved job configuration (see the constructor).
	 *
	 * @var array
	 */
	private $config;

	/**
	 * The file manifest for this job.
	 *
	 * @var CopyManifest
	 */
	private $manifest;

	/**
	 * The resumable cursor for this job.
	 *
	 * @var CopyCheckpoint
	 */
	private $checkpoint;

	/**
	 * Absolute path to the advisory lock file.
	 *
	 * @var string
	 */
	private $lock_path;

	/**
	 * The wp-content subtrees a staging copy moves, when the caller does not name its own.
	 *
	 * Must stay in step with CONTENT_DIRS in lib/.staging and with the three directories
	 * finalize_new_content_dirs promotes: a directory copied here but not promoted there would be
	 * built and then silently left behind.
	 */
	const DEFAULT_ROOTS = array( 'uploads', 'themes', 'plugins' );

	/**
	 * Construct an engine for one copy job.
	 *
	 * @param array $config {
	 *     @type string      $source_root    Absolute wp-content to copy FROM.
	 *     @type string      $dest_root      Absolute wp-content to copy TO.
	 *     @type string      $dest_suffix    '' for clone, '-new' for deploy.
	 *     @type string[]    $roots          Content roots to copy, e.g. ['uploads','themes','plugins'].
	 *     @type string|null $link_dest_root Absolute wp-content to hardlink unchanged files from, or null.
	 *     @type string      $job_dir        Absolute dir for manifest/cursor/lock (under nfd-private).
	 *     @type float       $budget_seconds Optional per-burst budget. Defaults to DEFAULT_BUDGET.
	 * }
	 */
	public function __construct( array $config ) {
		$config           = array_merge(
			array(
				'dest_suffix'    => '',
				'roots'          => self::DEFAULT_ROOTS,
				'link_dest_root' => null,
				'budget_seconds' => self::DEFAULT_BUDGET,
			),
			$config
		);
		$this->config     = $config;
		$this->manifest   = new CopyManifest( $config['job_dir'] . '/manifest.list' );
		$this->checkpoint = new CopyCheckpoint( $config['job_dir'] . '/cursor.json' );
		$this->lock_path  = $config['job_dir'] . '/copy.lock';
	}

	/**
	 * Do one bounded burst of work and return progress.
	 *
	 * Safe to call concurrently: a non-blocking flock means only one caller advances at a time; the
	 * others get the current progress and 'running'. Safe to call after a crash: it resumes from the
	 * last saved cursor.
	 *
	 * @return array { status: running|success|error, progress: array, message?: string }
	 */
	public function advance() {
		if ( ! is_dir( $this->config['job_dir'] ) && ! mkdir( $this->config['job_dir'], 0700, true ) && ! is_dir( $this->config['job_dir'] ) ) {
			return $this->result( 'error', __( 'Could not create the copy job directory.', 'wp-module-staging' ) );
		}

		$lock = fopen( $this->lock_path, 'cb' );
		if ( false === $lock ) {
			// Cannot even open the lock file (permission/space). Fail rather than loop "running" forever.
			return $this->result( 'error', __( 'Could not open the copy lock.', 'wp-module-staging' ) );
		}
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock );
			// Another burst is in flight; report where we are without touching anything.
			$this->checkpoint->load();
			return $this->result( 'running' );
		}

		try {
			return $this->runBurst();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	/**
	 * The actual work, under the lock.
	 *
	 * @return array
	 */
	private function runBurst() {
		$cp = $this->checkpoint;

		// A fresh job, OR a cursor that could not be read (missing, corrupt, or truncated by a crash):
		// start clean. We reseed the worklist, SAVE THE CURSOR FIRST, and only then truncate any stale
		// manifest. Ordering matters: if the manifest existed but the cursor did not, proceeding would
		// walk an empty worklist, mark the walk "complete", and copy a partial tree over production.
		if ( ! $cp->load() ) {
			$pending = array();
			foreach ( (array) $this->config['roots'] as $root ) {
				if ( is_dir( $this->config['source_root'] . '/' . $root ) ) {
					$pending[] = $root;
				}
			}
			$cp->set(
				array(
					'job_id'        => uniqid( 'copy_', true ),
					'phase'         => CopyCheckpoint::PHASE_ENUMERATE,
					'pending_dirs'  => $pending,
					'walk_complete' => false,
				)
			);
			if ( ! $cp->save() ) {
				return $this->result( 'error', __( 'Could not write the copy cursor.', 'wp-module-staging' ) );
			}
			if ( false === file_put_contents( $this->manifest->path(), '' ) ) {
				return $this->result( 'error', __( 'Could not create the copy manifest.', 'wp-module-staging' ) );
			}
		}

		$deadline = microtime( true ) + (float) $this->config['budget_seconds'];

		if ( CopyCheckpoint::PHASE_ENUMERATE === $cp->get( 'phase' ) ) {
			$walk = $this->manifest->walk( $this->config['source_root'], $cp, $deadline );
			if ( CopyManifest::WALK_ERROR === $walk ) {
				$cp->save();
				return $this->result( 'error', __( 'Could not enumerate the files to copy.', 'wp-module-staging' ) );
			}
			if ( CopyManifest::WALK_DONE === $walk ) {
				$cp->set(
					array(
						'phase'           => CopyCheckpoint::PHASE_COPY,
						'manifest_offset' => 0,
					)
				);
			}
			// A failed save (full disk / over quota) must fail the job, not report progress that did not
			// persist: on the next burst the cursor would reload stale and could mis-sequence the phases.
			if ( ! $cp->save() ) {
				return $this->result( 'error', __( 'Could not write the copy cursor.', 'wp-module-staging' ) );
			}
			return $this->result( 'running' );
		}

		if ( CopyCheckpoint::PHASE_COPY === $cp->get( 'phase' ) ) {
			// Defend the C1 invariant: never copy from a manifest whose walk did not finish.
			if ( ! $cp->get( 'walk_complete' ) ) {
				return $this->result( 'error', __( 'Copy manifest is incomplete; cannot continue.', 'wp-module-staging' ) );
			}
			$status = $this->runCopyPhase( $cp, $deadline );
			// Persist the copy cursor before reporting; a failed save would otherwise lose the advance and
			// let the next burst re-copy from a stale offset (or, at DONE, fail to record completion).
			if ( ! $cp->save() ) {
				return $this->result( 'error', __( 'Could not write the copy cursor.', 'wp-module-staging' ) );
			}
			return $status;
		}

		// Already done.
		return $this->result( 'success' );
	}

	/**
	 * Copy manifest entries until the deadline, EOF, or a fatal error.
	 *
	 * @param CopyCheckpoint $cp       Cursor.
	 * @param float          $deadline microtime(true) to stop at.
	 * @return array
	 */
	private function runCopyPhase( CopyCheckpoint $cp, $deadline ) {
		$handle = $this->manifest->openAt( (int) $cp->get( 'manifest_offset' ) );
		if ( false === $handle ) {
			return $this->result( 'error', __( 'Could not read the copy manifest.', 'wp-module-staging' ) );
		}

		$offset       = (int) $cp->get( 'manifest_offset' );
		$current_file = (string) $cp->get( 'current_file' );
		$file_offset  = (int) $cp->get( 'file_offset' );
		$files_done   = (int) $cp->get( 'files_done' );
		$bytes_done   = (int) $cp->get( 'bytes_done' );
		$retries      = (int) $cp->get( 'retries' );

		// Deadline is checked at the END of each iteration, so a burst always processes at least one
		// entry (and FileCopier always moves at least one chunk), guaranteeing forward progress.
		while ( true ) {
			$line_start = ftell( $handle );
			$line       = fgets( $handle );
			if ( false === $line ) {
				// fgets() returns false at EOF AND on a read error (e.g. NFS EIO). Only a genuine EOF
				// means the manifest is fully consumed; treating a mid-file read error as "done" would
				// promote a partial tree and delete the un-copied files from production. Require feof(),
				// and that we actually reached the end of the manifest, before declaring the copy done.
				clearstatcache();
				$size = @filesize( $this->manifest->path() );
				if ( ! feof( $handle ) || ( false !== $size && $line_start < $size ) ) {
					fclose( $handle );
					$cp->set( array( 'manifest_offset' => $line_start ) );
					return $this->result( 'error', __( 'Could not read the whole copy manifest; cannot continue safely.', 'wp-module-staging' ) );
				}
				$cp->set( array( 'phase' => CopyCheckpoint::PHASE_DONE ) );
				$offset = $line_start;
				break;
			}
			$line_end = ftell( $handle );
			$entry    = CopyManifest::parseLine( $line );
			if ( null === $entry ) {
				if ( '' === trim( $line ) ) {
					// A blank / whitespace-only line (e.g. a trailing newline) is benign: skip it.
					$offset = $line_end;
					continue;
				}
				// A non-empty line we cannot parse is a file we would never copy -- and the finalize swap
				// would then DELETE it from production. A re-parse cannot fix a corrupt manifest, so fail
				// CLOSED immediately rather than retry or silently drop the entry.
				fclose( $handle );
				$cp->set( array( 'manifest_offset' => $line_start ) );
				return $this->result( 'error', __( 'The copy manifest is corrupt; cannot continue safely.', 'wp-module-staging' ) );
			}

			$rel  = $entry['path'];
			$src  = $this->config['source_root'] . '/' . $rel;
			$dest = $this->config['dest_root'] . '/' . $this->withSuffix( $rel );
			$cand = null !== $this->config['link_dest_root'] ? $this->config['link_dest_root'] . '/' . $rel : null;
			$foff = ( $rel === $current_file ) ? $file_offset : 0;
			$res  = FileCopier::copyOne( $src, $dest, $entry['size'], $entry['mtime'], $cand, $foff, $deadline, $this->config['source_root'] );

			if ( FileCopier::RESULT_PARTIAL === $res ) {
				$current_file = $rel;
				$file_offset  = $foff;
				// bytes_done counts only COMPLETED files; the in-flight offset is added at display time
				// (CopyCheckpoint::progress), so it is not double-counted across resumes of this file.
				$offset       = $line_start; // Re-read this line next burst.
				break;
			}

			if ( FileCopier::RESULT_ERROR === $res ) {
				++$retries;
				$offset = $line_start; // Retry this file next burst.
				if ( $retries >= self::MAX_RETRIES ) {
					$cp->set(
						array(
							'manifest_offset' => $offset,
							'current_file'    => $rel,
							'file_offset'     => 0,
							'files_done'      => $files_done,
							'bytes_done'      => $bytes_done,
							'retries'         => $retries,
						)
					);
					return $this->result(
						'error',
						/* translators: %s: file path. */
						sprintf( __( 'Failed to copy %s after repeated attempts.', 'wp-module-staging' ), $rel )
					);
				}
				break; // Try again on the next burst.
			}

			// DONE or SKIP: advance past this entry. A DONE file is fully copied now (whether or not it
			// resumed mid-file), so count its whole size once.
			if ( FileCopier::RESULT_DONE === $res ) {
				$bytes_done += (int) $entry['size'];
			}
			++$files_done;
			$current_file = '';
			$file_offset  = 0;
			$retries      = 0;
			$offset       = $line_end;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $handle );

		$cp->set(
			array(
				'manifest_offset' => $offset,
				'current_file'    => $current_file,
				'file_offset'     => $file_offset,
				'files_done'      => $files_done,
				'bytes_done'      => $bytes_done,
				'retries'         => $retries,
			)
		);

		return $this->result( CopyCheckpoint::PHASE_DONE === $cp->get( 'phase' ) ? 'success' : 'running' );
	}

	/**
	 * Apply the destination suffix to the first path segment only
	 * (e.g. "uploads/2021/x.jpg" + "-new" => "uploads-new/2021/x.jpg").
	 *
	 * @param string $rel Relative path.
	 * @return string
	 */
	private function withSuffix( $rel ) {
		$suffix = (string) $this->config['dest_suffix'];
		if ( '' === $suffix ) {
			return $rel;
		}
		$slash = strpos( $rel, '/' );
		if ( false === $slash ) {
			return $rel . $suffix;
		}
		return substr( $rel, 0, $slash ) . $suffix . substr( $rel, $slash );
	}

	/**
	 * Build the standard return envelope.
	 *
	 * @param string $status  running|success|error.
	 * @param string $message Optional message.
	 * @return array
	 */
	private function result( $status, $message = '' ) {
		$out = array(
			'status'   => $status,
			'progress' => $this->checkpoint->progress(),
		);
		if ( '' !== $message ) {
			$out['message'] = $message;
		}
		return $out;
	}

	/**
	 * True once the whole tree is copied.
	 *
	 * @return bool
	 */
	public function isDone() {
		$this->checkpoint->load();
		return CopyCheckpoint::PHASE_DONE === $this->checkpoint->get( 'phase' );
	}

	/**
	 * Remove the job's manifest, cursor, and lock (after a successful hand-off or a discard).
	 */
	public function cleanup() {
		$this->manifest->delete();
		$this->checkpoint->delete();
		if ( is_file( $this->lock_path ) ) {
			@unlink( $this->lock_path );
		}
	}
}
