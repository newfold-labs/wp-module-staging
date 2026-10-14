<?php
/**
 * Reads and refreshes the staging command mutex that lib/.staging owns.
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

namespace NewfoldLabs\WP\Module\Staging;

/**
 * The PHP half of the staging command mutex.
 *
 * Only ONE staging operation (create, clone, destroy, a legacy deploy, or a stepped content deploy)
 * may run against a site at a time: they all move, rsync, `rm -rf` and rename the same production and
 * staging trees, so two at once can delete files out from under each other and promote a half-built
 * tree over live production. lib/.staging's `lock_check` is what enforces that, and this class is how
 * PHP takes part in the same lock.
 *
 * ## Why a file, and not the WordPress transient this used to be
 *
 * lib/.staging runs WP-CLI with the object-cache drop-in skipped (see the NFD_WP_EXEC comment there),
 * so `wp_using_ext_object_cache()` is false inside the script while it is true for this request.
 * Anything the two sides share therefore cannot live anywhere that moves with the object cache. A
 * transient does: bash wrote it to the database as an option while a persistent cache in front of
 * `get_transient()` here would answer from a stale (or absent) cached copy. Worse, the lock belongs to
 * PRODUCTION's install, and a deploy is driven from the STAGING install, whose `$wpdb` points at a
 * different table prefix -- so a naive `get_transient()` here would read the wrong table entirely and
 * see no lock at all. A plain file under production's `nfd-private` has neither problem: both sides
 * address it by absolute path, there is no cache and no prefix, and `nfd-private/.htaccess` (written
 * by lib/.staging's `log`) already denies it to the web.
 *
 * ## Format
 *
 * One line: `active:<REFRESHED_AT>:<TOKEN>:<PID>`
 *
 *   - REFRESHED_AT -- unix seconds, rewritten on every refresh (NOT the original acquire time).
 *   - TOKEN        -- opaque `[A-Za-z0-9]{1,64}` identifying the operation holding the lock. A stepped
 *                     content deploy uses its per-job token (ContentCopyController), so each of its
 *                     bash bookends re-presents the same token and refreshes the lock it already owns
 *                     instead of blocking on it. Other commands get a random one from bash.
 *   - PID          -- the bash process holding the lock, or 0 when no single process does (a stepped
 *                     job between bursts, which this class refreshes).
 *
 * ## Division of labour
 *
 * bash is the authority: `lock_check` is the only thing that ACQUIRES or STEALS the lock, and it alone
 * decides a holder is dead (TTL plus a `kill -0` liveness test on PID). This class only ever READS the
 * lock, or REFRESHES one that already carries our own token. It never creates, steals or releases one,
 * so it cannot hand two operations the same mutex.
 */
class StagingCommandLock {

	/**
	 * Seconds after the last refresh that a lock is treated as possibly abandoned. Must stay in step
	 * with LOCK_TTL in lib/.staging.
	 */
	const TTL = 1800;

	/**
	 * How far inside the TTL a lock must be before releaseIfOurs() will remove it by path.
	 *
	 * Deleting by path cannot be made atomic against lib/.staging deciding the same lock is abandoned
	 * and replacing it with its own, so the release only runs on a lock far enough from its TTL that
	 * no steal is permitted. Without the margin the two could in principle cross at the exact instant
	 * of the TTL boundary; with it, that would need the method to stall for a minute between reading
	 * the lock and unlinking it.
	 */
	const RELEASE_MARGIN = 60;

	/**
	 * Lock path relative to the production directory. Must stay in step with LOCK_FILE in lib/.staging.
	 */
	const RELATIVE_PATH = 'nfd-private/nfd-staging-command.lock';

	/**
	 * Absolute path to the lock file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Build a lock handle for a site.
	 *
	 * @param string $production_dir Absolute production install root (the lock always lives with
	 *                               production, even when the request runs in the staging install).
	 */
	public function __construct( $production_dir ) {
		$this->path = rtrim( (string) $production_dir, '/' ) . '/' . self::RELATIVE_PATH;
	}

