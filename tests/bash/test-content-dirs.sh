#!/usr/bin/env bash
#
# Unit tests for the content-directory deploy logic in lib/.staging.
#
# Covers the two functions that promote staging content over production during a deploy:
#
#   - prepare_new_content_dirs builds "$DIR-new" as a --link-dest delta of the current production
#     tree: files identical to production are hardlinked (no byte copy), only changed/new files are
#     copied. This is what keeps a deploy from needing a second full copy of a large uploads tree.
#   - finalize_new_content_dirs promotes "$DIR-new" over production with two instant renames (move
#     production aside, promote new), never deleting live production before the swap. On promotion
#     failure it restores production from the aside. It consumes every "-new" and leaves the "-old"
#     asides in place, because deleting them is the slow half of the swap and is a separate step.
#   - discard_content_asides deletes those asides. deploy_content_finalize calls it AFTER releasing
#     the staging mutex, which is the whole design: the delete is the one step in a content deploy
#     whose cost scales with the site's file count, so it is the one step that can outlive the PHP
#     request, and with the mutex already free nothing is waiting on it.
#   - cleanup_failed_new_content_dirs sweeps a failed attempt. "-new" is always disposable. An "-old"
#     is never deleted: production's name free means the aside IS production and is restored, and both
#     names occupied means nothing on disk says which copy is production, so the aside is parked under
#     a "-recovered-" name that no sweep touches.
#
# The staging script runs server-side via PHP exec() and is not exercised by the PHP (wpunit) or
# Playwright suites, so these bash tests are its only automated coverage. They do real filesystem
# work in temp dirs; no database or wp-cli is required. Hardlink identity is checked with the POSIX
# `-ef` test, so that part is portable, but the promotions under test use GNU `mv -T` and this suite
# therefore needs Linux (it detects the missing flag and skips; see below).
#
# Run: bash tests/bash/test-content-dirs.sh  (on Linux, as a non-root user)

# PRODUCTION_DIR, STAGING_DIR and friends are read by the SOURCED lib/.staging, not by this file. A
# linter cannot follow a dynamic source, so it reports every one of them as unused; hence the
# file-wide SC2034 exemption below.
# shellcheck disable=SC2034

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAGING_SCRIPT="$SCRIPT_DIR/../../lib/.staging"

if [ ! -f "$STAGING_SCRIPT" ]; then
  echo "FATAL: cannot find lib/.staging at $STAGING_SCRIPT" >&2
  exit 1
fi

# Every promotion in the code under test uses `mv -T`, which is GNU coreutils only: BSD/macOS `mv`
# rejects the flag outright. So this suite can only run on Linux, and on macOS it used to report
# dozens of failures that said nothing about the branch. Detect it and say so instead.
#
# The deploy worker only ever runs on cPanel (Linux), and CI is ubuntu-latest, so nothing real is
# skipped by this. Run it locally with:
#   docker run --rm -v "$PWD":/w -w /w ubuntu:22.04 \
#     bash -c 'apt-get update -qq && apt-get install -y -qq rsync && useradd -m tester &&
#              su tester -c "cd /w && bash tests/bash/test-content-dirs.sh"'
# (as the non-root `tester`: a few assertions need permission bits to bite, and root ignores them)
MV_T_PROBE="$(mktemp -d)"
mkdir -p "$MV_T_PROBE/a" "$MV_T_PROBE/b"
if ! mv -T "$MV_T_PROBE/a" "$MV_T_PROBE/c" 2>/dev/null; then
  rm -rf "$MV_T_PROBE"
  echo "SKIP: tests/bash/test-content-dirs.sh needs GNU coreutils \`mv -T\`, which this host lacks."
  echo "      Run it on Linux or in a container (see the comment at the top of this file)."
  exit 0
fi
rm -rf "$MV_T_PROBE"

# Source the script. Its `BASH_SOURCE == $0` guards are false when sourced, so the entrypoint and the
# top-level wp bootstrap are skipped; the tested functions get their inputs as arguments.
set -- compat_check token /nonexistent/prod /nonexistent/stg http://p.test http://s.test 0 id slug name
# shellcheck disable=SC1090
source "$STAGING_SCRIPT"
set +e              # the script enables `set -e`; tests assert on non-zero returns, so turn it off
trap - EXIT         # drop the script's cleanup trap (it would shell out to wp on test exit)

LOG_FILE="$(mktemp)"

# Neutralise lifecycle helpers so the sourced functions never shell out to wp/cpanel during tests,
# and make error() non-fatal so an unexpected error path reports instead of killing the run.
cleanup() { :; }
release_staging_lock() { :; }
resync_cached_options() { :; }
write_deploy_result() { :; }
error() { printf 'test error(): %s\n' "$1" >&2; return 1; }

PASS=0
FAIL=0
ok() { PASS=$((PASS + 1)); printf '  ok   - %s\n' "$1"; }
no() { FAIL=$((FAIL + 1)); printf '  FAIL - %s\n' "$1"; [ -n "${2:-}" ] && printf '         %s\n' "$2"; }
assert_eq() { if [ "$2" = "$3" ]; then ok "$1"; else no "$1" "expected [$2], got [$3]"; fi; }
assert_same_file() { if [ "$2" -ef "$3" ]; then ok "$1"; else no "$1" "[$2] is not the same inode as [$3]"; fi; }
assert_diff_file() { if [ "$2" -ef "$3" ]; then no "$1" "[$2] unexpectedly shares an inode with [$3]"; else ok "$1"; fi; }
assert_absent() { if [ -e "$2" ]; then no "$1" "[$2] should not exist"; else ok "$1"; fi; }
assert_present() { if [ -e "$2" ]; then ok "$1"; else no "$1" "[$2] should exist"; fi; }
assert_ne() { if [ "$2" != "$3" ]; then ok "$1"; else no "$1" "expected anything but [$2]"; fi; }

