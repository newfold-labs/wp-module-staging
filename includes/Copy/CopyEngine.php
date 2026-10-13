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
				'roots'          => array( 'uploads', 'themes', 'plugins' ),
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
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
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
		$cp->load();

		// Seed a fresh job: queue the content roots that exist, start a clean manifest.
		if ( CopyCheckpoint::PHASE_ENUMERATE === $cp->get( 'phase' ) && ! $this->manifest->exists() ) {
			$pending = array();
			foreach ( (array) $this->config['roots'] as $root ) {
				if ( is_dir( $this->config['source_root'] . '/' . $root ) ) {
					$pending[] = $root;
				}
			}
			$cp->set( array( 'pending_dirs' => $pending ) );
			file_put_contents( $this->manifest->path(), '' );
		}

		$deadline = microtime( true ) + (float) $this->config['budget_seconds'];

		if ( CopyCheckpoint::PHASE_ENUMERATE === $cp->get( 'phase' ) ) {
			$done = $this->manifest->walk( $this->config['source_root'], $cp, $deadline );
			if ( $done ) {
				$cp->set(
					array(
						'phase'           => CopyCheckpoint::PHASE_COPY,
						'manifest_offset' => 0,
					)
				);
			}
			$cp->save();
			return $this->result( CopyCheckpoint::PHASE_DONE === $cp->get( 'phase' ) ? 'success' : 'running' );
		}

		if ( CopyCheckpoint::PHASE_COPY === $cp->get( 'phase' ) ) {
			$status = $this->runCopyPhase( $cp, $deadline );
			$cp->save();
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
				$cp->set( array( 'phase' => CopyCheckpoint::PHASE_DONE ) );
				$offset = $line_start;
				break;
			}
			$line_end = ftell( $handle );
			$entry    = CopyManifest::parseLine( $line );
			if ( null === $entry ) {
				$offset = $line_end;
				continue;
			}

			$rel  = $entry['path'];
			$src  = $this->config['source_root'] . '/' . $rel;
			$dest = $this->config['dest_root'] . '/' . $this->withSuffix( $rel );
			$cand = null !== $this->config['link_dest_root'] ? $this->config['link_dest_root'] . '/' . $rel : null;
			$foff = ( $rel === $current_file ) ? $file_offset : 0;
			$res  = FileCopier::copyOne( $src, $dest, $entry['size'], $entry['mtime'], $cand, $foff, $deadline );

			if ( FileCopier::RESULT_PARTIAL === $res ) {
				$current_file = $rel;
				$file_offset  = $foff;
				$bytes_done  += $foff; // Bytes written so far for this file.
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

			// DONE or SKIP: advance past this entry.
			if ( FileCopier::RESULT_DONE === $res ) {
				$bytes_done += ( $rel === $current_file ) ? ( $entry['size'] - $file_offset ) : $entry['size'];
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
