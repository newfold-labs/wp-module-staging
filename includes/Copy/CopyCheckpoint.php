<?php
/**
 * Durable, resumable cursor for a chunked content copy.
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

namespace NewfoldLabs\WP\Module\Staging\Copy;

/**
 * Persists where a copy job is, so a burst that is interrupted (SIGKILL, request timeout, closed tab)
 * resumes from the last saved point instead of starting over.
 *
 * The cursor is written to disk with a temp-file + rename, and always AFTER the bytes it describes
 * have been flushed. That ordering is the whole safety property: a crash can only ever cause the next
 * run to REPEAT a little work, never to SKIP a file that was not actually copied.
 *
 * This class is pure PHP (no WordPress), so it can be unit-tested directly against a temp dir.
 */
class CopyCheckpoint {

	/**
	 * Enumerating the source tree into the manifest.
	 */
	const PHASE_ENUMERATE = 'enumerate';

	/**
	 * Copying manifest entries into the destination.
	 */
	const PHASE_COPY = 'copy';

	/**
	 * All files copied and verified.
	 */
	const PHASE_DONE = 'done';

	/**
	 * Absolute path to the cursor file on disk.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Cursor state. See defaults() for the shape.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Construct a checkpoint bound to a cursor file path.
	 *
	 * @param string $path Absolute path to the cursor JSON file.
	 */
	public function __construct( $path ) {
		$this->path  = $path;
		$this->state = self::defaults();
	}

	/**
	 * The initial cursor for a fresh job.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			// A unique id for this job, stamped by the engine when it seeds a fresh cursor. Carried for
			// diagnostics/log correlation. A stale cursor cannot be mistaken for the current job: the
			// controller's setup phase calls CopyEngine::cleanup() before each run (see
			// ContentCopyController::runSetup), so the cursor is always deleted and reseeded, never
			// resumed across jobs.
			'job_id'          => '',
			'phase'           => self::PHASE_ENUMERATE,
			// Enumerate phase: directories still to walk (relative to the source root). Seeded by the
			// engine with the content roots (uploads, themes, plugins).
			'pending_dirs'    => array(),
			// Enumerate phase: the directory currently being walked (relative to the source root, or null
			// when none is mid-walk) and the NAME of the last entry already processed in it (or null for
			// "start from the beginning"). A single huge directory resumes after that name instead of
			// being re-walked (and re-appended to the manifest) every burst. Resuming by name, not index,
			// tolerates the directory's contents changing between bursts: nothing <= the last done name is
			// re-processed, and no existing entry that sorts after it is skipped.
			'walk_dir'        => null,
			'walk_last'       => null,
			// True only once the walk has finished. The copy phase must never start on a manifest whose
			// walk did not complete (a crash mid-walk would otherwise look "done" with an empty worklist).
			'walk_complete'   => false,
			// Copy phase: byte offset into the manifest file of the next line to process.
			'manifest_offset' => 0,
			// Copy phase: the entry currently in flight and how many of its bytes are already on disk,
			// so a single file larger than one budget resumes mid-file instead of restarting.
			'current_file'    => '',
			'file_offset'     => 0,
			// Progress + accounting.
			'files_done'      => 0,
			'bytes_done'      => 0,
			'files_total'     => 0,
			'bytes_total'     => 0,
			'retries'         => 0,
		);
	}

	/**
	 * Load the cursor from disk if present; otherwise keep the fresh defaults.
	 *
	 * @return bool True if an existing cursor was loaded.
	 */
	public function load() {
		if ( ! is_file( $this->path ) ) {
			return false;
		}
		$raw = file_get_contents( $this->path );
		if ( false === $raw || '' === $raw ) {
			return false;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}
		// Merge over defaults so an older/partial cursor still has every key, then decode the paths.
		$this->state = self::decodePaths( array_merge( self::defaults(), $decoded ) );
		return true;
	}