# Make specific `mv` calls fail, so the concurrency windows in finalize can be driven deterministically
# instead of waited for. A shell function shadows the external command for every call the sourced
# script makes.
#
# This replaced a chmod-based trick (a read-only parent directory, so the rm or mv inside it fails).
# That worked only while the code happened to touch a path under the directory the test had locked
# down, and when it stopped doing so the injection silently stopped happening: the suite still passed,
# but against the happy path. Shadowing `mv` is keyed to the operation itself, so it cannot miss.
#
# MV_FAIL_SOURCES is a list of SOURCE paths, each failing ONE mv (list a path twice to fail two in a
# row). Matched on the source rather than the destination because the promote and the rollback's
# restore share a destination -- production's own directory -- and only their sources tell them apart.
#
# A source also in MV_SQUAT_SOURCES gets a non-empty directory created at the mv's DESTINATION on the
# way out, which is what a live request does when wp_upload_dir() remakes uploads/YYYY/MM inside the
# window. Paths come from mktemp -d and never contain spaces, so splitting these lists on whitespace
# is safe.
#
# MV_SQUAT_AFTER_SOURCES is the other half of that, and it is the only way to exercise `mv -T` itself:
# the named mv SUCCEEDS and a squatter is then created at the path it just vacated (its SOURCE). Used
# on the aside rename, that leaves production's own name occupied when the promote runs, with the real
# `mv` in play -- so a promote that lost its `-T` nests the staged tree instead of failing. A shim that
# only ever fails the promote cannot tell the two apart, because `mv` and `mv -T` behave identically
# when the destination does not exist.
MV_FAIL_SOURCES=""
MV_SQUAT_SOURCES=""
MV_SQUAT_AFTER_SOURCES=""
install_mv_shim() {
  mv() {
    local argc=$# target="${!#}" source="" t rest="" hit=0 rc=0
    if [ "$argc" -ge 2 ]; then
      local si=$((argc - 1)); source="${!si}"
    fi
    for t in $MV_FAIL_SOURCES; do
      if [ "$hit" -eq 0 ] && [ "$t" = "$source" ]; then hit=1; else rest="$rest $t"; fi
    done
    if [ "$hit" -eq 1 ]; then
      MV_FAIL_SOURCES="$rest"
      case " $MV_SQUAT_SOURCES " in
        *" $source "*)
          mkdir -p "$target/2026/10" 2>/dev/null || true
          : > "$target/2026/10/x.jpg" 2>/dev/null || true
          ;;
      esac
      return 1
    fi
    command mv "$@" || rc=$?
    if [ "$rc" -eq 0 ]; then
      rest=""; hit=0
      for t in $MV_SQUAT_AFTER_SOURCES; do
        if [ "$hit" -eq 0 ] && [ "$t" = "$source" ]; then hit=1; else rest="$rest $t"; fi
      done
      if [ "$hit" -eq 1 ]; then
        MV_SQUAT_AFTER_SOURCES="$rest"
        mkdir -p "$source/2026/10" 2>/dev/null || true
        : > "$source/2026/10/x.jpg" 2>/dev/null || true
      fi
    fi
    return "$rc"
  }
}
remove_mv_shim() { unset -f mv; MV_FAIL_SOURCES=""; MV_SQUAT_SOURCES=""; MV_SQUAT_AFTER_SOURCES=""; }

echo "== prepare_new_content_dirs builds a --link-dest delta snapshot =="

ROOT="$(mktemp -d)"
PROD="$ROOT/prod"; STG="$ROOT/stg"
for d in uploads themes plugins; do mkdir -p "$PROD/wp-content/$d" "$STG/wp-content/$d"; done

# Identical in production and staging (same bytes AND mtime) -> must hardlink, not copy.
echo "shared" > "$PROD/wp-content/uploads/shared.txt"
cp -p "$PROD/wp-content/uploads/shared.txt" "$STG/wp-content/uploads/shared.txt"
# Only in staging -> copied fresh.
echo "brand new" > "$STG/wp-content/uploads/new.txt"
# Only in production (deleted in staging) -> must be gone from production after deploy.
echo "obsolete" > "$PROD/wp-content/uploads/old.txt"
# Changed (same path, different bytes) -> staging version wins, not hardlinked to production.
echo "v1" > "$PROD/wp-content/themes/style.css"
echo "v2-longer" > "$STG/wp-content/themes/style.css"

prepare_new_content_dirs "$STG" "$PROD"
assert_eq "prepare_new_content_dirs returns 0" "0" "$?"

assert_same_file "unchanged file is hardlinked into -new (no byte copy)" \
  "$PROD/wp-content/uploads/shared.txt" "$PROD/wp-content/uploads-new/shared.txt"
assert_eq "staging-only file is present in -new" "brand new" "$(cat "$PROD/wp-content/uploads-new/new.txt" 2>/dev/null)"
assert_diff_file "staging-only file is a fresh copy, not a link to production" \
  "$PROD/wp-content/uploads/shared.txt" "$PROD/wp-content/uploads-new/new.txt"
assert_eq "changed file carries staging content in -new" "v2-longer" "$(cat "$PROD/wp-content/themes-new/style.css" 2>/dev/null)"
assert_diff_file "changed file is not hardlinked to the old production version" \
  "$PROD/wp-content/themes/style.css" "$PROD/wp-content/themes-new/style.css"
assert_absent "production-only (obsolete) file is excluded from -new" "$PROD/wp-content/uploads-new/old.txt"

echo "== finalize_new_content_dirs promotes atomically (instant swap; asides kept for the next setup) =="

