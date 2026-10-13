<?php
/**
 * Standalone unit tests for the chunked content-copy engine (includes/Copy/*).
 *
 * The engine core is pure PHP (no WordPress), so these run with a plain `php` and need no database,
 * wp-cli, or wp-env -- the same spirit as tests/bash/*. They cover the behaviours that matter for not
 * losing or corrupting a customer's files: full copy, resume across many tiny bursts, mid-file resume
 * of a file bigger than one budget, the deploy hardlink-delta, skipping a vanished source, and failing
 * verification on a wrong size.
 *
 * Run: php tests/php/test-copy-engine.php   (or: composer test:php)
 *
 * @package NewfoldLabs\WP\Module\Staging
 */

error_reporting( E_ALL );

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; } // phpcs:ignore
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d ) { return json_encode( $d ); } // phpcs:ignore
}

$inc = dirname( __DIR__, 2 ) . '/includes/Copy/';
require $inc . 'CopyCheckpoint.php';
require $inc . 'CopyManifest.php';
require $inc . 'FileCopier.php';
require $inc . 'CopyEngine.php';

use NewfoldLabs\WP\Module\Staging\Copy\CopyEngine;
use NewfoldLabs\WP\Module\Staging\Copy\FileCopier;
use NewfoldLabs\WP\Module\Staging\Copy\CopyCheckpoint;
use NewfoldLabs\WP\Module\Staging\Copy\CopyManifest;

$PASS = 0;
$FAIL = 0;
function ok( $m ) {
	global $PASS;
	++$PASS;
	printf( "  ok   - %s\n", $m ); }
function no( $m, $d = '' ) {
	global $FAIL;
	++$FAIL;
	printf( "  FAIL - %s\n", $m );
	if ( '' !== $d ) { printf( "         %s\n", $d ); } }
function eq( $m, $a, $b ) {
	if ( $a === $b ) { ok( $m );
	} else { no( $m, 'expected [' . var_export( $b, true ) . '], got [' . var_export( $a, true ) . ']' ); } }

function rmrf( $d ) {
	if ( ! is_dir( $d ) ) { if ( is_file( $d ) || is_link( $d ) ) { @unlink( $d );
	} return; }
	foreach ( array_diff( scandir( $d ), array( '.', '..' ) ) as $e ) { rmrf( "$d/$e" ); }
	@rmdir( $d );
}
function putf( $p, $c ) {
	@mkdir( dirname( $p ), 0777, true );
	file_put_contents( $p, $c ); }
function snap( $root ) {
	$out = array();
	if ( ! is_dir( $root ) ) { return $out; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && ! $f->isLink() ) { $out[ substr( $f->getPathname(), strlen( $root ) + 1 ) ] = sha1_file( $f->getPathname() ); }
	}
	ksort( $out );
	return $out;
}
function drive( CopyEngine $e ) {
	$n = 0;
	do { $r = $e->advance();
	} while ( 'running' === $r['status'] && ++$n < 100000 );
	return $r; }

$ROOT = sys_get_temp_dir() . '/copy-engine-test-' . getmypid();
rmrf( $ROOT );
mkdir( $ROOT, 0777, true );

echo "== clone: full copy, symlinks skipped ==\n";
$src = "$ROOT/s1/wp-content";
$dst = "$ROOT/s1/staging/wp-content";
putf( "$src/uploads/a.txt", 'AAA' );
putf( "$src/uploads/2021/b.txt", str_repeat( 'B', 100 ) );
putf( "$src/themes/t/style.css", 'body{}' );
putf( "$src/plugins/p/p.php", '<?php' );
@symlink( "$src/uploads/a.txt", "$src/uploads/link.txt" );
$r = drive(
	new CopyEngine(
		array(
			'source_root'    => $src,
			'dest_root'      => $dst,
			'dest_suffix'    => '',
			'link_dest_root' => null,
			'job_dir'        => "$ROOT/s1/job",
			'budget_seconds' => 10,
		)
	)
);
eq( 'clone completes', $r['status'], 'success' );
eq( 'clone tree matches source', snap( $dst ), snap( $src ) );
eq( 'symlink not copied', ( is_file( "$dst/uploads/link.txt" ) && ! is_link( "$dst/uploads/link.txt" ) ) ? 'copied' : 'skipped', 'skipped' );