	/**
	 * Encode the path-bearing fields of a state array to base64 for safe JSON storage.
	 *
	 * @param array $state State with raw paths.
	 * @return array State with encoded paths.
	 */
	private static function encodePaths( array $state ) {
		if ( isset( $state['current_file'] ) ) {
			$state['current_file'] = base64_encode( (string) $state['current_file'] );
		}
		// walk_dir / walk_last are null when no directory is mid-walk; only encode an actual path/name.
		if ( isset( $state['walk_dir'] ) && null !== $state['walk_dir'] ) {
			$state['walk_dir'] = base64_encode( (string) $state['walk_dir'] );
		}
		if ( isset( $state['walk_last'] ) && null !== $state['walk_last'] ) {
			$state['walk_last'] = base64_encode( (string) $state['walk_last'] );
		}
		if ( isset( $state['pending_dirs'] ) && is_array( $state['pending_dirs'] ) ) {
			$state['pending_dirs'] = array_map( 'base64_encode', $state['pending_dirs'] );
		}
		return $state;
	}

	/**
	 * Reverse encodePaths(): decode the path-bearing fields back to raw bytes.
	 *
	 * @param array $state State with encoded paths.
	 * @return array State with raw paths.
	 */
	private static function decodePaths( array $state ) {
		if ( isset( $state['current_file'] ) ) {
			$state['current_file'] = (string) base64_decode( (string) $state['current_file'] );
		}
		// A null walk_dir / walk_last (nothing mid-walk) round-trips as null, not an empty decoded string.
		if ( isset( $state['walk_dir'] ) && null !== $state['walk_dir'] ) {
			$state['walk_dir'] = (string) base64_decode( (string) $state['walk_dir'] );
		}
		if ( isset( $state['walk_last'] ) && null !== $state['walk_last'] ) {
			$state['walk_last'] = (string) base64_decode( (string) $state['walk_last'] );
		}
		if ( isset( $state['pending_dirs'] ) && is_array( $state['pending_dirs'] ) ) {
			$state['pending_dirs'] = array_map(
				function ( $v ) {
					return (string) base64_decode( (string) $v );
				},
				$state['pending_dirs']
			);
		}
		return $state;
	}

	/**
	 * Persist the cursor atomically (temp file + rename), so a crash mid-write cannot leave a
	 * truncated cursor that would be unreadable on resume.
	 *
	 * @return bool
	 */
	public function save() {
		$dir = dirname( $this->path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			return false;
		}
		$json = wp_json_encode( self::encodePaths( $this->state ) );
		if ( false === $json ) {
			return false;
		}
		$tmp     = $this->path . '.tmp-' . getmypid();
		$written = file_put_contents( $tmp, $json, LOCK_EX );
		// A short write (full disk / over quota) must NOT be promoted: a truncated cursor would be
		// unreadable on resume, and the engine would then restart and potentially mis-count as done.
		if ( false === $written || $written < strlen( $json ) ) {
			@unlink( $tmp );
			return false;
		}
		if ( ! rename( $tmp, $this->path ) ) {
			@unlink( $tmp );
			return false;
		}
		return true;
	}

	/**
	 * Remove the cursor file (job finished or discarded).
	 */
	public function delete() {
		if ( is_file( $this->path ) ) {
			@unlink( $this->path );
		}
	}

	/**
	 * Get a cursor field.
	 *
	 * @param string $key     Field name.
	 * @param mixed  $default Value if the field is missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->state ) ? $this->state[ $key ] : $default;
	}

	/**
	 * Set one or more cursor fields (does not persist; call save()).
	 *
	 * @param array $changes Field => value.
	 * @return CopyCheckpoint
	 */
	public function set( array $changes ) {
		$this->state = array_merge( $this->state, $changes );
		return $this;
	}

	/**
	 * The whole cursor, for returning progress to the client.
	 *
	 * @return array
	 */
	public function toArray() {
		return $this->state;
	}

	/**
	 * A compact progress view for the status endpoint / UI.
	 *
	 * @return array
	 */
	public function progress() {
		$bytes_total = (int) $this->state['bytes_total'];
		// bytes_done counts completed files; add the in-flight file's offset so the bar advances during
		// a large file without being double-counted when that file finishes.
		$bytes_done  = (int) $this->state['bytes_done'] + (int) $this->state['file_offset'];
		$percent     = $bytes_total > 0
			? min( 100, (int) floor( ( $bytes_done / $bytes_total ) * 100 ) )
			: ( self::PHASE_DONE === $this->state['phase'] ? 100 : 0 );
		return array(
			'phase'       => $this->state['phase'],
			'files_done'  => (int) $this->state['files_done'],
			'files_total' => (int) $this->state['files_total'],
			'bytes_done'  => $bytes_done,
			'bytes_total' => $bytes_total,
			'percent'     => $percent,
		);
	}
}