finalize_new_content_dirs "$PROD"

assert_eq "production has the unchanged file" "shared" "$(cat "$PROD/wp-content/uploads/shared.txt" 2>/dev/null)"
assert_eq "production gained the staging-only file" "brand new" "$(cat "$PROD/wp-content/uploads/new.txt" 2>/dev/null)"
assert_eq "production took the changed file" "v2-longer" "$(cat "$PROD/wp-content/themes/style.css" 2>/dev/null)"
assert_absent "obsolete file is gone from production" "$PROD/wp-content/uploads/old.txt"
assert_absent "no uploads-new leftover (promoted)" "$PROD/wp-content/uploads-new"
assert_absent "no themes-new leftover (promoted)" "$PROD/wp-content/themes-new"
# finalize is now only the instant swap: the "-old" asides are deliberately left as disposable,
# hardlink-heavy safety backups for the next deploy's setup (cleanup_failed_new_content_dirs) to sweep,
# so finalize cannot run long enough to be orphaned mid-sweep and report a successful swap as failed.
assert_present "uploads-old aside is kept (swept by the next setup, not by finalize)" "$PROD/wp-content/uploads-old"
assert_present "themes-old aside is kept (swept by the next setup, not by finalize)" "$PROD/wp-content/themes-old"

echo "== finalize aborts rather than promote when it cannot clear the -old name =="

# A stale uploads-old is in the way and cannot be moved out of it. finalize must abort instead of
# carrying on, because the alternative is `mv -T` onto an occupied name -- which fails anyway -- or,
# without -T, nesting production inside it. Production (uploads/keep.txt) must be left intact.
#
# Injected through the mv shim, keyed to the operation itself. This test used to make the stale
# directory read-only so an `rm -rf` inside it would fail, and when the code stopped deleting that
# tree and started renaming it instead, the injection silently stopped happening: `mv` of a directory
# needs no write permission INSIDE it. The suite still passed, on the happy path, asserting a rollback
# that no longer occurred.
R2="$(mktemp -d)"; P2="$R2/prod"
mkdir -p "$P2/wp-content/uploads" "$P2/wp-content/uploads-new" "$P2/wp-content/uploads-old"
echo "LIVE DATA" > "$P2/wp-content/uploads/keep.txt"
echo "STAGED"    > "$P2/wp-content/uploads-new/f"
echo "BLOCK"     > "$P2/wp-content/uploads-old/f"
install_mv_shim
MV_FAIL_SOURCES="$P2/wp-content/uploads-old"   # the park of the stale aside
ROLLBACK_HIT=0
finalize_new_content_dirs "$P2" 'ROLLBACK_HIT=1'
remove_mv_shim
assert_eq "error/rollback path was taken" "1" "$ROLLBACK_HIT"
assert_eq "production content is intact (not lost) after failed finalize" "LIVE DATA" "$(cat "$P2/wp-content/uploads/keep.txt" 2>/dev/null)"
assert_present "production uploads directory still exists" "$P2/wp-content/uploads"
assert_eq "nothing was promoted" "STAGED" "$(cat "$P2/wp-content/uploads-new/f" 2>/dev/null)"
assert_eq "and the stale aside is untouched, not half-moved" "BLOCK" "$(cat "$P2/wp-content/uploads-old/f" 2>/dev/null)"

echo "== finalize is all-or-nothing across dirs: one failing dir rolls the others back =="

# uploads and themes promote cleanly; plugins then fails, because the stale plugins-old in its way
# cannot be moved. Since the asides are only discarded after every dir promotes, uploads and themes
# must roll back to their ORIGINAL production content rather than be left half-deployed.
#
# Also injected through the shim now, for the reason given in the test above: the read-only directory
# this used to rely on stopped blocking anything once the code renamed that tree instead of deleting
# it, and the test went on passing while asserting a rollback that was not happening.
R4="$(mktemp -d)"; P4="$R4/prod"
mkdir -p "$P4/wp-content/uploads" "$P4/wp-content/uploads-new" \
         "$P4/wp-content/themes"  "$P4/wp-content/themes-new" \
         "$P4/wp-content/plugins"  "$P4/wp-content/plugins-new" "$P4/wp-content/plugins-old"
echo "ORIG-UP" > "$P4/wp-content/uploads/f";  echo "NEW-UP" > "$P4/wp-content/uploads-new/f"
echo "ORIG-TH" > "$P4/wp-content/themes/f";   echo "NEW-TH" > "$P4/wp-content/themes-new/f"
echo "ORIG-PL" > "$P4/wp-content/plugins/f";  echo "NEW-PL" > "$P4/wp-content/plugins-new/f"
echo "BLOCK"   > "$P4/wp-content/plugins-old/f"
install_mv_shim
MV_FAIL_SOURCES="$P4/wp-content/plugins-old"
XDIR_HIT=0
finalize_new_content_dirs "$P4" 'XDIR_HIT=1'
remove_mv_shim
assert_eq "cross-dir failure took the error path" "1" "$XDIR_HIT"
assert_eq "uploads rolled back to original content" "ORIG-UP" "$(cat "$P4/wp-content/uploads/f" 2>/dev/null)"
assert_eq "themes rolled back to original content" "ORIG-TH" "$(cat "$P4/wp-content/themes/f" 2>/dev/null)"
assert_eq "plugins never promoted (still original)" "ORIG-PL" "$(cat "$P4/wp-content/plugins/f" 2>/dev/null)"

echo "== finalize parks a stale -old left by an interrupted prior run, and still promotes =="

