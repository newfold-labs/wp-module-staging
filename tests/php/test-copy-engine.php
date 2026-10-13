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
eq( 'missing source returns SKIP', FileCopier::copyOne( "$ROOT/nope", "$ROOT/s4dst", 5, 123, null, $off, microtime( true ) + 5 ), FileCopier::RESULT_SKIP );
putf( "$ROOT/s6/src.bin", '12345' );
$off = 0;
eq( 'size mismatch returns ERROR', FileCopier::copyOne( "$ROOT/s6/src.bin", "$ROOT/s6/dst.bin", 99, 123, null, $off, microtime( true ) + 5 ), FileCopier::RESULT_ERROR );

rmrf( $ROOT );
echo "\n-----------------------------------------\n";
printf( "copy-engine tests: %d passed, %d failed\n", $PASS, $FAIL );
exit( 0 === $FAIL ? 0 : 1 );