echo "== resume: budget=0 forces 1 unit/burst, multi-chunk file resumes mid-file ==\n";
$src2 = "$ROOT/s2/wp-content";
$dst2 = "$ROOT/s2/staging/wp-content";
putf( "$src2/uploads/small.txt", 'hello' );
$big = str_repeat( '0123456789abcdef', 300000 ); // ~4.6 MB -> multiple 1 MiB chunks.
putf( "$src2/uploads/big.bin", $big );
putf( "$src2/themes/t/a.css", 'x' );
$e2     = new CopyEngine(
	array(
		'source_root'    => $src2,
		'dest_root'      => $dst2,
		'dest_suffix'    => '',
		'link_dest_root' => null,
		'job_dir'        => "$ROOT/s2/job",
		'budget_seconds' => 0.0,
	)
);
$n      = 0;
$bursts = 0;
do { $r = $e2->advance();
	++$bursts;
} while ( 'running' === $r['status'] && ++$n < 100000 );
eq( 'resume completes', $r['status'], 'success' );
eq( 'took multiple bursts (chunked)', $bursts > 5 ? 'many' : "few($bursts)", 'many' );
eq( 'multi-chunk file identical after mid-file resume', sha1_file( "$dst2/uploads/big.bin" ), sha1( $big ) );
eq( 'resumed tree matches source', snap( $dst2 ), snap( $src2 ) );

echo "== SER7: a large flat dir enumerates across many bursts without duplicating manifest entries ==\n";
$s7src = "$ROOT/ser7/wp-content"; $s7dst = "$ROOT/ser7/staging/wp-content"; $s7job = "$ROOT/ser7/job";
for ( $i = 0; $i < 60; $i++ ) { putf( sprintf( '%s/uploads/f%03d.txt', $s7src, $i ), "data-$i" ); }
$e7     = new CopyEngine( array( 'source_root' => $s7src, 'dest_root' => $s7dst, 'dest_suffix' => '', 'link_dest_root' => null, 'job_dir' => $s7job, 'budget_seconds' => 0.0 ) );
$n      = 0;
$bursts = 0;
do { $r = $e7->advance();
	++$bursts;
} while ( 'running' === $r['status'] && ++$n < 100000 );
eq( 'SER7 large flat dir completes', $r['status'], 'success' );
eq( 'SER7 resumed tree matches source (nothing dropped)', snap( $s7dst ), snap( $s7src ) );
$manifest_lines = count( array_filter( explode( "\n", (string) @file_get_contents( "$s7job/manifest.list" ) ), 'strlen' ) );
eq( 'SER7 manifest has exactly one entry per file (no unbounded re-append)', $manifest_lines, 60 );
eq( 'SER7 took per-entry bursts, not one whole-dir burst', $bursts > 60 ? 'many' : "few($bursts)", 'many' );

echo "== M11: a corrupt (non-empty, unparseable) manifest line fails the job CLOSED ==\n";
$m11src = "$ROOT/m11/wp-content"; $m11dst = "$ROOT/m11/staging/wp-content"; $m11job = "$ROOT/m11/job";
putf( "$m11src/uploads/a.txt", 'A' );
@mkdir( $m11job, 0777, true );
file_put_contents( "$m11job/manifest.list", "uploads/a.txt\t1\t123\nthis-line-has-no-tabs-and-cannot-parse\n" );
$cp11 = new CopyCheckpoint( "$m11job/cursor.json" );
$cp11->set( array( 'job_id' => 'x', 'phase' => CopyCheckpoint::PHASE_COPY, 'walk_complete' => true, 'manifest_offset' => 0 ) );
$cp11->save();
$r = drive( new CopyEngine( array( 'source_root' => $m11src, 'dest_root' => $m11dst, 'dest_suffix' => '', 'link_dest_root' => null, 'job_dir' => $m11job, 'budget_seconds' => 10 ) ) );
eq( 'M11 corrupt manifest line -> job errors (fail closed)', $r['status'], 'error' );