# Production in place plus a leftover -old. finalize needs the "-old" NAME, nothing more, so it gets
# it with a rename: `[ -d "$ORIG" ]` is not evidence that production is safe (see the sweep tests
# below), and this branch used to delete on that test, which put the unsound rule back 200 lines from
# where it was removed. The deploy must still promote cleanly (regression guard for the mv-nesting
# bug: a stale -old must never cause production to be moved INTO it).
R5="$(mktemp -d)"; P5="$R5/prod"
mkdir -p "$P5/wp-content/uploads" "$P5/wp-content/uploads-new" "$P5/wp-content/uploads-old" \
         "$P5/wp-content/themes" "$P5/wp-content/themes-new" \
         "$P5/wp-content/plugins" "$P5/wp-content/plugins-new"
echo "PROD" > "$P5/wp-content/uploads/f"
echo "STAGED" > "$P5/wp-content/uploads-new/f"
echo "LEFTOVER" > "$P5/wp-content/uploads-old/f"
finalize_new_content_dirs "$P5"
assert_eq "promoted staged content despite stale -old" "STAGED" "$(cat "$P5/wp-content/uploads/f" 2>/dev/null)"
assert_absent "stale -old moved out of the way, not nested" "$P5/wp-content/uploads/uploads-old"
# The -old that remains is this run's aside of the pre-deploy production (PROD), not the leftover.
assert_eq "remaining uploads-old is this run's production aside (not the stale leftover)" "PROD" "$(cat "$P5/wp-content/uploads-old/f" 2>/dev/null)"
# And the leftover itself is still on disk. It MIGHT have been production -- a finalize killed between
# its two renames leaves exactly this shape, and a single media request then recreates the directory
# that makes it look disposable. Nothing here can tell, so nothing here deletes.
PARKED_P5="$(find "$P5/wp-content" -maxdepth 1 -name 'uploads-recovered-*' -type d | head -1)"
assert_eq "the unattributable leftover was parked, not deleted" "LEFTOVER" "$(cat "$PARKED_P5/f" 2>/dev/null)"

echo "== finalize restores production from a -old left when ORIG is missing (mid-swap interrupt) =="

# ORIG missing but -old present = a prior run died between the aside and the promote; -old is the only
# production copy and must be restored/adopted, never deleted. With a valid -new, the deploy then
# promotes the staged content (deploy semantics), but the production copy is never lost to a sweep.
R6="$(mktemp -d)"; P6="$R6/prod"
mkdir -p "$P6/wp-content/uploads-new" "$P6/wp-content/uploads-old" \
         "$P6/wp-content/themes" "$P6/wp-content/themes-new" \
         "$P6/wp-content/plugins" "$P6/wp-content/plugins-new"
echo "STAGED6" > "$P6/wp-content/uploads-new/f"
echo "SOLE-PROD" > "$P6/wp-content/uploads-old/f"   # no uploads/ (ORIG) present
finalize_new_content_dirs "$P6"
assert_present "uploads production directory exists after restore+promote" "$P6/wp-content/uploads"
# -old must never be blindly rm'd while it is the only copy. It is restored into place, then moved
# aside again as this run's backup, so after the deploy production holds the staged content AND the
# sole-production content survives in the -old aside (swept by the next setup, once ORIG is in place).
assert_eq "uploads holds staged content after deploy" "STAGED6" "$(cat "$P6/wp-content/uploads/f" 2>/dev/null)"
assert_eq "sole-production content preserved in the -old aside, not deleted" "SOLE-PROD" "$(cat "$P6/wp-content/uploads-old/f" 2>/dev/null)"

echo "== cleanup sweeps -new always, and NEVER deletes an -old: it restores or parks it =="

# -new is always disposable. An -old is not, and cleanup does not try to work out whether it is:
# production's name free means the aside IS production (a prior run died mid-swap) and it is restored;
# both names occupied means nothing on disk says which copy is production, so the aside is parked
# under a name no sweep touches. cleanup runs on a resumed deploy before finalize, so this rule has to
# live here too.
R3="$(mktemp -d)"; P3="$R3/prod"
mkdir -p "$P3/wp-content/uploads-new" \
         "$P3/wp-content/themes" "$P3/wp-content/themes-old" \
         "$P3/wp-content/plugins-old"
echo x > "$P3/wp-content/uploads-new/f"
echo "LIVE-TH" > "$P3/wp-content/themes/f"            # production present -> themes-old unattributable
echo "AMBIGUOUS-TH" > "$P3/wp-content/themes-old/f"
echo "SOLE-PL" > "$P3/wp-content/plugins-old/f"       # no plugins/ -> plugins-old is the only copy

cleanup_failed_new_content_dirs "$P3"

assert_absent "cleanup removed disposable -new" "$P3/wp-content/uploads-new"
assert_absent "cleanup freed the -old name when production was also present" "$P3/wp-content/themes-old"
# ...by MOVING it, not deleting it. `[ -d "$ORIG" ]` is not proof that production is safe: one live
# request recreates the directory (wp_upload_dir() remakes uploads/YYYY/MM), and a near-empty recreated
# directory satisfies that test exactly as well as the real tree does. Deleting on that test deletes
# the customer's media library, so the aside is parked instead.
PARKED_TH="$(find "$P3/wp-content" -maxdepth 1 -name 'themes-recovered-*' -type d | head -1)"
assert_eq "the unattributable aside was PARKED, not deleted" "AMBIGUOUS-TH" "$(cat "$PARKED_TH/f" 2>/dev/null)"
assert_eq "cleanup left production themes untouched" "LIVE-TH" "$(cat "$P3/wp-content/themes/f" 2>/dev/null)"
assert_eq "cleanup RESTORED sole-copy -old to production (did not delete it)" "SOLE-PL" "$(cat "$P3/wp-content/plugins/f" 2>/dev/null)"
assert_absent "restored plugins-old no longer present" "$P3/wp-content/plugins-old"

