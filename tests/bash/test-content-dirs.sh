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
#     failure it restores production from the aside, and it leaves no "-new"/"-old" leftovers.
#
# The staging script runs server-side via PHP exec() and is not exercised by the PHP (wpunit) or
# Playwright suites, so these bash tests are its only automated coverage. They do real filesystem
# work in temp dirs; no database or wp-cli is required. Hardlink identity is checked with the POSIX
# `-ef` test so the suite runs the same on Linux CI and on macOS.
#
# Run: bash tests/bash/test-content-dirs.sh

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAGING_SCRIPT="$SCRIPT_DIR/../../lib/.staging"

if [ ! -f "$STAGING_SCRIPT" ]; then
  echo "FATAL: cannot find lib/.staging at $STAGING_SCRIPT" >&2
  exit 1
fi

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

echo "== finalize_new_content_dirs promotes atomically and leaves no leftovers =="

finalize_new_content_dirs "$PROD"

assert_eq "production has the unchanged file" "shared" "$(cat "$PROD/wp-content/uploads/shared.txt" 2>/dev/null)"
assert_eq "production gained the staging-only file" "brand new" "$(cat "$PROD/wp-content/uploads/new.txt" 2>/dev/null)"
assert_eq "production took the changed file" "v2-longer" "$(cat "$PROD/wp-content/themes/style.css" 2>/dev/null)"
assert_absent "obsolete file is gone from production" "$PROD/wp-content/uploads/old.txt"
assert_absent "no uploads-new leftover" "$PROD/wp-content/uploads-new"
assert_absent "no uploads-old leftover" "$PROD/wp-content/uploads-old"
assert_absent "no themes-new leftover" "$PROD/wp-content/themes-new"
assert_absent "no themes-old leftover" "$PROD/wp-content/themes-old"

echo "== finalize_new_content_dirs preserves production when promotion fails (rollback) =="

# Production has live content; no prepared "-new" exists, so the promotion rename must fail and
# trigger the rollback. This is the regression guard for the old destroy-then-rename behaviour,
# which would have deleted production before discovering it could not complete.
R2="$(mktemp -d)"; P2="$R2/prod"
mkdir -p "$P2/wp-content/uploads"
echo "LIVE DATA" > "$P2/wp-content/uploads/keep.txt"

ROLLBACK_HIT=0
finalize_new_content_dirs "$P2" 'ROLLBACK_HIT=1'

assert_eq "error/rollback path was taken" "1" "$ROLLBACK_HIT"
assert_present "production uploads directory still exists after failed promotion" "$P2/wp-content/uploads"
assert_eq "production content is intact (not lost) after failed promotion" "LIVE DATA" "$(cat "$P2/wp-content/uploads/keep.txt" 2>/dev/null)"
assert_absent "no uploads-old aside left stranded" "$P2/wp-content/uploads-old"

echo "== finalize is all-or-nothing across dirs: one failing dir rolls the others back =="

# uploads and themes have valid prepared trees; plugins has none, so its promotion fails. Because
# asides are only deleted after every dir promotes, uploads and themes must roll back to their
# ORIGINAL production content, not be left half-deployed with the new content.
R4="$(mktemp -d)"; P4="$R4/prod"
mkdir -p "$P4/wp-content/uploads" "$P4/wp-content/uploads-new" \
         "$P4/wp-content/themes"  "$P4/wp-content/themes-new" \
         "$P4/wp-content/plugins"
echo "ORIG-UP" > "$P4/wp-content/uploads/f";  echo "NEW-UP" > "$P4/wp-content/uploads-new/f"
echo "ORIG-TH" > "$P4/wp-content/themes/f";   echo "NEW-TH" > "$P4/wp-content/themes-new/f"
echo "ORIG-PL" > "$P4/wp-content/plugins/f"   # no plugins-new -> plugins promotion fails

XDIR_HIT=0
finalize_new_content_dirs "$P4" 'XDIR_HIT=1'