echo "== deploy: unchanged files hardlink against production, changed/new copy ==\n";
$prod = "$ROOT/s3/wp-content";
$stg  = "$ROOT/s3/staging/wp-content";
$mt   = 1700000000;
putf( "$prod/uploads/same.jpg", 'IMAGE-UNCHANGED' );
touch( "$prod/uploads/same.jpg", $mt );
putf( "$prod/uploads/changed.jpg", 'OLD-VERSION' );
putf( "$stg/uploads/same.jpg", 'IMAGE-UNCHANGED' );
touch( "$stg/uploads/same.jpg", $mt );
putf( "$stg/uploads/changed.jpg", 'NEW-VERSION-LONGER' );
putf( "$stg/uploads/new.jpg", 'BRAND-NEW' );
$r = drive(
	new CopyEngine(
		array(
			'source_root'    => $stg,
			'dest_root'      => $prod,
			'dest_suffix'    => '-new',
			'link_dest_root' => $prod,
			'job_dir'        => "$ROOT/s3/job",
			'budget_seconds' => 10,
		)
	)
);
eq( 'deploy prepare completes', $r['status'], 'success' );
clearstatcache();
eq( 'unchanged file hardlinked into -new (same inode)', fileinode( "$prod/uploads/same.jpg" ) === fileinode( "$prod/uploads-new/same.jpg" ) ? 'linked' : 'copied', 'linked' );
eq( 'changed file carries staging content', file_get_contents( "$prod/uploads-new/changed.jpg" ), 'NEW-VERSION-LONGER' );
eq( 'changed file not hardlinked (own inode)', fileinode( "$prod/uploads/changed.jpg" ) === fileinode( "$prod/uploads-new/changed.jpg" ) ? 'linked' : 'separate', 'separate' );
eq( 'new file present in -new', file_get_contents( "$prod/uploads-new/new.jpg" ), 'BRAND-NEW' );

echo "== FileCopier: vanished source skipped; wrong size fails verify ==\n";
$off = 0;
eq( 'missing source (parent + root present) returns SKIP', FileCopier::copyOne( "$ROOT/nope", "$ROOT/s4dst", 5, 123, null, $off, microtime( true ) + 5, "$ROOT" ), FileCopier::RESULT_SKIP );
// A source whose real size/mtime differ from the manifest (changed since enumeration) is re-snapshotted
// to its current content and COMPLETES, rather than shipping a frankenfile or failing the deploy forever.
putf( "$ROOT/s6/src.bin", '12345' );
$off = 0;
eq( 'changed source re-snapshots and completes', FileCopier::copyOne( "$ROOT/s6/src.bin", "$ROOT/s6/dst.bin", 99, 123, null, $off, microtime( true ) + 5, "$ROOT/s6" ), FileCopier::RESULT_DONE );
eq( 'changed source dest has the real current content', @file_get_contents( "$ROOT/s6/dst.bin" ), '12345' );

echo "== CAT2: a vanished source with a missing tree fails CLOSED (not skipped -> not deleted from prod) ==\n";
// Root gone entirely (e.g. a concurrent clone rm -rf'd staging mid-copy): must ERROR, never SKIP.
$off = 0;
eq( 'CAT2 vanished source + MISSING content root returns ERROR', FileCopier::copyOne( "$ROOT/gone/wp-content/uploads/x.txt", "$ROOT/s7dst", 5, 123, null, $off, microtime( true ) + 5, "$ROOT/gone/wp-content" ), FileCopier::RESULT_ERROR );
// Root present but the file's own subtree removed: still ERROR (distinguishes the parent-dir branch).
mkdir( "$ROOT/s7/wp-content", 0777, true );
$off = 0;
eq( 'CAT2 vanished source + MISSING parent subtree (root present) returns ERROR', FileCopier::copyOne( "$ROOT/s7/wp-content/uploads/gone/x.txt", "$ROOT/s7dst2", 5, 123, null, $off, microtime( true ) + 5, "$ROOT/s7/wp-content" ), FileCopier::RESULT_ERROR );

echo "== C1: a partial manifest with no cursor reseeds; never copies a partial tree ==\n";
$c1src = "$ROOT/c1/wp-content"; $c1dst = "$ROOT/c1/staging/wp-content"; $c1job = "$ROOT/c1/job";
foreach ( array( 'uploads/a.txt' => 'A', 'uploads/b.txt' => 'B', 'uploads/c.txt' => 'C', 'themes/t/s.css' => 'S', 'plugins/p/p.php' => 'P' ) as $p => $c ) { putf( "$c1src/$p", $c ); }
@mkdir( $c1job, 0777, true );
file_put_contents( "$c1job/manifest.list", "uploads/a.txt\t1\t123\n" ); // partial: 1 of 5 files, and NO cursor.json
$r = drive( new CopyEngine( array( 'source_root' => $c1src, 'dest_root' => $c1dst, 'dest_suffix' => '', 'link_dest_root' => null, 'job_dir' => $c1job, 'budget_seconds' => 10 ) ) );
eq( 'C1 completes', $r['status'], 'success' );
eq( 'C1 reseeded and copied the FULL tree (not the 1-line partial manifest)', snap( $c1dst ), snap( $c1src ) );