	/**
	 * Absolute path to the lock file.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Read and parse the current lock.
	 *
	 * An unreadable or unparseable lock is reported as a lock with no token and no pid, timestamped
	 * from the file's mtime, rather than as no lock at all: a truncated file (a crash between the
	 * exclusive create and the write) still means somebody claimed the mutex.
	 *
	 * @return array|null { timestamp: int, token: string, pid: int } or null when no lock exists.
	 */
	public function read() {
		clearstatcache( true, $this->path );
		if ( ! is_file( $this->path ) ) {
			return null;
		}

		$raw = @file_get_contents( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

		return $this->parse( false === $raw ? '' : $raw );
	}

	/**
	 * Parse a lock value, falling back to the file's mtime when it carries no usable timestamp.
	 *
	 * @param string $raw Raw file contents.
	 *
	 * @return array { timestamp: int, token: string, pid: int }
	 */
	private function parse( $raw ) {
		$fields = explode( ':', trim( (string) $raw ) );

		$timestamp = isset( $fields[1] ) && ctype_digit( $fields[1] ) ? (int) $fields[1] : 0;
		if ( 0 === $timestamp ) {
			$mtime     = @filemtime( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$timestamp = false === $mtime ? 0 : (int) $mtime;
		}

		$token = isset( $fields[2] ) && $this->isValidToken( $fields[2] ) ? $fields[2] : '';
		$pid   = isset( $fields[3] ) && ctype_digit( $fields[3] ) ? (int) $fields[3] : 0;

		return array(
			'timestamp' => $timestamp,
			'token'     => $token,
			'pid'       => $pid,
		);
	}

	/**
	 * Whether a token is well formed. Keeps a token out of the colon-delimited lock format and out of
	 * a shell argument.
	 *
	 * @param string $token Token to check.
	 *
	 * @return bool
	 */
	public function isValidToken( $token ) {
		return is_string( $token ) && (bool) preg_match( '/^[A-Za-z0-9]{1,64}$/', $token );
	}

	/**
	 * Whether the lock currently carries this token (regardless of age).
	 *
	 * @param string $token Our token.
	 *
	 * @return bool
	 */
	public function holds( $token ) {
		if ( ! $this->isValidToken( $token ) ) {
			return false;
		}

		$lock = $this->read();

		return null !== $lock && $lock['token'] === $token;
	}

	/**
	 * Whether a live lock belonging to SOMEONE ELSE is in place, i.e. starting a new operation now
	 * would collide with one already running.
	 *
	 * Deliberately TTL-only, with no process-liveness test. bash's `lock_check` applies the stronger
	 * test (a lock whose recorded pid is still alive is held even past the TTL), and it runs
	 * immediately after this on the same operation. Erring in that direction keeps this fast path from
	 * refusing an operation bash would have allowed; the only cost of being wrong is that bash reports
	 * the collision instead of this method.
	 *
	 * @param string $token Our token ('' when we hold no lock of our own).
	 *
	 * @return bool
	 */
	public function heldByOther( $token ) {
		$lock = $this->read();
		if ( null === $lock ) {
			return false;
		}

		if ( '' !== (string) $token && $lock['token'] === $token ) {
			return false;
		}

		return ( time() - $lock['timestamp'] ) <= self::TTL;
	}

	/**
	 * Push the lock's timestamp forward, but only while it still carries our own token.
	 *
	 * This is what keeps a stepped content deploy holding the mutex for its whole run: the copy phase
	 * is PHP, with no bash process alive between bursts, so without a refresh here the lock bash took
	 * at setup would age out mid-copy and another operation could take it.
	 *
	 * The rewrite is done IN PLACE on a file handle opened before the token was checked, which is the
	 * point: if bash concluded the lock was abandoned and took it, it did so by removing the file and
	 * exclusively creating a new one, so this write lands on the inode we verified -- now unlinked --
	 * and cannot clobber the new holder's lock. The re-read afterwards is by path, so it sees the
	 * theft and reports failure. A caller that cannot refresh must treat its job as no longer holding
	 * the mutex and stop.
	 *
	 * @param string $token Our token.
	 *
	 * @return bool True when the lock is ours and now carries a fresh timestamp.
	 */
	public function refresh( $token ) {
		if ( ! $this->isValidToken( $token ) ) {
			return false;
		}

		$handle = @fopen( $this->path, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $handle ) {
			return false;
		}

		$written = false;
		if ( flock( $handle, LOCK_EX ) ) {
			$raw = stream_get_contents( $handle );
			if ( $this->parse( false === $raw ? '' : $raw )['token'] === $token ) {
				$value = 'active:' . time() . ':' . $token . ':0';
				if ( 0 === fseek( $handle, 0 ) && false !== fwrite( $handle, $value ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					ftruncate( $handle, strlen( $value ) );
					fflush( $handle );
					$written = true;
				}
			}
			flock( $handle, LOCK_UN );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $written && $this->holds( $token );
	}

	/**
	 * Free the lock when the caller's PHP-side job has failed and is certainly the only thing holding
	 * it.
	 *
	 * Without this, a job that fails during the copy phase -- a full disk, an unreadable manifest --
	 * leaves the lock it took at setup in place until the TTL runs out, blocking every other staging
	 * operation for up to half an hour over a deploy that is already over. The old transient lock did
	 * not have that problem, because every failure path went through bash's error(), which released.
	 *
	 * Three conditions, all required, and all of them about being sure nothing else is involved:
	 *
	 *   - the lock carries this job's token, so it is ours to free;
	 *   - it records pid 0, which only refresh() above ever writes. A bash verb's lock_check always
	 *     writes its own pid, so pid 0 means no verb has run since the last copy burst and none can
	 *     be in flight -- in particular this is never the orphaned-setup case, where a killed
	 *     request leaves a `rm -rf` still running under this same token and the lock must stay;
	 *   - it is comfortably within the TTL (by RELEASE_MARGIN), so lib/.staging may not concurrently
	 *     decide it is abandoned and claim it. That is what makes deleting by path safe: a lock
	 *     nobody may steal right now cannot turn into somebody else's between the check and the
	 *     unlink. For a lock already at or past the TTL there is nothing to gain anyway -- it no
	 *     longer blocks anyone.
	 *
	 * @param string $token Our token.
	 *
	 * @return bool True when a lock was removed.
	 */
	public function releaseIfOurs( $token ) {
		if ( ! $this->isValidToken( $token ) ) {
			return false;
		}

		$lock = $this->read();
		if ( null === $lock || $lock['token'] !== $token || 0 !== $lock['pid'] ) {
			return false;
		}

		if ( ( time() - $lock['timestamp'] ) > ( self::TTL - self::RELEASE_MARGIN ) ) {
			return false;
		}

		return @unlink( $this->path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
