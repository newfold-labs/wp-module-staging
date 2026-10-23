<?php

namespace NewfoldLabs\WP\Module\Staging;

// Like tests/php/* (excluded in phpcs.xml for the same reason), this is a framework-free filesystem
// harness, not plugin runtime code. It must use raw filesystem primitives the WordPress runtime standard
// discourages -- inode comparisons, symlinks, chmod to drop read/search bits, and silenced probes of
// paths expected to be missing -- none of which WP_Filesystem can express. Only those two sniff families
// are relaxed here; the rest of the standard still applies.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions

use NewfoldLabs\WP\Module\Staging\Copy\CopyEngine;
use NewfoldLabs\WP\Module\Staging\Copy\FileCopier;
use NewfoldLabs\WP\Module\Staging\Copy\CopyCheckpoint;
use NewfoldLabs\WP\Module\Staging\Copy\CopyManifest;

/**
 * Wpunit coverage for the chunked content-copy engine (includes/Copy/*).
 *
 * The engine core is pure PHP and is also exercised by the standalone runner tests/php/test-copy-engine.php
 * and by the bash finalize suites, but those run outside Codeception, so their execution is invisible to the
 * `codecept run wpunit --coverage` harness that gates the PR. This class drives the same four classes
 * (CopyEngine, FileCopier, CopyManifest, CopyCheckpoint) directly against real temporary directories so the
 * engine's lines are attributed to coverage. The behaviours mirror the standalone runner one-to-one: full
 * copy, resume across many tiny bursts, mid-file resume, the deploy hardlink delta, verification, failing
 * closed on an un-walkable tree or a corrupt manifest, non-UTF-8 path round-tripping, and the hardlink
 * staleness contract. ContentCopyController (the WordPress-facing orchestrator) is covered separately by
 * StagingContentCopyWPUnitTest.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\Copy\CopyEngine
 * @covers \NewfoldLabs\WP\Module\Staging\Copy\FileCopier
 * @covers \NewfoldLabs\WP\Module\Staging\Copy\CopyManifest
 * @covers \NewfoldLabs\WP\Module\Staging\Copy\CopyCheckpoint
 */
class StagingCopyEngineWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Per-test scratch root under the system temp dir.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Create a fresh scratch root before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/copy-engine-wpunit-' . getmypid() . '-' . uniqid();
		$this->rmrf( $this->root );
		mkdir( $this->root, 0777, true );
	}

	/**
	 * Remove the scratch root after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->rmrf( $this->root );
		parent::tearDown();
	}

	// --- helpers (mirrors of the standalone runner) -------------------------------------------------

	/**
	 * Recursively remove a path.
	 *
	 * @param string $d Path to remove.
	 * @return void
	 */
	private function rmrf( $d ) {
		if ( ! is_dir( $d ) ) {
			if ( is_file( $d ) || is_link( $d ) ) {
				@chmod( $d, 0777 );
				@unlink( $d );
			}
			return;
		}
		@chmod( $d, 0777 );
		foreach ( array_diff( (array) @scandir( $d ), array( '.', '..' ) ) as $e ) {
			$this->rmrf( "$d/$e" );
		}
		@rmdir( $d );
	}

	/**
	 * Write a file, creating parent directories.
	 *
	 * @param string $p Path.
	 * @param string $c Contents.
	 * @return void
	 */
	private function putf( $p, $c ) {
		@mkdir( dirname( $p ), 0777, true );
		file_put_contents( $p, $c );
	}

	/**
	 * Snapshot a tree as path => sha1 (regular files only, symlinks excluded).
	 *
	 * @param string $r Root.
	 * @return array<string,string>
	 */
	private function snap( $r ) {
		$out = array();
		if ( ! is_dir( $r ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $r, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && ! $f->isLink() ) {
				$out[ substr( $f->getPathname(), strlen( $r ) + 1 ) ] = sha1_file( $f->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Drive an engine to a terminal status.
	 *
	 * @param CopyEngine $e Engine.
	 * @return array<string,mixed>
	 */
	private function drive( CopyEngine $e ) {
		$n = 0;
		do {
			$r = $e->advance();
		} while ( 'running' === $r['status'] && ++$n < 100000 );
		return $r;
	}

	/**
	 * Build a CopyEngine for a scenario.
	 *
	 * @param string      $src    Source root.
	 * @param string      $dst    Dest root.
	 * @param string      $job    Job dir.
	 * @param string      $suffix Dest suffix.
	 * @param string|null $link   Link-dest root.
	 * @param float       $budget Budget seconds.
	 * @return CopyEngine
	 */
	private function engine( $src, $dst, $job, $suffix = '', $link = null, $budget = 10.0 ) {
		return new CopyEngine(
			array(
				'source_root'    => $src,
				'dest_root'      => $dst,
				'dest_suffix'    => $suffix,
				'link_dest_root' => $link,
				'job_dir'        => $job,
				'budget_seconds' => $budget,
			)
		);
	}

	// --- tests --------------------------------------------------------------------------------------

	/**
	 * A full clone copies the tree and skips symlinks.
	 *
	 * @return void
	 */
	public function test_clone_full_copy_skips_symlinks() {
		$src = "{$this->root}/s1/wp-content";
		$dst = "{$this->root}/s1/staging/wp-content";
		$this->putf( "$src/uploads/a.txt", 'AAA' );
		$this->putf( "$src/uploads/2021/b.txt", str_repeat( 'B', 100 ) );
		$this->putf( "$src/themes/t/style.css", 'body{}' );
		$this->putf( "$src/plugins/p/p.php", '<?php' );
		@symlink( "$src/uploads/a.txt", "$src/uploads/link.txt" );

		$r = $this->drive( $this->engine( $src, $dst, "{$this->root}/s1/job" ) );

		$this->assertSame( 'success', $r['status'], 'clone completes' );
		$this->assertSame( $this->snap( $src ), $this->snap( $dst ), 'clone tree matches source' );
		$this->assertFalse(
			is_file( "$dst/uploads/link.txt" ) && ! is_link( "$dst/uploads/link.txt" ),
			'symlink is not materialised into the copy'
		);
	}

	/**
	 * A zero budget forces one unit per burst and a multi-chunk file resumes mid-file.
	 *
	 * @return void
	 */
	public function test_resume_mid_file_across_many_bursts() {
		$src = "{$this->root}/s2/wp-content";
		$dst = "{$this->root}/s2/staging/wp-content";
		$this->putf( "$src/uploads/small.txt", 'hello' );
		$big = str_repeat( '0123456789abcdef', 300000 ); // ~4.6 MB -> multiple 1 MiB chunks.
		$this->putf( "$src/uploads/big.bin", $big );
		$this->putf( "$src/themes/t/a.css", 'x' );

		$e      = $this->engine( $src, $dst, "{$this->root}/s2/job", '', null, 0.0 );
		$n      = 0;
		$bursts = 0;
		do {
			$r = $e->advance();
			++$bursts;
		} while ( 'running' === $r['status'] && ++$n < 100000 );

		$this->assertSame( 'success', $r['status'], 'resume completes' );
		$this->assertGreaterThan( 5, $bursts, 'took multiple bursts (chunked)' );
		$this->assertSame( sha1( $big ), sha1_file( "$dst/uploads/big.bin" ), 'multi-chunk file identical after mid-file resume' );
		$this->assertSame( $this->snap( $src ), $this->snap( $dst ), 'resumed tree matches source' );
	}

	/**
	 * A large flat directory enumerates across many bursts without duplicating manifest entries.
	 *
	 * @return void
	 */
	public function test_large_flat_dir_enumerates_without_duplicating_entries() {
		$src = "{$this->root}/ser7/wp-content";
		$dst = "{$this->root}/ser7/staging/wp-content";
		$job = "{$this->root}/ser7/job";
		for ( $i = 0; $i < 60; $i++ ) {
			$this->putf( sprintf( '%s/uploads/f%03d.txt', $src, $i ), "data-$i" );
		}

		$e      = $this->engine( $src, $dst, $job, '', null, 0.0 );
		$n      = 0;
		$bursts = 0;
		do {
			$r = $e->advance();
			++$bursts;
		} while ( 'running' === $r['status'] && ++$n < 100000 );

		$this->assertSame( 'success', $r['status'], 'large flat dir completes' );
		$this->assertSame( $this->snap( $src ), $this->snap( $dst ), 'nothing dropped' );
		$manifest_lines = count( array_filter( explode( "\n", (string) @file_get_contents( "$job/manifest.list" ) ), 'strlen' ) );
		$this->assertSame( 60, $manifest_lines, 'exactly one manifest entry per file (no unbounded re-append)' );
		$this->assertGreaterThan( 60, $bursts, 'per-entry bursts, not one whole-dir burst' );
	}

	/**
	 * A corrupt (non-empty, unparseable) manifest line fails the job closed.
	 *
	 * @return void
	 */
	public function test_corrupt_manifest_line_fails_closed() {
		$src = "{$this->root}/m11/wp-content";
		$dst = "{$this->root}/m11/staging/wp-content";
		$job = "{$this->root}/m11/job";
		$this->putf( "$src/uploads/a.txt", 'A' );
		@mkdir( $job, 0777, true );
		file_put_contents( "$job/manifest.list", "uploads/a.txt\t1\t123\nthis-line-has-no-tabs-and-cannot-parse\n" );
		$cp = new CopyCheckpoint( "$job/cursor.json" );
		$cp->set(
			array(
				'job_id'          => 'x',
				'phase'           => CopyCheckpoint::PHASE_COPY,
				'walk_complete'   => true,
				'manifest_offset' => 0,
			)
		);
		$cp->save();

		$r = $this->drive( $this->engine( $src, $dst, $job ) );

		$this->assertSame( 'error', $r['status'], 'corrupt manifest line -> job errors (fail closed)' );
	}

	/**
	 * A deploy hardlinks unchanged files against production and copies changed/new ones.
	 *
	 * @return void
	 */
	public function test_deploy_hardlinks_unchanged_copies_changed() {
		$prod = "{$this->root}/s3/wp-content";
		$stg  = "{$this->root}/s3/staging/wp-content";
		$mt   = 1700000000;
		$this->putf( "$prod/uploads/same.jpg", 'IMAGE-UNCHANGED' );
		touch( "$prod/uploads/same.jpg", $mt );
		$this->putf( "$prod/uploads/changed.jpg", 'OLD-VERSION' );
		$this->putf( "$stg/uploads/same.jpg", 'IMAGE-UNCHANGED' );
		touch( "$stg/uploads/same.jpg", $mt );
		$this->putf( "$stg/uploads/changed.jpg", 'NEW-VERSION-LONGER' );
		$this->putf( "$stg/uploads/new.jpg", 'BRAND-NEW' );

		$r = $this->drive( $this->engine( $stg, $prod, "{$this->root}/s3/job", '-new', $prod ) );

		$this->assertSame( 'success', $r['status'], 'deploy prepare completes' );
		clearstatcache();
		$this->assertSame(
			fileinode( "$prod/uploads/same.jpg" ),
			fileinode( "$prod/uploads-new/same.jpg" ),
			'unchanged file hardlinked into -new (same inode)'
		);
		$this->assertSame( 'NEW-VERSION-LONGER', file_get_contents( "$prod/uploads-new/changed.jpg" ), 'changed file carries staging content' );
		$this->assertNotSame(
			fileinode( "$prod/uploads/changed.jpg" ),
			fileinode( "$prod/uploads-new/changed.jpg" ),
			'changed file is not hardlinked (own inode)'
		);
		$this->assertSame( 'BRAND-NEW', file_get_contents( "$prod/uploads-new/new.jpg" ), 'new file present in -new' );
	}

	/**
	 * FileCopier skips a vanished source when the tree is intact and re-snapshots a changed source.
	 *
	 * @return void
	 */
	public function test_filecopier_skip_vanished_and_resnapshot_changed() {
		$off = 0;
		$this->assertSame(
			FileCopier::RESULT_SKIP,
			FileCopier::copyOne( "{$this->root}/nope", "{$this->root}/s4dst", 5, 123, null, $off, microtime( true ) + 5, "{$this->root}" ),
			'missing source (parent + root present) returns SKIP'
		);

		$this->putf( "{$this->root}/s6/src.bin", '12345' );
		$off = 0;
		$this->assertSame(
			FileCopier::RESULT_DONE,
			FileCopier::copyOne( "{$this->root}/s6/src.bin", "{$this->root}/s6/dst.bin", 99, 123, null, $off, microtime( true ) + 5, "{$this->root}/s6" ),
			'changed source re-snapshots and completes'
		);
		$this->assertSame( '12345', (string) @file_get_contents( "{$this->root}/s6/dst.bin" ), 'dest has the real current content' );
	}

	/**
	 * A vanished source whose tree is gone fails closed, never skips (which would delete from production).
	 *
	 * @return void
	 */
	public function test_filecopier_vanished_source_missing_tree_fails_closed() {
		$off = 0;
		$this->assertSame(
			FileCopier::RESULT_ERROR,
			FileCopier::copyOne( "{$this->root}/gone/wp-content/uploads/x.txt", "{$this->root}/s7dst", 5, 123, null, $off, microtime( true ) + 5, "{$this->root}/gone/wp-content" ),
			'vanished source + MISSING content root returns ERROR'
		);

		mkdir( "{$this->root}/s7/wp-content", 0777, true );
		$off = 0;
		$this->assertSame(
			FileCopier::RESULT_ERROR,
			FileCopier::copyOne( "{$this->root}/s7/wp-content/uploads/gone/x.txt", "{$this->root}/s7dst2", 5, 123, null, $off, microtime( true ) + 5, "{$this->root}/s7/wp-content" ),
			'vanished source + MISSING parent subtree (root present) returns ERROR'
		);
	}

	/**
	 * A partial manifest with no cursor reseeds and copies the full tree, never the partial one.
	 *
	 * @return void
	 */
	public function test_partial_manifest_without_cursor_reseeds() {
		$src = "{$this->root}/c1/wp-content";
		$dst = "{$this->root}/c1/staging/wp-content";
		$job = "{$this->root}/c1/job";
		foreach ( array(
			'uploads/a.txt'   => 'A',
			'uploads/b.txt'   => 'B',
			'uploads/c.txt'   => 'C',
			'themes/t/s.css'  => 'S',
			'plugins/p/p.php' => 'P',
		) as $p => $c ) {
			$this->putf( "$src/$p", $c );
		}
		@mkdir( $job, 0777, true );
		file_put_contents( "$job/manifest.list", "uploads/a.txt\t1\t123\n" ); // partial: 1 of 5, and NO cursor.json.

		$r = $this->drive( $this->engine( $src, $dst, $job ) );

		$this->assertSame( 'success', $r['status'], 'completes' );
		$this->assertSame( $this->snap( $src ), $this->snap( $dst ), 'reseeded and copied the FULL tree' );
	}

	/**
	 * The checkpoint base64-round-trips non-UTF-8 paths.
	 *
	 * @return void
	 */
	public function test_checkpoint_round_trips_non_utf8_paths() {
		$cpfile = "{$this->root}/cp/cursor.json";
		@mkdir( dirname( $cpfile ), 0777, true );
		$weird = 'uploads/caf' . chr( 0xE9 ) . '/x'; // invalid UTF-8 (latin-1 'é').
		$cp    = new CopyCheckpoint( $cpfile );
		$cp->set(
			array(
				'pending_dirs' => array( $weird ),
				'current_file' => $weird,
			)
		);
		$cp->save();

		$cp2 = new CopyCheckpoint( $cpfile );
		$cp2->load();
		$this->assertSame( array( $weird ), $cp2->get( 'pending_dirs' ), 'pending_dirs round-trips non-UTF-8' );
		$this->assertSame( $weird, $cp2->get( 'current_file' ), 'current_file round-trips non-UTF-8' );
	}

	/**
	 * Name-based walk resume does not skip entries when the directory mutates between bursts.
	 *
	 * @return void
	 */
	public function test_walk_resume_is_name_based_not_index_based() {
		$src = "{$this->root}/wk/wp-content";
		$job = "{$this->root}/wk/job";
		@mkdir( $job, 0777, true );
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $wn ) {
			$this->putf( "$src/uploads/$wn.txt", strtoupper( $wn ) );
		}
		$mani = new CopyManifest( "$job/manifest.list" );
		file_put_contents( "$job/manifest.list", '' );
		$cpw = new CopyCheckpoint( "$job/cursor.json" );
		$cpw->set( array( 'pending_dirs' => array( 'uploads' ) ) );

		// Three one-entry bursts (deadline already past stops after each processed entry).
		$mani->walk( $src, $cpw, microtime( true ) - 1 );
		$mani->walk( $src, $cpw, microtime( true ) - 1 );
		$mani->walk( $src, $cpw, microtime( true ) - 1 );

		// Mutate: delete the just-processed 'a.txt' AND the not-yet-processed 'b.txt'. An index resume
		// would now skip 'c.txt'; a name resume (after 'a.txt') must still reach c/d/e.
		@unlink( "$src/uploads/a.txt" );
		@unlink( "$src/uploads/b.txt" );

		$guard = 0;
		do {
			$st = $mani->walk( $src, $cpw, microtime( true ) + 5 );
		} while ( CopyManifest::WALK_MORE === $st && ++$guard < 1000 );

		$this->assertSame( CopyManifest::WALK_DONE, $st, 'walk completes' );
		$names = array();
		foreach ( file( "$job/manifest.list" ) as $line ) {
			$e = CopyManifest::parseLine( $line );
			if ( $e ) {
				$names[ $e['path'] ] = true;
			}
		}
		$this->assertArrayHasKey( 'uploads/c.txt', $names, 'c.txt NOT skipped despite earlier deletion' );
		$this->assertArrayHasKey( 'uploads/d.txt', $names, 'd.txt present' );
		$this->assertArrayHasKey( 'uploads/e.txt', $names, 'e.txt present' );
	}

	/**
	 * A byte copy never writes through a hardlink into production.
	 *
	 * @return void
	 */
	public function test_byte_copy_never_writes_through_a_hardlink() {
		$base = "{$this->root}/s1b";
		$this->putf( "$base/prod.txt", 'PRODUCTION-ORIGINAL' );
		$destlink = "$base/uploads-new.txt";
		link( "$base/prod.txt", $destlink ); // dest is a hardlink sharing production's inode.
		$this->putf( "$base/staging.txt", 'STAGING-NEW-CONTENT' );
		touch( "$base/staging.txt", 123 );

		$off = 0;
		$res = FileCopier::copyOne( "$base/staging.txt", $destlink, strlen( 'STAGING-NEW-CONTENT' ), 123, null, $off, microtime( true ) + 5, $base );

		$this->assertSame( FileCopier::RESULT_DONE, $res, 'copy completes' );
		$this->assertSame( 'STAGING-NEW-CONTENT', file_get_contents( $destlink ), 'dest got staging content' );
		$this->assertSame( 'PRODUCTION-ORIGINAL', file_get_contents( "$base/prod.txt" ), 'production inode NOT corrupted' );
	}

	/**
	 * .git directories are excluded from the copy.
	 *
	 * @return void
	 */
	public function test_git_directories_excluded() {
		$src = "{$this->root}/g/wp-content";
		$dst = "{$this->root}/g/staging/wp-content";
		$this->putf( "$src/plugins/p/.git/config", 'gitdata' );
		$this->putf( "$src/plugins/p/real.php", '<?php' );

		$r = $this->drive( $this->engine( $src, $dst, "{$this->root}/g/job" ) );

		$this->assertSame( 'success', $r['status'], 'completes' );
		$this->assertTrue( is_file( "$dst/plugins/p/real.php" ), 'real file copied' );
		$this->assertFalse( is_dir( "$dst/plugins/p/.git" ), '.git excluded' );
	}

	/**
	 * The hardlink fast path ships a source edited after the walk as its current content, not the stale one.
	 *
	 * @return void
	 */
	public function test_hardlink_staleness_ships_current_content() {
		$root = "{$this->root}/hl";
		$src  = "$root/wp-content";
		$prod = "$root/prod/wp-content";

		$stale_size  = 3;
		$stale_mtime = 1600000000;

		$this->putf( "$prod/uploads/edited.txt", 'OLD' );
		touch( "$prod/uploads/edited.txt", $stale_mtime );
		$this->putf( "$src/uploads/edited.txt", 'EDITED AFTER THE WALK' );
		touch( "$src/uploads/edited.txt", 1600000500 );

		$off = 0;
		$res = FileCopier::copyOne(
			"$src/uploads/edited.txt",
			"$prod/uploads-new/edited.txt",
			$stale_size,
			$stale_mtime,
			"$prod/uploads/edited.txt",
			$off,
			microtime( true ) + 30,
			$src
		);

		$this->assertSame( FileCopier::RESULT_DONE, $res, 'the copy reports done' );
		$this->assertSame( 'EDITED AFTER THE WALK', (string) @file_get_contents( "$prod/uploads-new/edited.txt" ), 'ships CURRENT content' );
		$this->assertNotSame(
			@fileinode( "$prod/uploads-new/edited.txt" ),
			@fileinode( "$prod/uploads/edited.txt" ),
			'not hardlinked to the stale production copy'
		);

		// A genuinely-unchanged file must still be hardlinked, or the delta fast path is disabled.
		$this->putf( "$prod/uploads/same.txt", 'SAME' );
		$this->putf( "$src/uploads/same.txt", 'SAME' );
		touch( "$prod/uploads/same.txt", $stale_mtime );
		touch( "$src/uploads/same.txt", $stale_mtime );

		$off = 0;
		$res = FileCopier::copyOne(
			"$src/uploads/same.txt",
			"$prod/uploads-new/same.txt",
			4,
			$stale_mtime,
			"$prod/uploads/same.txt",
			$off,
			microtime( true ) + 30,
			$src
		);

		$this->assertSame( FileCopier::RESULT_DONE, $res, 'the copy reports done' );
		$this->assertSame(
			@fileinode( "$prod/uploads-new/same.txt" ),
			@fileinode( "$prod/uploads/same.txt" ),
			'an unchanged file is still hardlinked (no byte copy)'
		);
	}

	/**
	 * An unreadable directory fails the job closed (skipped where the runner is root and cannot drop perms).
	 *
	 * @return void
	 */
	public function test_unreadable_directory_fails_closed() {
		$src = "{$this->root}/fc/wp-content";
		$dst = "{$this->root}/fc/staging/wp-content";
		$job = "{$this->root}/fc/job";
		$this->putf( "$src/uploads/ok.txt", 'OK' );
		mkdir( "$src/uploads/secret", 0777, true );
		$this->putf( "$src/uploads/secret/s.txt", 'S' );
		@chmod( "$src/uploads/secret", 0000 );
		clearstatcache();

		if ( is_readable( "$src/uploads/secret" ) ) {
			@chmod( "$src/uploads/secret", 0755 );
			$this->markTestSkipped( 'cannot drop read perms (running as root)' );
			return;
		}

		$r = $this->drive( $this->engine( $src, $dst, $job ) );
		@chmod( "$src/uploads/secret", 0755 );
		$this->assertSame( 'error', $r['status'], 'unreadable dir -> job errors (fail closed)' );
	}

	/**
	 * A readable-but-not-searchable dir fails closed so its subtree is not silently dropped then deleted.
	 *
	 * @return void
	 */
	public function test_not_searchable_directory_fails_closed() {
		$src = "{$this->root}/n1/wp-content";
		$dst = "{$this->root}/n1/staging/wp-content";
		$job = "{$this->root}/n1/job";
		$this->putf( "$src/uploads/ok.txt", 'OK' );
		mkdir( "$src/uploads/locked", 0777, true );
		$this->putf( "$src/uploads/locked/inner.txt", 'INNER' );
		@chmod( "$src/uploads/locked", 0644 );
		clearstatcache();

		if ( false !== @stat( "$src/uploads/locked/inner.txt" ) ) {
			@chmod( "$src/uploads/locked", 0755 );
			$this->markTestSkipped( 'cannot drop search perms (running as root)' );
			return;
		}

		$r = $this->drive( $this->engine( $src, $dst, $job ) );
		@chmod( "$src/uploads/locked", 0755 );
		$this->assertSame( 'error', $r['status'], 'not-searchable dir -> job errors (fail closed, subtree not dropped)' );
	}
}