echo "== a parked aside is never swept by a later cleanup, however many run =="

# The parked name is the end of the line for that tree: it is kept until a human or a support request
# clears it. A sweep that reclaimed its own parked names would make the park pointless -- the second
# deploy would delete what the first one refused to.
cleanup_failed_new_content_dirs "$P3"
cleanup_failed_new_content_dirs "$P3"
assert_eq "the parked tree survives repeated sweeps" "AMBIGUOUS-TH" "$(cat "$PARKED_TH/f" 2>/dev/null)"

echo "== a recreated production directory does not cost the customer their media =="

# The scenario the park rule exists for, end to end: a finalize was killed between its two renames, so
# production is at uploads-old and the uploads name is free. Before the retry runs, one media request
# recreates uploads/ with a single month directory in it. The sweep now sees both names occupied.
R8="$(mktemp -d)"; P8="$R8/prod"
mkdir -p "$P8/wp-content/uploads-old/2026/09" "$P8/wp-content/uploads/2026/10"
echo "THE CUSTOMER'S MEDIA LIBRARY" > "$P8/wp-content/uploads-old/2026/09/photo.jpg"
: > "$P8/wp-content/uploads/2026/10/just-recreated.jpg"

cleanup_failed_new_content_dirs "$P8"

PARKED_UP="$(find "$P8/wp-content" -maxdepth 1 -name 'uploads-recovered-*' -type d | head -1)"
assert_eq "the real media library is still on disk" "THE CUSTOMER'S MEDIA LIBRARY" \
  "$(cat "$PARKED_UP/2026/09/photo.jpg" 2>/dev/null)"
assert_present "and the recreated directory is left serving" "$P8/wp-content/uploads/2026/10/just-recreated.jpg"

echo "== finalize re-run is idempotent: a dir already promoted (no -new) is skipped, not re-promoted =="

# Simulate a finalize that was interrupted after promoting uploads but before themes/plugins: uploads
# holds the new content and has NO uploads-new (the rename consumed it); themes/plugins still have -new.
# A re-run must skip uploads (not error on its missing -new, not touch uploads-old), promote the rest,
# and succeed.
R7="$(mktemp -d)"; P7="$R7/prod"
mkdir -p "$P7/wp-content/uploads" "$P7/wp-content/uploads-old" \
         "$P7/wp-content/themes" "$P7/wp-content/themes-new" \
         "$P7/wp-content/plugins" "$P7/wp-content/plugins-new"
echo "NEW-UP"  > "$P7/wp-content/uploads/f"      # already promoted; no uploads-new
echo "OLD-UP"  > "$P7/wp-content/uploads-old/f"  # disposable leftover aside
echo "OLD-TH"  > "$P7/wp-content/themes/f";   echo "NEW-TH" > "$P7/wp-content/themes-new/f"
echo "OLD-PL"  > "$P7/wp-content/plugins/f";  echo "NEW-PL" > "$P7/wp-content/plugins-new/f"

RERUN_HIT=0
finalize_new_content_dirs "$P7" 'RERUN_HIT=1'

assert_eq "re-run did not hit the error path" "0" "$RERUN_HIT"
assert_eq "already-promoted uploads untouched" "NEW-UP" "$(cat "$P7/wp-content/uploads/f" 2>/dev/null)"
assert_eq "themes promoted on re-run" "NEW-TH" "$(cat "$P7/wp-content/themes/f" 2>/dev/null)"
assert_eq "plugins promoted on re-run" "NEW-PL" "$(cat "$P7/wp-content/plugins/f" 2>/dev/null)"
# finalize no longer sweeps asides, so the leftover uploads-old is left untouched (the next setup's
# cleanup_failed_new_content_dirs reclaims it); the re-run must not error on it or lose it.
assert_eq "disposable uploads-old left intact for the next setup to sweep" "OLD-UP" "$(cat "$P7/wp-content/uploads-old/f" 2>/dev/null)"
assert_absent "no themes-new leftover after re-run" "$P7/wp-content/themes-new"

echo "== content_aside_name mints a distinct path every time, including twice in one second =="

# Two asides for the same dir inside the same second is not hypothetical: one rollback can park a
# squatter and then a recovered tree back to back. With only a timestamp and a pid in the name, the
# second name EQUALS the first, and `mv -T` onto the now non-empty directory FAILS -- leaving the
# caller holding the aside it was trying to move out of the way, in the one code path whose whole job
# is not to lose a tree. The sequence number is what separates them.
R9="$(mktemp -d)"
content_aside_name "$R9" uploads recovered; A1="$NFD_ASIDE_PATH"
content_aside_name "$R9" uploads recovered; A2="$NFD_ASIDE_PATH"
assert_ne "two names minted back to back differ" "$A1" "$A2"
case "$A1" in
  "$R9/wp-content/uploads-recovered-"*) ok "the name is scoped to the dir and the kind" ;;
  *) no "the name is scoped to the dir and the kind" "got [$A1]" ;;
esac
# And the counter keeps rising across kinds, so a squatter and a recovered tree cannot collide either.
content_aside_name "$R9" uploads squatter; A3="$NFD_ASIDE_PATH"
assert_ne "a different kind is also distinct" "$A2" "$A3"

echo "== discard_content_asides removes the swap's own asides and nothing else =="

R10="$(mktemp -d)"; P10="$R10/prod"
mkdir -p "$P10/wp-content/uploads" "$P10/wp-content/uploads-old" \
         "$P10/wp-content/themes" "$P10/wp-content/themes-old" \
         "$P10/wp-content/plugins" \
         "$P10/wp-content/uploads-recovered-1-1-1" "$P10/wp-content/uploads-new"
