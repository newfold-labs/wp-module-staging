<?php
/**
 * The list of files a copy job must move, built by a resumable directory walk.
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

namespace NewfoldLabs\WP\Module\Staging\Copy;

/**
 * Writes and reads a manifest: one line per source file, "relpath\tsize\tmtime".
 *
 * The walk itself is budget-bounded and resumable (via a directory worklist in the checkpoint), so
 * enumerating a tree of millions of inodes cannot blow the per-request envelope either. Symlinks are
 * skipped, matching the existing `rsync -rt` behaviour (no -l), and files that vanish between the walk
 * and the copy are skipped gracefully.
 *
 * Pure PHP (no WordPress), so it is unit-testable against a temp dir.
 */
class CopyManifest {

	/**
	 * Walk made progress but is not finished.
	 */
	const WALK_MORE = 'more';

	/**
	 * Walk finished: every directory enumerated.
	 */
	const WALK_DONE = 'done';

	/**
	 * Walk hit an unrecoverable condition (unreadable directory, un-stattable file, manifest write
	 * failure, or an unsafe name). The job must abort rather than copy a partial tree.
	 */
	const WALK_ERROR = 'error';

	/**
	 * Absolute path to the manifest file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Construct a manifest bound to a file path.
	 *
	 * @param string $path Absolute path to the manifest file.
	 */
	public function __construct( $path ) {
		$this->path = $path;
	}

	/**
	 * Absolute path to the manifest file.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Whether the manifest file has been created.
	 *
	 * @return bool
	 */
	public function exists() {
		return is_file( $this->path );
	}

	/**
	 * Advance the directory walk, appending file entries to the manifest until the time budget is hit
	 * or the worklist is empty.
	 *
	 * The checkpoint carries the worklist (`pending_dirs`, relative to `$source_root`), the directory
	 * currently mid-walk (`walk_dir` + `walk_offset`), and the running `files_total` / `bytes_total`.
	 * The caller seeds `pending_dirs` with the content roots before the first call and persists the
	 * checkpoint after this returns.
	 *
	 * The deadline is checked after EVERY entry, and the walk resumes mid-directory by NAME (skipping
	 * every entry at or before `walk_last` in a byte-wise ordering). So a single directory with millions
	 * of entries cannot blow the budget in one burst, and a burst killed partway through resumes after
	 * the entries it already wrote rather than re-walking the whole directory and re-appending them
	 * (which would grow the manifest without bound).
	 *
	 * Resuming by name rather than by index is deliberate: entries can be added or removed between
	 * bursts (uploads churn, cache/log rotation, WP-cron). An index would then point at a shifted entry
	 * and could SKIP a file that still exists -- which finalize would delete from production. By name,
	 * every existing entry sorting after `walk_last` is still visited, and only entries at or before it
	 * (already handled) are skipped. The order is produced with SCANDIR_SORT_NONE + a byte-wise sort, so
	 * it does not depend on the process locale (the default scandir sort uses strcoll, which a plugin's
	 * setlocale() could shift between bursts and desynchronise the resume).
	 *
	 * @param string         $source_root Absolute path the manifest's relative paths are rooted at.
	 * @param CopyCheckpoint $cp          Cursor to read/update.
	 * @param float          $deadline    microtime(true) value to stop at.
	 * @return string One of WALK_MORE, WALK_DONE, WALK_ERROR.
	 */
	public function walk( $source_root, CopyCheckpoint $cp, $deadline ) {
		$pending     = $cp->get( 'pending_dirs', array() );
		$walk_dir    = $cp->get( 'walk_dir', null );
		$walk_last   = $cp->get( 'walk_last', null );
		$files_total = (int) $cp->get( 'files_total', 0 );
		$bytes_total = (int) $cp->get( 'bytes_total', 0 );

		$handle = fopen( $this->path, 'ab' );
		if ( false === $handle ) {
			return self::WALK_ERROR;
		}

		$status = self::WALK_MORE;
		while ( true ) {
			// Pick the directory to work on: resume the one left mid-walk, else start the next pending.
			if ( null === $walk_dir ) {
				if ( empty( $pending ) ) {
					break; // Worklist empty and nothing mid-walk: the tree is fully enumerated.
				}
				$walk_dir  = array_pop( $pending );
				$walk_last = null;
			}

			$rel_dir = $walk_dir;
			$abs_dir = '' === $rel_dir ? $source_root : $source_root . '/' . $rel_dir;

			$entries = @scandir( $abs_dir, SCANDIR_SORT_NONE );
			if ( false === $entries ) {
				// Cannot enumerate this directory. Fail CLOSED: silently skipping it would make the copy
				// omit the whole subtree, and the finalize swap would then delete it from production.
				$status = self::WALK_ERROR;
				break;
			}
			// Deterministic byte-wise order, locale-independent, so the name-based resume below is stable.
			sort( $entries, SORT_STRING );

			foreach ( $entries as $entry ) {
				// Resume: skip every entry already handled in an earlier burst of this directory.
				if ( null !== $walk_last && strcmp( $entry, $walk_last ) <= 0 ) {
					continue;
				}
				// '.', '..' and '.git' do no work (the last matches the legacy `rsync --exclude=.git`:
				// never ship a repo into production), but still advance the resume point + deadline below.
				if ( '.' !== $entry && '..' !== $entry && '.git' !== $entry ) {
					if ( false !== strpbrk( $entry, "\n\r" ) ) {
						// A newline in a name breaks the line-based manifest and could smuggle a path
						// traversal; refuse the job rather than drop the file or mishandle the name.
						$status = self::WALK_ERROR;
						break 2;
					}
					$rel_entry = '' === $rel_dir ? $entry : $rel_dir . '/' . $entry;
					$abs_entry = $abs_dir . '/' . $entry;

					// Classify from a single lstat so an un-stattable entry fails the job CLOSED rather
					// than being silently dropped. A directory that is readable but not searchable (mode
					// 0644) lets scandir list these children yet makes every child un-stattable; without
					// this guard those children would be omitted from the copy and then DELETED from
					// production by the finalize swap.
					$st = @lstat( $abs_entry );
					if ( false === $st ) {
						$status = self::WALK_ERROR;
						break 2;
					}
					$fmt = $st['mode'] & 0170000; // S_IFMT: the file-type bits of the mode.
					if ( 0040000 === $fmt ) {
						$pending[] = $rel_entry; // S_IFDIR: recurse into it.
					} elseif ( 0100000 === $fmt ) {
						// S_IFREG: a regular file we must copy. lstat already gave a trustworthy size/mtime.
						$size    = (int) $st['size'];
						$mtime   = (int) $st['mtime'];
						$line    = $rel_entry . "\t" . $size . "\t" . $mtime . "\n";
						$written = fwrite( $handle, $line );
						if ( false === $written || $written < strlen( $line ) ) {
							$status = self::WALK_ERROR; // Manifest write failed (quota/space); fail closed.
							break 2;
						}
						++$files_total;
						$bytes_total += $size;
					}
					// Otherwise S_IFLNK (symlink, matching `rsync -rt` with no -l) or another non-regular
					// file (socket, fifo, device): never copied.
				}

				// This entry is fully handled; resume strictly after its name next burst.
				$walk_last = $entry;

				// Per-entry deadline: stop cleanly after this entry so no single directory monopolises a
				// burst and a killed burst never re-appends this dir.
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
			}

			// Reached here only by finishing the directory (the deadline path breaks out of the while).
			// Clear the mid-walk marker so the next iteration starts the next pending directory.
			$walk_dir  = null;
			$walk_last = null;
		}

		fclose( $handle );

		if ( self::WALK_ERROR === $status ) {
			// Persist progress, but do NOT mark the walk complete; the engine aborts the job.
			$cp->set(
				array(
					'pending_dirs' => $pending,
					'walk_dir'     => $walk_dir,
					'walk_last'    => $walk_last,
					'files_total'  => $files_total,
					'bytes_total'  => $bytes_total,
				)
			);
			return self::WALK_ERROR;
		}

		$complete = ( null === $walk_dir ) && empty( $pending );
		$cp->set(
			array(
				'pending_dirs'  => $pending,
				'walk_dir'      => $walk_dir,
				'walk_last'     => $walk_last,
				'files_total'   => $files_total,
				'bytes_total'   => $bytes_total,
				'walk_complete' => $complete,
			)
		);

		return $complete ? self::WALK_DONE : self::WALK_MORE;
	}

