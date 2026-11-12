<?php
/**
 * Copies one file within the time budget, resumably, with a hardlink fast path.
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

namespace NewfoldLabs\WP\Module\Staging\Copy;

/**
 * Moves a single manifest entry into place. Three behaviours, in order:
 *
 *   1. Hardlink fast path (deploy only): if a file at the link-dest (current production) matches the
 *      source by size + mtime, hardlink it instead of copying bytes. This is the per-file analogue of
 *      `rsync --link-dest`: unchanged files cost no bytes.
 *   2. Resumable byte copy: otherwise stream the bytes in chunks, stopping cleanly at the deadline and
 *      returning PARTIAL with the offset reached, so a file larger than one budget resumes mid-file
 *      next call. The destination is truncated to the checkpointed offset before appending, so a dead
 *      request can never corrupt the file.
 *   3. Verify: on completion the destination's size must equal the manifest size, and its mtime is set
 *      to the source mtime so a later deploy's delta still recognises it as unchanged.
 *
 * Pure PHP (no WordPress); unit-testable against temp dirs.
 */
class FileCopier {

	const RESULT_DONE    = 'done';
	const RESULT_PARTIAL = 'partial';
	const RESULT_SKIP    = 'skip';
	const RESULT_ERROR   = 'error';

	/**
	 * Bytes moved per read/write iteration.
	 */
	const CHUNK_BYTES = 1048576; // 1 MiB.

	/**
	 * Maximum mid-file re-snapshots from offset 0 before pinning a growing file's target size.
	 */
	const MAX_RESNAPSHOTS = 3;

	/**
	 * Ensure one directory entry from the manifest exists on the destination tree.
	 *
	 * @param string $src         Absolute source directory path.
	 * @param string $dest        Absolute destination directory path.
	 * @param int    $mtime       Expected mtime from the manifest.
	 * @param string $source_root Absolute content root the copy reads from.
	 * @return string One of the RESULT_* constants.
	 */
	public static function ensureDirectory( $src, $dest, $mtime, $source_root ) {
		if ( ! is_dir( $src ) ) {
			$parent_ok = is_dir( dirname( $src ) );
			$root_ok   = is_dir( $source_root );
			return ( $parent_ok && $root_ok ) ? self::RESULT_SKIP : self::RESULT_ERROR;
		}

		$dest_dir = dirname( $dest );
		if ( ! is_dir( $dest_dir ) ) {
			if ( ! mkdir( $dest_dir, 0755, true ) && ! is_dir( $dest_dir ) ) {
				return self::RESULT_ERROR;
			}
			self::applyDirModeFromSource( dirname( $src ), $dest_dir );
		}
		if ( is_dir( $dest ) ) {
			self::applyDirModeFromSource( $src, $dest );
		} elseif ( ! mkdir( $dest, 0755, false ) && ! is_dir( $dest ) ) {
			return self::RESULT_ERROR;
		} else {
			self::applyDirModeFromSource( $src, $dest );
		}
		if ( $mtime > 0 ) {
			@touch( $dest, $mtime );
		}
		return self::RESULT_DONE;
	}