echo "LIVE" > "$P10/wp-content/uploads/f"
echo "BACKUP" > "$P10/wp-content/uploads-old/f"
echo "BACKUP-TH" > "$P10/wp-content/themes-old/f"
echo "PARKED" > "$P10/wp-content/uploads-recovered-1-1-1/f"
echo "UNPROMOTED" > "$P10/wp-content/uploads-new/f"

discard_content_asides "$P10"
assert_eq "discard returns 0" "0" "$?"

assert_absent "the uploads aside is gone" "$P10/wp-content/uploads-old"
assert_absent "the themes aside is gone" "$P10/wp-content/themes-old"
assert_eq "live production is untouched" "LIVE" "$(cat "$P10/wp-content/uploads/f" 2>/dev/null)"
# A parked tree is deliberately outside the swept namespace: the sweep refused to delete it, so the
# discard must not delete it either, or the park would buy nothing.
assert_eq "a parked tree is not in the swept namespace" "PARKED" "$(cat "$P10/wp-content/uploads-recovered-1-1-1/f" 2>/dev/null)"
# And a "-new" is not this function's business. It belongs to cleanup, which can tell a disposable
# prepared tree from one a resumed finalize is about to promote.
assert_eq "an unpromoted -new is left for cleanup/finalize" "UNPROMOTED" "$(cat "$P10/wp-content/uploads-new/f" 2>/dev/null)"

# Nothing to do is a success, not a failure: deploy_content_finalize calls this unconditionally, and a
# non-zero return under the script's `set -e` would fail a deploy that had already succeeded.
discard_content_asides "$P10"
assert_eq "a second discard with nothing left still returns 0" "0" "$?"

echo "== deploy_content_finalize releases the mutex BEFORE the unbounded delete =="

# This ordering is the entire reason the swap and the delete are separate functions, and it is not
# observable from either one alone. The delete is O(file count); the swap is three renames. Holding the
# mutex across the delete is what made a successful deploy get reported as a failure: the request
# blocking on this script times out mid-delete, the orphan carries on holding the lock, and the next
# poll has no way to tell "still running" from "finished". With the lock already free, the next poll
# finds no "-new" to promote and reports the success that has already happened.
R11="$(mktemp -d)"; P11="$R11/prod"; S11="$R11/stg"
mkdir -p "$S11" "$P11/wp-content/uploads" "$P11/wp-content/uploads-new" \
         "$P11/wp-content/themes" "$P11/wp-content/themes-new" \
         "$P11/wp-content/plugins" "$P11/wp-content/plugins-new"
for d in uploads themes plugins; do
  echo "ORIG" > "$P11/wp-content/$d/f"
  echo "STAGED" > "$P11/wp-content/$d-new/f"
done

PRODUCTION_DIR="$P11"
STAGING_DIR="$S11"
LOCK_RELEASED_BEFORE_DISCARD=""
REAL_DISCARD="$(declare -f discard_content_asides)"
release_staging_lock() { LOCK_FREE=1; }
LOCK_FREE=0
discard_content_asides() {
  # Recorded at the moment of the call, not afterwards: the question is the ORDER, so reading
  # LOCK_FREE once the whole verb has finished would pass either way.
  LOCK_RELEASED_BEFORE_DISCARD="$LOCK_FREE"
  eval "$REAL_DISCARD"
  discard_content_asides "$@"
}
deploy_content_finalize >/dev/null 2>&1
FINALIZE_RC=$?
unset -f discard_content_asides
eval "$REAL_DISCARD"
release_staging_lock() { :; }

assert_eq "deploy_content_finalize succeeds" "0" "$FINALIZE_RC"
assert_eq "the mutex was already free when the delete started" "1" "$LOCK_RELEASED_BEFORE_DISCARD"
assert_eq "production holds the staged content" "STAGED" "$(cat "$P11/wp-content/uploads/f" 2>/dev/null)"
# The asides go in this run, not the next one. An aside that outlives its run is exposed to a live
# request recreating production's directory, after which nothing on disk can tell the backup from the
# real thing -- the ambiguity the whole abandoned journal design existed to resolve.
assert_absent "the uploads aside was discarded in-run" "$P11/wp-content/uploads-old"
assert_absent "the themes aside was discarded in-run" "$P11/wp-content/themes-old"
assert_absent "the plugins aside was discarded in-run" "$P11/wp-content/plugins-old"

echo "== finalize and the sweep run to completion under the real script's set -e =="

# The harness turns errexit OFF at line 35 so tests can assert on return values, which hides a whole
# class of break: a command that legitimately returns non-zero in a non-final position is fine, but one
# in a final or lone position ENDS THE RUN. In production these functions run under `set -e`, in the
# middle of a deploy, so a `[ -d x ]` that happens to be the last statement in a loop body would abort
# a swap halfway. Each is called as a PLAIN STATEMENT here: `f || true` suppresses errexit for the
# whole dynamic extent of the command, which would mask exactly this.
R12="$(mktemp -d)"; P12="$R12/prod"
mkdir -p "$P12/wp-content/uploads" "$P12/wp-content/uploads-new" \
         "$P12/wp-content/themes" "$P12/wp-content/themes-new" \
         "$P12/wp-content/plugins" "$P12/wp-content/plugins-new"
for d in uploads themes plugins; do
  echo "ORIG" > "$P12/wp-content/$d/f"
  echo "STAGED" > "$P12/wp-content/$d-new/f"
done
( set -e
  finalize_new_content_dirs "$P12"
  discard_content_asides "$P12"
  : > "$R12/reached" ) >/dev/null 2>&1
assert_present "swap then discard both completed under set -e" "$R12/reached"
assert_eq "and production holds the staged content" "STAGED" "$(cat "$P12/wp-content/uploads/f" 2>/dev/null)"