assert_eq "cross-dir failure took the error path" "1" "$XDIR_HIT"
assert_eq "uploads rolled back to original content" "ORIG-UP" "$(cat "$P4/wp-content/uploads/f" 2>/dev/null)"
assert_eq "themes rolled back to original content" "ORIG-TH" "$(cat "$P4/wp-content/themes/f" 2>/dev/null)"
assert_eq "plugins restored to original content" "ORIG-PL" "$(cat "$P4/wp-content/plugins/f" 2>/dev/null)"
assert_absent "no uploads-old left after rollback" "$P4/wp-content/uploads-old"
assert_absent "no themes-old left after rollback" "$P4/wp-content/themes-old"

echo "== finalize normalizes a stale -old left by an interrupted prior run =="

# Production in place plus a leftover -old: the leftover is disposable and must be dropped, and the
# deploy must still promote cleanly (regression guard for the mv-nesting bug: a stale -old must never
# cause production to be moved INTO it).
R5="$(mktemp -d)"; P5="$R5/prod"
mkdir -p "$P5/wp-content/uploads" "$P5/wp-content/uploads-new" "$P5/wp-content/uploads-old" \
         "$P5/wp-content/themes" "$P5/wp-content/themes-new" \
         "$P5/wp-content/plugins" "$P5/wp-content/plugins-new"
echo "PROD" > "$P5/wp-content/uploads/f"
echo "STAGED" > "$P5/wp-content/uploads-new/f"
echo "LEFTOVER" > "$P5/wp-content/uploads-old/f"
finalize_new_content_dirs "$P5"
assert_eq "promoted staged content despite stale -old" "STAGED" "$(cat "$P5/wp-content/uploads/f" 2>/dev/null)"
assert_absent "stale -old cleared, not nested" "$P5/wp-content/uploads/uploads-old"
assert_absent "no uploads-old remains" "$P5/wp-content/uploads-old"

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
# -old must not have been blindly rm'd while it was the only copy; after a successful deploy uploads
# holds the staged content and no -old remains.
assert_eq "uploads holds staged content after deploy" "STAGED6" "$(cat "$P6/wp-content/uploads/f" 2>/dev/null)"
assert_absent "sole-production -old was consumed, not left stranded" "$P6/wp-content/uploads-old"

echo "== cleanup sweeps -new always, and -old only when it is not the sole production copy =="

# -new is always disposable. -old is disposable only when live production is in place; if production
# is missing, -old is the sole copy (a prior run died mid-swap) and cleanup must RESTORE it, not delete
# it. cleanup runs on a resumed deploy before finalize, so this rule has to live here too.
R3="$(mktemp -d)"; P3="$R3/prod"
mkdir -p "$P3/wp-content/uploads-new" \
         "$P3/wp-content/themes" "$P3/wp-content/themes-old" \
         "$P3/wp-content/plugins-old"
echo x > "$P3/wp-content/uploads-new/f"
echo "LIVE-TH" > "$P3/wp-content/themes/f"            # production present -> themes-old disposable
echo "DISPOSABLE-TH" > "$P3/wp-content/themes-old/f"
echo "SOLE-PL" > "$P3/wp-content/plugins-old/f"       # no plugins/ -> plugins-old is the only copy

cleanup_failed_new_content_dirs "$P3"

assert_absent "cleanup removed disposable -new" "$P3/wp-content/uploads-new"
assert_absent "cleanup removed -old when production present" "$P3/wp-content/themes-old"
assert_eq "cleanup left production themes untouched" "LIVE-TH" "$(cat "$P3/wp-content/themes/f" 2>/dev/null)"
assert_eq "cleanup RESTORED sole-copy -old to production (did not delete it)" "SOLE-PL" "$(cat "$P3/wp-content/plugins/f" 2>/dev/null)"
assert_absent "restored plugins-old no longer present" "$P3/wp-content/plugins-old"

trap - EXIT
rm -rf "$ROOT" "$R2" "$R3" "$R4" "$R5" "$R6" "$LOG_FILE" 2>/dev/null

echo
echo "-----------------------------------------"
printf 'content-dir tests: %d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