	/**
	 * Copy one file.
	 *
	 * @param string      $src         Absolute source path.
	 * @param string      $dest        Absolute destination path.
	 * @param int         $size        In/out: expected size (manifest or a persisted re-snapshot).
	 * @param int         $mtime       In/out: expected mtime (manifest or a persisted re-snapshot).
	 * @param string|null $link_cand   Absolute hardlink candidate (current production copy), or null.
	 * @param int         $offset      In/out: bytes already written; updated as copying proceeds.
	 * @param float       $deadline    microtime(true) to stop at.
	 * @param string      $source_root Absolute content root the copy reads from. Used to tell a single
	 *                                 file genuinely vanishing (safe to skip) from the whole tree
	 *                                 disappearing (catastrophic: fail closed).
	 * @param int         $resnapshots In/out: mid-file re-snapshot count for continuously changing files.
	 * @return string One of the RESULT_* constants.
	 */
	public static function copyOne( $src, $dest, &$size, &$mtime, $link_cand, &$offset, $deadline, $source_root, &$resnapshots = 0 ) {
		if ( ! file_exists( $src ) ) {
			// The source is gone. Distinguish a single file genuinely removed between walk and copy (safe
			// to skip) from the whole subtree or content root having vanished -- e.g. a concurrent clone
			// `rm -rf`-ing staging mid-copy, or an EACCES/ESTALE that hides a tree still on disk. SKIPping
			// a file that is really still there makes the finalize swap DELETE it from production, so skip
			// only when the immediate parent dir AND the content root both still exist; else fail CLOSED.
			$parent_ok = is_dir( dirname( $src ) );
			$root_ok   = is_dir( $source_root );
			return ( $parent_ok && $root_ok ) ? self::RESULT_SKIP : self::RESULT_ERROR;
		}

		$dest_dir = dirname( $dest );
		if ( ! is_dir( $dest_dir ) ) {
			if ( ! mkdir( $dest_dir, 0755, true ) && ! is_dir( $dest_dir ) ) {
				return self::RESULT_ERROR;
			}
			self::applyDirModeFromSource( dirname( $src ), $dest_dir );
		}

		// 1. Hardlink fast path.
		//
		// The comparison is production against the source AS IT IS NOW, not against the size + mtime
		// the manifest recorded at enumeration. The copy phase can run for hours, so a staging file
		// edited after the walk still matches the manifest -- and so did production, which meant the
		// edit was hardlinked away and silently not shipped. The byte copy below re-snapshots for
		// exactly this reason; the two paths have to agree about which version of the file a deploy
		// ships, or the answer depends on whether a hardlink happened to be possible.
		if ( null !== $link_cand && 0 === $offset && is_file( $link_cand ) && ! is_link( $link_cand ) ) {
			clearstatcache( true, $src );
			clearstatcache( true, $link_cand );
			$src_size  = filesize( $src );
			$src_mtime = filemtime( $src );
			if ( false !== $src_size && false !== $src_mtime
				&& filesize( $link_cand ) === $src_size && filemtime( $link_cand ) === $src_mtime ) {
				if ( is_file( $dest ) || is_link( $dest ) ) {
					@unlink( $dest );
				}
				if ( @link( $link_cand, $dest ) ) {
					return self::RESULT_DONE; // Shares the inode (and the mtime); nothing to verify.
				}
				// Hardlink failed (cross-device, etc.); fall through to a byte copy.
			}
		}

		// 2. Resumable byte copy.
		$in = fopen( $src, 'rb' );
		if ( false === $in ) {
			// Enumerated but now unreadable. Tolerate the legacy htaccess exception (unreadable
			// .htaccess.* backups); otherwise fail CLOSED rather than silently omit the file.
			return self::isIgnorable( $src ) ? self::RESULT_SKIP : self::RESULT_ERROR;
		}
		// Snapshot integrity: the manifest recorded this file's size + mtime at enumeration. If either
		// has changed, the file was rewritten/appended/truncated since, so copying exactly the manifest
		// `$size` would ship a frankenfile (a prefix of new content that passes the size check, or a
		// short read). Re-snapshot to the CURRENT size + mtime and copy THAT instead: that ships a
		// self-consistent version of the file (its state right now) and lets the deploy COMPLETE, rather
		// than failing every retry while a live file (regenerated CSS, an active log) keeps changing.
		// Restart from offset 0 so no stale partial sized to the old snapshot is kept. CopyEngine persists
		// the re-snapshotted size + mtime in the cursor so the next burst passes them back in and a
		// large, stable post-change file can resume mid-file instead of re-snapshotting every burst.
		$sstat = fstat( $in );
		if ( false === $sstat ) {
			fclose( $in );
			return self::RESULT_ERROR;
		}
		if ( (int) $sstat['size'] !== (int) $size || (int) $sstat['mtime'] !== (int) $mtime ) {
			if ( $offset > 0 && $resnapshots >= self::MAX_RESNAPSHOTS ) {
				// A hot file (e.g. an active debug.log) has changed across every burst. If it only grew,
				// keep copying up to the pinned snapshot size so the job finishes without livelocking; if
				// it shrank below our progress, fail this attempt so CopyEngine's retry cap bounds it.
				if ( (int) $sstat['size'] < (int) $size ) {
					fclose( $in );
					return self::RESULT_ERROR;
				}
			} else {
				if ( $offset > 0 ) {
					++$resnapshots;
				}
				$size   = (int) $sstat['size'];
				$mtime  = (int) $sstat['mtime'];
				$offset = 0;
			}
		}
		// At offset 0, remove any existing destination first. If $dest is a hardlink to the production
		// inode (a re-processed entry, or a stale -new), writing through it would truncate and corrupt
		// live production; unlinking breaks the link so we write a fresh, independent file.
		if ( 0 === $offset && ( is_file( $dest ) || is_link( $dest ) ) ) {
			@unlink( $dest );
			clearstatcache( true, $dest );
			if ( is_file( $dest ) || is_link( $dest ) ) {
				// The unlink failed and the dest is still there. If it is a hardlink to production, the
				// byte copy below would write THROUGH it and corrupt live production. Fail CLOSED.
				fclose( $in );
				return self::RESULT_ERROR;
			}
		}
		// 'cb' = create/keep, do not truncate. Cut any partial tail past the checkpointed offset, then
		// position both handles at it, so resume is exact and a dead request leaves no stray bytes.
		$out = fopen( $dest, 'cb' );
		if ( false === $out ) {
			fclose( $in );
			return self::RESULT_ERROR;
		}
		if ( ! ftruncate( $out, $offset ) || -1 === fseek( $out, $offset ) || ( $offset > 0 && -1 === fseek( $in, $offset ) ) ) {
			fclose( $in );
			fclose( $out );
			return self::RESULT_ERROR;
		}

		// Read exactly up to the manifest size, never past it: a file that GREW after enumeration must
		// still yield a `$size`-byte copy (snapshot semantics), and a file that SHRANK hits an early
		// EOF which we surface as an error rather than ship a short file. The deadline is checked after
		// each chunk, so every burst makes at least one chunk of progress (no livelock).
		$result = self::RESULT_DONE;
		while ( $offset < $size ) {
			$to_read = (int) min( self::CHUNK_BYTES, $size - $offset );
			$buf     = fread( $in, $to_read );
			if ( false === $buf || '' === $buf ) {
				$result = self::RESULT_ERROR; // Read error or unexpected EOF (source shrank).
				break;
			}
			$written = fwrite( $out, $buf );
			if ( false === $written || $written < strlen( $buf ) ) {
				// Short write = out of space / over quota. Do not advance the offset past what landed.
				$offset += is_int( $written ) ? $written : 0;
				$result  = self::RESULT_ERROR;
				break;
			}
			$offset += $written;
			if ( $offset < $size && microtime( true ) >= $deadline ) {
				$result = self::RESULT_PARTIAL;
				break;
			}
		}

		fflush( $out );
		fclose( $in );
		fclose( $out );

		if ( self::RESULT_DONE !== $result ) {
			return $result;
		}

		// 3. Verify + stamp mode and mtime.
		$mode = (int) $sstat['mode'] & 0777;
		if ( $mode > 0 ) {
			@chmod( $dest, $mode );
		}
		@touch( $dest, $mtime );
		clearstatcache( true, $dest );
		if ( filesize( $dest ) !== $size ) {
			return self::RESULT_ERROR;
		}
		return self::RESULT_DONE;
	}

	/**
	 * Match a newly created destination directory to its source's permission bits while keeping owner
	 * rwx (0700) so files can still be written into it during the copy and sweeps can remove `-old`.
	 *
	 * @param string $src_dir  Absolute source directory.
	 * @param string $dest_dir Absolute destination directory just created.
	 * @return void
	 */
	private static function applyDirModeFromSource( $src_dir, $dest_dir ) {
		clearstatcache( true, $src_dir );
		if ( ! is_dir( $src_dir ) ) {
			return;
		}
		$raw_mode = fileperms( $src_dir ) & 0777;
		if ( $raw_mode > 0 ) {
			@chmod( $dest_dir, $raw_mode | 0700 );
		}
	}

	/**
	 * Whether an unreadable source file may be skipped instead of failing the job. Matches the legacy
	 * worker's IGNORE_PATTERNS=("htaccess.") tolerance for unreadable .htaccess.* backup files.
	 *
	 * @param string $path Absolute source path.
	 * @return bool
	 */
	private static function isIgnorable( $path ) {
		return false !== strpos( basename( $path ), 'htaccess.' );
	}
}