# The sweep's two branches under set -e as well: a restore (production's name free) and a park (both
# names occupied). The park ends in an `mv` whose failure would be fatal, and the restore branch's
# `[ ! -e "$ORIG" ]` test is the kind that returns non-zero on the ordinary path.
R13="$(mktemp -d)"; P13="$R13/prod"
mkdir -p "$P13/wp-content/uploads-old" "$P13/wp-content/themes" "$P13/wp-content/themes-old" \
         "$P13/wp-content/plugins-new"
echo "SOLE" > "$P13/wp-content/uploads-old/f"
echo "LIVE" > "$P13/wp-content/themes/f"
echo "AMBIG" > "$P13/wp-content/themes-old/f"
( set -e
  cleanup_failed_new_content_dirs "$P13"
  : > "$R13/reached" ) >/dev/null 2>&1
assert_present "the sweep completed under set -e" "$R13/reached"
assert_eq "the sole copy was restored" "SOLE" "$(cat "$P13/wp-content/uploads/f" 2>/dev/null)"
PARKED_13="$(find "$P13/wp-content" -maxdepth 1 -name 'themes-recovered-*' -type d | head -1)"
assert_eq "the ambiguous aside was parked" "AMBIG" "$(cat "$PARKED_13/f" 2>/dev/null)"
assert_absent "and the disposable -new went" "$P13/wp-content/plugins-new"

echo "== a promote that fails restores production, and never deletes the only copy =="

# Driven through the mv shim rather than a permissions trick, so the failing operation is the promote
# itself. Production was set aside this run, so the rollback has a copy to restore and must put it
# back in place.
R14="$(mktemp -d)"; P14="$R14/prod"
mkdir -p "$P14/wp-content/uploads" "$P14/wp-content/uploads-new" \
         "$P14/wp-content/themes" "$P14/wp-content/themes-new" \
         "$P14/wp-content/plugins" "$P14/wp-content/plugins-new"
for d in uploads themes plugins; do
  echo "ORIG-$d" > "$P14/wp-content/$d/f"
  echo "STAGED-$d" > "$P14/wp-content/$d-new/f"
done
install_mv_shim
MV_FAIL_SOURCES="$P14/wp-content/uploads-new"   # the promote of uploads
PROMOTE_HIT=0
finalize_new_content_dirs "$P14" 'PROMOTE_HIT=1'
remove_mv_shim

assert_eq "a failed promote took the error path" "1" "$PROMOTE_HIT"
assert_eq "production was restored in place" "ORIG-uploads" "$(cat "$P14/wp-content/uploads/f" 2>/dev/null)"
assert_absent "and its aside is gone, because it went back to production" "$P14/wp-content/uploads-old"
assert_eq "the staged tree is still there to retry from" "STAGED-uploads" "$(cat "$P14/wp-content/uploads-new/f" 2>/dev/null)"
# uploads is the first dir, so nothing had promoted yet. The cross-dir version of this is covered above.
assert_eq "later dirs were not promoted" "ORIG-themes" "$(cat "$P14/wp-content/themes/f" 2>/dev/null)"

echo "== a live request recreating production mid-swap does not block the deploy =="

# The window the squatter retry exists for: production has been renamed to "-old", and before the
# promote lands a media request remakes uploads/. `mv -T` then refuses to nest and the promote fails.
# Production is provably backed up at "-old" (this run set it aside), so the squatter is moved out of
# the way and the promote retried, rather than leaving production serving a one-file directory.
R15="$(mktemp -d)"; P15="$R15/prod"
mkdir -p "$P15/wp-content/uploads" "$P15/wp-content/uploads-new" \
         "$P15/wp-content/themes" "$P15/wp-content/themes-new" \
         "$P15/wp-content/plugins" "$P15/wp-content/plugins-new"
for d in uploads themes plugins; do
  echo "ORIG-$d" > "$P15/wp-content/$d/f"
  echo "STAGED-$d" > "$P15/wp-content/$d-new/f"
done
install_mv_shim
# Fail the promote ONCE and have that same failure leave a squatter behind, which is what a live
# request does. The retry then runs against the real `mv` and must succeed.
MV_FAIL_SOURCES="$P15/wp-content/uploads-new"
MV_SQUAT_SOURCES="$P15/wp-content/uploads-new"
SQUAT_HIT=0
finalize_new_content_dirs "$P15" 'SQUAT_HIT=1'
remove_mv_shim

assert_eq "the deploy did NOT take the error path" "0" "$SQUAT_HIT"
assert_eq "production holds the staged content after the retry" "STAGED-uploads" "$(cat "$P15/wp-content/uploads/f" 2>/dev/null)"
SQUATTED="$(find "$P15/wp-content" -maxdepth 1 -name 'uploads-squatter-*' -type d | head -1)"
assert_present "the concurrently written file is kept for inspection, not deleted" "$SQUATTED/2026/10/x.jpg"
# The squatter's name is outside every swept namespace, for the same reason a parked tree's is: this
# code cannot prove the squatter is disposable, and deleting the wrong tree is the unrecoverable error.
discard_content_asides "$P15"
assert_present "and the in-run discard does not sweep it" "$SQUATTED/2026/10/x.jpg"
assert_eq "the remaining dirs promoted normally" "STAGED-themes" "$(cat "$P15/wp-content/themes/f" 2>/dev/null)"

echo "== the promote REFUSES to nest when production's name was reoccupied (mv -T) =="