echo "== C2: checkpoint base64 round-trips non-UTF-8 paths ==\n";
$cpfile = "$ROOT/cp/cursor.json"; @mkdir( dirname( $cpfile ), 0777, true );
$weird  = 'uploads/caf' . chr( 0xE9 ) . '/x'; // invalid UTF-8 (latin-1 'é')
$cp     = new CopyCheckpoint( $cpfile );
$cp->set( array( 'pending_dirs' => array( $weird ), 'current_file' => $weird ) );
$cp->save();
$cp2 = new CopyCheckpoint( $cpfile ); $cp2->load();
eq( 'C2 pending_dirs round-trips non-UTF-8', $cp2->get( 'pending_dirs' ), array( $weird ) );
eq( 'C2 current_file round-trips non-UTF-8', $cp2->get( 'current_file' ), $weird );

echo "== C2: an unreadable directory fails the job CLOSED (not silently dropped) ==\n";
$fcsrc = "$ROOT/fc/wp-content"; $fcdst = "$ROOT/fc/staging/wp-content"; $fcjob = "$ROOT/fc/job";
putf( "$fcsrc/uploads/ok.txt", 'OK' );
mkdir( "$fcsrc/uploads/secret", 0777, true ); putf( "$fcsrc/uploads/secret/s.txt", 'S' );
@chmod( "$fcsrc/uploads/secret", 0000 );
clearstatcache();
if ( is_readable( "$fcsrc/uploads/secret" ) ) {
	ok( 'C2 unreadable-dir test skipped (running as root; cannot drop read perms)' );
} else {
	$r = drive( new CopyEngine( array( 'source_root' => $fcsrc, 'dest_root' => $fcdst, 'dest_suffix' => '', 'link_dest_root' => null, 'job_dir' => $fcjob, 'budget_seconds' => 10 ) ) );
	eq( 'C2 unreadable dir -> job errors (fail closed)', $r['status'], 'error' );
}
@chmod( "$fcsrc/uploads/secret", 0755 ); // restore for cleanup

echo "== CAT1: a readable-but-not-searchable dir fails CLOSED (subtree not silently dropped) ==\n";
// mode 0644: scandir can LIST the children (read bit) but every child is un-stattable (no search bit).
// Before the lstat guard, those children fell through the non-regular-file `continue` and were dropped
// from the manifest -- then DELETED from production by the finalize swap. Must fail the job instead.
$n1src = "$ROOT/n1/wp-content"; $n1dst = "$ROOT/n1/staging/wp-content"; $n1job = "$ROOT/n1/job";
putf( "$n1src/uploads/ok.txt", 'OK' );
mkdir( "$n1src/uploads/locked", 0777, true ); putf( "$n1src/uploads/locked/inner.txt", 'INNER' );
@chmod( "$n1src/uploads/locked", 0644 );
clearstatcache();
if ( false !== @stat( "$n1src/uploads/locked/inner.txt" ) ) {
	ok( 'CAT1 not-searchable-dir test skipped (running as root; cannot drop search perms)' );
} else {
	$r = drive( new CopyEngine( array( 'source_root' => $n1src, 'dest_root' => $n1dst, 'dest_suffix' => '', 'link_dest_root' => null, 'job_dir' => $n1job, 'budget_seconds' => 10 ) ) );
	eq( 'CAT1 not-searchable dir -> job errors (fail closed, subtree not dropped)', $r['status'], 'error' );
}
@chmod( "$n1src/uploads/locked", 0755 ); // restore for cleanup