	/**
	 * Open the manifest for reading, positioned at a byte offset.
	 *
	 * @param int $offset Byte offset to seek to.
	 * @return resource|false File handle, or false on failure.
	 */
	public function openAt( $offset ) {
		$handle = fopen( $this->path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}
		return $handle;
	}

	/**
	 * Parse a manifest line into [relpath, size, mtime].
	 *
	 * Splits size and mtime off the right so a relative path that itself contains a tab still parses.
	 *
	 * @param string $line Raw line (trailing newline tolerated).
	 * @return array|null array('path'=>string,'size'=>int,'mtime'=>int) or null if malformed.
	 */
	public static function parseLine( $line ) {
		$line = rtrim( $line, "\r\n" );
		if ( '' === $line ) {
			return null;
		}
		$p2 = strrpos( $line, "\t" );
		if ( false === $p2 ) {
			return null;
		}
		$mtime = substr( $line, $p2 + 1 );
		$rest  = substr( $line, 0, $p2 );
		$p1    = strrpos( $rest, "\t" );
		if ( false === $p1 ) {
			return null;
		}
		$size = substr( $rest, $p1 + 1 );
		$path = substr( $rest, 0, $p1 );
		if ( '' === $path || ! ctype_digit( $size ) || ! ctype_digit( $mtime ) ) {
			return null;
		}
		return array(
			'path'  => $path,
			'size'  => (int) $size,
			'mtime' => (int) $mtime,
		);
	}

	/**
	 * Remove the manifest file.
	 */
	public function delete() {
		if ( is_file( $this->path ) ) {
			@unlink( $this->path );
		}
	}
}