# The one case where `-T` is the difference, and the only way to reach it: production's name has to be
# occupied at the moment the promote runs, with the real `mv`. The shim lets the aside rename succeed
# and then recreates the vacated directory, exactly as a media request does.
#
# Plain `mv <dir>-new <dir>` onto an existing directory NESTS the source inside it and returns
# SUCCESS, so the swap would report success with the staged tree hidden at <dir>/<dir>-new and
# production's real content then deleted as a disposable aside. `-T` turns that into a failure, which
# routes to the squatter retry instead.
R18="$(mktemp -d)"; P18="$R18/prod"
mkdir -p "$P18/wp-content/uploads" "$P18/wp-content/uploads-new" \
         "$P18/wp-content/themes" "$P18/wp-content/themes-new" \
         "$P18/wp-content/plugins" "$P18/wp-content/plugins-new"
echo "ORIG" > "$P18/wp-content/uploads/f"
echo "STAGED" > "$P18/wp-content/uploads-new/f"
for d in themes plugins; do
  echo "ORIG-$d" > "$P18/wp-content/$d/f"
  echo "STAGED-$d" > "$P18/wp-content/$d-new/f"
done
install_mv_shim
MV_SQUAT_AFTER_SOURCES="$P18/wp-content/uploads"   # reoccupy uploads/ right after it is set aside
NEST_HIT=0
finalize_new_content_dirs "$P18" 'NEST_HIT=1'
remove_mv_shim

assert_absent "the staged tree was not nested under production" "$P18/wp-content/uploads/uploads-new"
assert_eq "production holds the staged content, at the top level" "STAGED" "$(cat "$P18/wp-content/uploads/f" 2>/dev/null)"
assert_eq "the deploy completed" "0" "$NEST_HIT"
# The reoccupied directory took the squatter path, so what the live request wrote is kept.
NEST_SQUAT="$(find "$P18/wp-content" -maxdepth 1 -name 'uploads-squatter-*' -type d | head -1)"
assert_present "and the concurrently written file is preserved" "$NEST_SQUAT/2026/10/x.jpg"

echo "== the squatter retry is refused when production was not set aside this run =="

# The retry moves whatever is sitting at production's name out of the way, which is only safe when we
# KNOW production is backed up: we set it aside ourselves this run (ASIDE=1) and "-old" still holds it.
# Without that guard the thing being moved away could BE the only copy of production -- an NFS is_dir()
# misread leaving ASIDE=0, or a concurrent restore having just put production back.
#
# Here there is no production to set aside, so ASIDE stays 0. The promote then fails with something at
# production's name, and the retry must decline rather than move it.
R17="$(mktemp -d)"; P17="$R17/prod"
mkdir -p "$P17/wp-content/uploads-new" "$P17/wp-content/themes" "$P17/wp-content/themes-new" \
         "$P17/wp-content/plugins" "$P17/wp-content/plugins-new"
echo "STAGED" > "$P17/wp-content/uploads-new/f"
for d in themes plugins; do
  echo "ORIG-$d" > "$P17/wp-content/$d/f"
  echo "STAGED-$d" > "$P17/wp-content/$d-new/f"
done
install_mv_shim
MV_FAIL_SOURCES="$P17/wp-content/uploads-new"
MV_SQUAT_SOURCES="$P17/wp-content/uploads-new"
NOASIDE_HIT=0
finalize_new_content_dirs "$P17" 'NOASIDE_HIT=1'
remove_mv_shim

assert_eq "the failure is reported rather than retried around" "1" "$NOASIDE_HIT"
assert_eq "whatever was at production's name is left exactly where it was" "" \
  "$(find "$P17/wp-content" -maxdepth 1 -name 'uploads-squatter-*' | head -1)"
assert_present "and is still being served" "$P17/wp-content/uploads/2026/10/x.jpg"

echo "== a rollback that cannot restore in place parks production instead of losing it =="

# Both renames fail: the promote, and then the rollback's restore of "-old" back to production. The
# only copy of production is at "-old", and leaving it there would let the NEXT deploy's sweep meet an
# "-old" with production's name also occupied -- the ambiguous case. So it is moved to a parked name
# here, where no sweep will ever touch it.
R16="$(mktemp -d)"; P16="$R16/prod"
mkdir -p "$P16/wp-content/uploads" "$P16/wp-content/uploads-new" \
         "$P16/wp-content/themes" "$P16/wp-content/themes-new" \
         "$P16/wp-content/plugins" "$P16/wp-content/plugins-new"
echo "THE ONLY COPY" > "$P16/wp-content/uploads/f"
echo "STAGED" > "$P16/wp-content/uploads-new/f"
for d in themes plugins; do
  echo "ORIG-$d" > "$P16/wp-content/$d/f"
  echo "STAGED-$d" > "$P16/wp-content/$d-new/f"
done
install_mv_shim
MV_FAIL_SOURCES="$P16/wp-content/uploads-new $P16/wp-content/uploads-old"
PARK_HIT=0
finalize_new_content_dirs "$P16" 'PARK_HIT=1'
remove_mv_shim

assert_eq "the failure was reported" "1" "$PARK_HIT"
PARKED_16="$(find "$P16/wp-content" -maxdepth 1 -name 'uploads-recovered-*' -type d | head -1)"
assert_eq "production was preserved under a parked name" "THE ONLY COPY" "$(cat "$PARKED_16/f" 2>/dev/null)"
assert_absent "nothing was left at the -old name for a later sweep to puzzle over" "$P16/wp-content/uploads-old"

trap - EXIT
rm -rf "$ROOT" "$R2" "$R3" "$R4" "$R5" "$R6" "$R7" "$R8" "$R9" "$R10" "$R11" "$R12" "$R13" \
       "$R14" "$R15" "$R16" "$R17" "$R18" "$LOG_FILE" 2>/dev/null

echo
echo "-----------------------------------------"
printf 'content-dir tests: %d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