echo "== CAT1b: name-based walk resume does NOT skip entries when the directory mutates between bursts ==\n";
// Regression guard for position-based resume: if a burst stops mid-directory and an already-processed
// (earlier-sorted) entry is deleted before the next burst, an index-based resume would point past a
// still-present entry and SKIP it -> finalize would then delete it from production. Name-based resume
// (resume strictly after the last processed NAME) is immune.
$wsrc = "$ROOT/wk/wp-content"; $wjob = "$ROOT/wk/job"; @mkdir( $wjob, 0777, true );
foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $wn ) { putf( "$wsrc/uploads/$wn.txt", strtoupper( $wn ) ); }
$mani = new CopyManifest( "$wjob/manifest.list" );
file_put_contents( "$wjob/manifest.list", '' );
$cpw = new CopyCheckpoint( "$wjob/cursor.json" );
$cpw->set( array( 'pending_dirs' => array( 'uploads' ) ) );
// Three one-entry bursts (a past deadline stops after each processed entry): '.', '..', then 'a.txt'.
$mani->walk( $wsrc, $cpw, microtime( true ) - 1 );
$mani->walk( $wsrc, $cpw, microtime( true ) - 1 );
$mani->walk( $wsrc, $cpw, microtime( true ) - 1 );
// Mutate the directory: delete the just-processed 'a.txt' AND the not-yet-processed 'b.txt'. An index
// resume would now skip 'c.txt'; a name resume (after 'a.txt') must still reach c/d/e.
@unlink( "$wsrc/uploads/a.txt" );
@unlink( "$wsrc/uploads/b.txt" );
$wguard = 0;
do { $wst = $mani->walk( $wsrc, $cpw, microtime( true ) + 5 ); } while ( CopyManifest::WALK_MORE === $wst && ++$wguard < 1000 );
eq( 'CAT1b walk completes', $wst, CopyManifest::WALK_DONE );
$wnames = array();
foreach ( file( "$wjob/manifest.list" ) as $wline ) { $we = CopyManifest::parseLine( $wline ); if ( $we ) { $wnames[ $we['path'] ] = true; } }
eq( 'CAT1b c.txt NOT skipped despite earlier deletion', isset( $wnames['uploads/c.txt'] ) ? 'present' : 'SKIPPED', 'present' );
eq( 'CAT1b d.txt present', isset( $wnames['uploads/d.txt'] ) ? 'present' : 'SKIPPED', 'present' );
eq( 'CAT1b e.txt present', isset( $wnames['uploads/e.txt'] ) ? 'present' : 'SKIPPED', 'present' );

echo "== S1: a byte copy never writes through a hardlink into production ==\n";
putf( "$ROOT/s1/prod.txt", 'PRODUCTION-ORIGINAL' );
$destlink = "$ROOT/s1/uploads-new.txt";
link( "$ROOT/s1/prod.txt", $destlink ); // dest is a hardlink sharing production's inode
putf( "$ROOT/s1/staging.txt", 'STAGING-NEW-CONTENT' );
touch( "$ROOT/s1/staging.txt", 123 ); // Manifest size+mtime must match the source (the walk records real values).
$off = 0;
$res = FileCopier::copyOne( "$ROOT/s1/staging.txt", $destlink, strlen( 'STAGING-NEW-CONTENT' ), 123, null, $off, microtime( true ) + 5, "$ROOT/s1" );
eq( 'S1 copy completes', $res, FileCopier::RESULT_DONE );
eq( 'S1 dest got staging content', file_get_contents( $destlink ), 'STAGING-NEW-CONTENT' );
eq( 'S1 production inode NOT corrupted', file_get_contents( "$ROOT/s1/prod.txt" ), 'PRODUCTION-ORIGINAL' );

echo "== .git directories are excluded from the copy ==\n";
$gsrc = "$ROOT/g/wp-content"; $gdst = "$ROOT/g/staging/wp-content"; $gjob = "$ROOT/g/job";
putf( "$gsrc/plugins/p/.git/config", 'gitdata' );
putf( "$gsrc/plugins/p/real.php", '<?php' );
$r = drive( new CopyEngine( array( 'source_root' => $gsrc, 'dest_root' => $gdst, 'dest_suffix' => '', 'link_dest_root' => null, 'job_dir' => $gjob, 'budget_seconds' => 10 ) ) );
eq( '.git excluded; real file copied', ( is_file( "$gdst/plugins/p/real.php" ) && ! is_dir( "$gdst/plugins/p/.git" ) ) ? 'ok' : 'bad', 'ok' );

rmrf( $ROOT );
echo "\n-----------------------------------------\n";
printf( "copy-engine tests: %d passed, %d failed\n", $PASS, $FAIL );
exit( 0 === $FAIL ? 0 : 1 );
