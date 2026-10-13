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
	 * The checkpoint carries the worklist (`pending_dirs`, relative to `$source_root`) and the running
	 * `files_total` / `bytes_total`. The caller seeds `pending_dirs` with the content roots before the
	 * first call and persists the checkpoint after this returns.
	 *
	 * @param string         $source_root Absolute path the manifest's relative paths are rooted at.
	 * @param CopyCheckpoint $cp          Cursor to read/update.
	 * @param float          $deadline    microtime(true) value to stop at.
	 * @return bool True when the walk is complete (worklist empty).
	 */
	public function walk( $source_root, CopyCheckpoint $cp, $deadline ) {
		$pending     = $cp->get( 'pending_dirs', array() );
		$files_total = (int) $cp->get( 'files_total', 0 );
		$bytes_total = (int) $cp->get( 'bytes_total', 0 );

		$handle = fopen( $this->path, 'ab' );
		if ( false === $handle ) {
			return false;
		}

		while ( ! empty( $pending ) ) {
			$rel_dir = array_pop( $pending );
			$abs_dir = '' === $rel_dir ? $source_root : $source_root . '/' . $rel_dir;

			$entries = @scandir( $abs_dir );
			if ( false === $entries ) {
				// Directory vanished or unreadable; check the budget and move on.
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$rel_entry = '' === $rel_dir ? $entry : $rel_dir . '/' . $entry;
				$abs_entry = $abs_dir . '/' . $entry;

				if ( is_link( $abs_entry ) ) {
					continue; // Match `rsync -rt` (no -l): symlinks are not copied.
				}
				if ( is_dir( $abs_entry ) ) {
					$pending[] = $rel_entry;
					continue;
				}
				if ( ! is_file( $abs_entry ) ) {
					continue; // Sockets, fifos, etc.
				}
				$size  = filesize( $abs_entry );
				$mtime = filemtime( $abs_entry );
				if ( false === $size || false === $mtime ) {
					continue;
				}
				fwrite( $handle, $rel_entry . "\t" . $size . "\t" . $mtime . "\n" );
				++$files_total;
				$bytes_total += (int) $size;
			}
			// Deadline checked after a whole directory is processed, so each burst advances at least
			// one directory (no livelock under a near-zero budget).
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		fclose( $handle );

		$cp->set(
			array(
				'pending_dirs' => $pending,
				'files_total'  => $files_total,
				'bytes_total'  => $bytes_total,
			)
		);

		return empty( $pending );
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
