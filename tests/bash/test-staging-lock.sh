#!/usr/bin/env bash
#
# Unit tests for the staging command mutex in lib/.staging (lock_check / release_staging_lock).
#
# The mutex is what stops two staging operations running against one site's trees at the same time:
# a clone rsyncing staging while a deploy enumerates it, a destroy tearing down what a copy is
# reading, a legacy deploy promoting over a half-built "-new" tree. Everything here is about who is
# allowed to take the lock from whom, which is the part that was wrong before this change:
#
#   - it was a read-then-write pair with nothing atomic in the middle, so two commands that looked at
#     the same instant could both decide the lock was theirs;
#   - any lock older than the TTL was declared expired and taken, with no test for whether its holder
#     was still running. An operation that outlives the PHP request that started it (PHP-FPM kills
#     the worker on its request timeout, the bash process carries on) would therefore lose its lock
#     while still writing files;
#   - it had no notion of one logical job spanning several bash processes, which is what the stepped
#     content deploy is: setup, then PHP copy bursts, then core, then finalize.
#
# The lock is now a file under production's nfd-private holding `active:<REFRESHED_AT>:<TOKEN>:<PID>`,
# which is also why these tests can exist at all -- no database and no wp-cli, just the filesystem.
# The PHP half of the same lock is covered by StagingContentCopyWPUnitTest.
#
# Run: bash tests/bash/test-staging-lock.sh

# Most of the variables this file sets are read by the SOURCED lib/.staging, not by this file. A
# linter cannot follow a dynamic source, so it reports every fixture variable as unused; the
# file-wide SC2034 exemption below is what keeps its output usable. (A comment whose first word is
# the linter's own name is parsed as a directive, hence the roundabout phrasing.)
# shellcheck disable=SC2034

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STAGING_SCRIPT="$SCRIPT_DIR/../../lib/.staging"

if [ ! -f "$STAGING_SCRIPT" ]; then
  echo "FATAL: cannot find lib/.staging at $STAGING_SCRIPT" >&2
  exit 1
fi

# Source the script. Its `BASH_SOURCE == $0` guards are false when sourced, so the entrypoint and the
# top-level wp bootstrap are skipped; the tested functions get their inputs as arguments and globals.
set -- compat_check token /nonexistent/prod /nonexistent/stg http://p.test http://s.test 0 id slug name
# shellcheck disable=SC1090
source "$STAGING_SCRIPT"
set +e              # the script enables `set -e`; tests assert on non-zero returns, so turn it off
trap - EXIT         # drop the script's cleanup trap (it would shell out to wp on test exit)

LOG_FILE="$(mktemp)"

# Neutralise the lifecycle helpers that would shell out to wp, and make error() non-fatal so a
# blocked lock_check reports and returns instead of killing the run. release_staging_lock is NOT
# stubbed here: it is under test.
# Captured BEFORE the stub below replaces it: one test runs the real error() to prove that error()
# itself is what clears STAGING_LOCK_KEEP.
REAL_ERROR="$(declare -f error)"

cleanup() { :; }
resync_cached_options() { :; }
write_deploy_result() { :; }
# $2 is the diagnostic step (e.g. lock_check:held_by_live_process). Capturing it lets a test assert
# WHICH refusal it got: several paths return the same customer-facing message, so asserting only the
# message lets a test pass on a refusal it was not testing for.
LAST_ERROR=""
LAST_ERROR_STEP=""
error() { LAST_ERROR="$1"; LAST_ERROR_STEP="${2:-}"; return 1; }

# Saved so a test that stubs the guard helpers can put the real ones back without re-sourcing the
# script (a re-source would also reinstate the real error(), which exits).
REAL_GUARD_FNS="$(declare -f lock_guard_available; declare -f lock_guard_enter)"

# The compat transient channel reaches WordPress through WP-CLI, which is not available here, so it
# is stubbed into recorders. Without the stubs these tests would depend on `wp` being absent and
# failing, which is a pass for the wrong reason. The real parsing is tested separately against a
# stubbed `wp`.
REAL_LEGACY_FNS="$(declare -f legacy_lock_held; declare -f legacy_lock_announce; declare -f legacy_lock_withdraw)"
LEGACY_HELD=0
LEGACY_ANNOUNCED=0
LEGACY_WITHDRAWN=0
legacy_lock_held() { [ "$LEGACY_HELD" = "1" ]; }
legacy_lock_announce() { LEGACY_ANNOUNCED=$((LEGACY_ANNOUNCED + 1)); }
legacy_lock_withdraw() { LEGACY_WITHDRAWN=$((LEGACY_WITHDRAWN + 1)); }

# Clearing an abandoned lock requires the kernel guard (see lock_guard_enter in lib/.staging), which
# needs util-linux `flock`. That is present on the cPanel boxes and in CI (Linux) but not on macOS,
# where lock_check deliberately refuses to steal instead. Gate the steal assertions on it rather than
# fail a macOS dev run; CI covers them.
HAVE_FLOCK=0
command -v flock >/dev/null 2>&1 && HAVE_FLOCK=1

PASS=0
FAIL=0
SKIP=0
skip() { SKIP=$((SKIP + 1)); printf '  skip - %s (no flock on this host)\n' "$1"; }
ok() { PASS=$((PASS + 1)); printf '  ok   - %s\n' "$1"; }
no() { FAIL=$((FAIL + 1)); printf '  FAIL - %s\n' "$1"; [ -n "${2:-}" ] && printf '         %s\n' "$2"; }
assert_eq() { if [ "$2" = "$3" ]; then ok "$1"; else no "$1" "expected [$2], got [$3]"; fi; }
assert_ne() { if [ "$2" != "$3" ]; then ok "$1"; else no "$1" "expected anything but [$2]"; fi; }
assert_absent() { if [ -e "$2" ]; then no "$1" "[$2] should not exist"; else ok "$1"; fi; }
assert_present() { if [ -e "$2" ]; then ok "$1"; else no "$1" "[$2] should exist"; fi; }

# Field accessors over the lock value, matching what lock_check and StagingCommandLock parse.
lock_field() { printf '%s' "$(cat "$LOCK_FILE" 2>/dev/null)" | awk -F':' -v f="$1" '{print $f}'; }
lock_ts() { lock_field 2; }
lock_token() { lock_field 3; }
lock_pid() { lock_field 4; }

# Each test runs against its own production dir, with the globals lock_check reads reset.
new_fixture() {
  ROOT="$(mktemp -d)"
  PRODUCTION_DIR="$ROOT/prod"
  mkdir -p "$PRODUCTION_DIR/nfd-private"
  LOCK_FILE="$PRODUCTION_DIR/nfd-private/nfd-staging-command.lock"
  LOCK_GUARD_FILE="$LOCK_FILE.guard"
  # Short, so a test that expects a held guard to block does not wait out the production timeout.
  LOCK_GUARD_WAIT=1
  STAGING_LOCK_OWNED=0
  STAGING_LOCK_KEEP=0
  STAGING_LOCK_TOKEN=""
  STAGING_LOCK_OWNER_PID=""
  LAST_ERROR=""
  LAST_ERROR_STEP=""
  LEGACY_HELD=0
  LEGACY_ANNOUNCED=0
  LEGACY_WITHDRAWN=0
}

# Write a lock as if another process held it. $1 age in seconds, $2 token, $3 pid.
plant_lock() {
  local age="$1"; local token="$2"; local pid="$3"
  printf 'active:%s:%s:%s' "$(( $(date +%s) - age ))" "$token" "$pid" > "$LOCK_FILE"
}

# A pid that is certainly running and is NOT this shell: a background sleep, cleaned up at exit.
#
# Sets LIVE_PID rather than printing it, and callers use "$LIVE_PID" instead of "$(live_pid)". A
# command substitution runs in a SUBSHELL, so the `LIVE_PIDS+=` inside one is thrown away with it and
# cleanup_live_pids never sees the pid: every call leaked a `sleep 300` reparented to PID 1. Harmless
# for the suite (they expire on their own, and their stdout is closed so they cannot hold a pipe open)
# but it meant the registry was decorative, and anything added to it later would be too.
LIVE_PIDS=()
LIVE_PID=""
live_pid() {
  # stdout/stderr go to /dev/null: a background child holding the script's stdout open would keep a
  # pipe (`bash this-script | grep`) from seeing EOF until the sleep expired. `9>&-` because a child
  # also inherits fd 9, and with it the guard's flock if one is held when it forks, which would keep
  # the guard locked by a sleep long after its parent let go.
  sleep 300 >/dev/null 2>&1 9>&- &
  LIVE_PID=$!
  LIVE_PIDS+=("$LIVE_PID")
}
cleanup_live_pids() {
  local p
  for p in "${LIVE_PIDS[@]:-}"; do
    [ -n "$p" ] && kill "$p" 2>/dev/null
  done
}

# A pid that cannot belong to a running process, being above the pid_max ceiling of both Linux
# (4194304) and macOS (99998). lock_holder_alive accepts it as digits greater than 1, then both
# `kill -0` and `ps -p` fail on it, which is exactly the "holder is gone" case.
#
# A constant rather than forking a process and killing it. That version had to `kill` and then
# `wait` a child from inside a command substitution, where the kill can lose the race with the fork
# and leave `wait` blocked on a live `sleep` for its full 300 seconds. It hung the suite on Linux.
DEAD_PID=99999999

echo "== lock_check takes a free lock =="

new_fixture
lock_check
assert_eq "lock_check succeeds when no lock exists" "0" "$?"
assert_present "lock file is created" "$LOCK_FILE"
assert_eq "caller is recorded as the owner" "1" "$STAGING_LOCK_OWNED"
assert_eq "lock records this process's pid" "$$" "$(lock_pid)"
assert_ne "lock carries a generated token" "" "$(lock_token)"

new_fixture
lock_check "mysuppliedtoken1"
assert_eq "lock_check accepts a supplied token" "0" "$?"
assert_eq "supplied token is written into the lock" "mysuppliedtoken1" "$(lock_token)"

echo "== lock_check refuses a lock held by another operation =="

new_fixture
plant_lock 5 "someoneelsestoken" "$$"
lock_check "ourtoken"
assert_eq "lock_check fails while a fresh foreign lock is held" "1" "$?"
assert_eq "the caller is told the action is locked" "Staging action is locked by another command." "$LAST_ERROR"
assert_eq "the holder's lock is left untouched" "someoneelsestoken" "$(lock_token)"
assert_eq "the blocked caller does not own the lock" "0" "$STAGING_LOCK_OWNED"

# The loser of the race must never free the winner's lock on its way out.
release_staging_lock
assert_present "a blocked caller's release leaves the holder's lock in place" "$LOCK_FILE"
assert_eq "holder's token survives the loser's release" "someoneelsestoken" "$(lock_token)"

echo "== a stepped job reclaims and refreshes its OWN lock across verbs =="

# setup/core/finalize are three bash processes minutes or hours apart, with PHP copy bursts between
# them. Presenting the job's token is what makes them one hold on the mutex instead of three.
new_fixture
plant_lock 900 "jobtoken123" "$DEAD_PID"
OLD_TS="$(lock_ts)"
lock_check "jobtoken123"
assert_eq "a later verb of the same job takes the lock" "0" "$?"
assert_eq "it keeps the job's token" "jobtoken123" "$(lock_token)"
assert_eq "it owns the lock and may release it" "1" "$STAGING_LOCK_OWNED"
if [ "$(lock_ts)" -gt "$OLD_TS" ]; then ok "the lock's timestamp is pushed forward"; else no "the lock's timestamp is pushed forward" "was $OLD_TS, now $(lock_ts)"; fi
assert_eq "the refreshed lock records the current process" "$$" "$(lock_pid)"

# ...but a matching token is not a licence to reclaim a lock whose holder is STILL RUNNING. The case
# that matters: a verb's PHP worker is killed on the FPM request timeout while the bash verb carries
# on as an orphan, still moving directories. A retry of the same job must wait for that orphan rather
# than build trees into what it is about to replace.
new_fixture
live_pid
ORPHAN_PID="$LIVE_PID"   # stands in for the orphaned verb: a live process other than this shell
plant_lock 2400 "jobtoken123" "$ORPHAN_PID"
lock_check "jobtoken123"
assert_eq "a retry does NOT reclaim its own lock while an earlier step is still running" "1" "$?"
assert_eq "the caller is told the action is locked" "Staging action is locked by another command." "$LAST_ERROR"
assert_eq "the running step keeps the lock" "jobtoken123" "$(lock_token)"
assert_eq "the orphan is still recorded as the holder" "$ORPHAN_PID" "$(lock_pid)"
assert_eq "the blocked retry does not own the lock" "0" "$STAGING_LOCK_OWNED"

# A pid that outlives its job by being recycled onto something else cannot pin the mutex forever.
new_fixture
live_pid
plant_lock "$((LOCK_HARD_TTL + 60))" "jobtoken123" "$LIVE_PID"
lock_check "jobtoken123"
assert_eq "past the hard ceiling the job reclaims its own lock anyway" "0" "$?"
assert_eq "it keeps the job's token" "jobtoken123" "$(lock_token)"

echo "== an abandoned lock is taken over, a live one is not =="

if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  plant_lock 2000 "deadjobtoken" "$DEAD_PID"
  lock_check "newtoken"
  assert_eq "a lock past the TTL whose holder is gone is taken" "0" "$?"
  assert_eq "the new owner's token replaces it" "newtoken" "$(lock_token)"
else
  skip "a lock past the TTL whose holder is gone is taken"
fi

# This is the case a TTL-only rule got wrong: a clone that outlived its PHP request and has been
# rsyncing for more than 30 minutes is still writing the trees a deploy would enumerate.
new_fixture
live_pid
plant_lock 2000 "longrunningclone" "$LIVE_PID"
lock_check "deploytoken"
assert_eq "a lock past the TTL whose holder is STILL RUNNING is refused" "1" "$?"
assert_eq "the long-running holder keeps its lock" "longrunningclone" "$(lock_token)"
if [ "$HAVE_FLOCK" = "1" ]; then
  # Without this the test would also pass via the no-guard refusal, which reports the same message
  # for a different reason and would hide a broken liveness test.
  assert_eq "refused specifically because the holder is alive" "lock_check:held_by_live_process" "$LAST_ERROR_STEP"
else
  skip "refused specifically because the holder is alive"
fi

# ...but a live pid cannot pin the mutex forever, in case the pid was recycled onto something else.
if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  live_pid
  plant_lock "$((LOCK_HARD_TTL + 60))" "ancientlock" "$LIVE_PID"
  lock_check "deploytoken"
  assert_eq "past the hard ceiling even a live holder is cleared" "0" "$?"
  assert_eq "the new owner's token replaces it" "deploytoken" "$(lock_token)"
else
  skip "past the hard ceiling even a live holder is cleared"
fi

echo "== a lock with no readable timestamp is aged from its mtime, never assumed free =="

new_fixture
printf 'garbage' > "$LOCK_FILE"
lock_check "deploytoken"
assert_eq "a freshly written unparseable lock blocks (crash between create and write)" "1" "$?"
assert_eq "the unparseable lock is left in place" "garbage" "$(cat "$LOCK_FILE")"

if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  printf 'garbage' > "$LOCK_FILE"
  touch -t 200001010000 "$LOCK_FILE"
  lock_check "deploytoken"
  assert_eq "an old unparseable lock is cleared" "0" "$?"
  assert_eq "the new owner's token replaces it" "deploytoken" "$(lock_token)"
else
  skip "an old unparseable lock is cleared"
fi

echo "== a malformed token is refused outright =="

new_fixture
lock_check "has:colons"
assert_eq "a token with a colon is refused (it would forge lock fields)" "1" "$?"
assert_eq "the caller is told the token is invalid" "Invalid staging lock token." "$LAST_ERROR"
assert_absent "no lock is taken under a malformed token" "$LOCK_FILE"

new_fixture
lock_check 'rm -rf /'
assert_eq "a token with shell metacharacters is refused" "1" "$?"
assert_absent "no lock is taken under a malformed token" "$LOCK_FILE"

new_fixture
lock_check "$(printf 'a%.0s' $(seq 1 65))"
assert_eq "an over-long token is refused" "1" "$?"
assert_absent "no lock is taken under an over-long token" "$LOCK_FILE"

echo "== release_staging_lock frees the lock only for its owner, and only when the job is over =="

new_fixture
lock_check "ownedtoken"
release_staging_lock
assert_absent "the owner's release removes the lock" "$LOCK_FILE"
assert_eq "ownership is dropped" "0" "$STAGING_LOCK_OWNED"

new_fixture
STAGING_LOCK_OWNED=0
plant_lock 5 "foreigntoken" "$$"
release_staging_lock
assert_present "a non-owner's release is a no-op" "$LOCK_FILE"

# setup and core set STAGING_LOCK_KEEP so the mutex outlives their process: the PHP controller
# refreshes it between copy bursts and finalize is what finally frees it.
new_fixture
lock_check "steppedjobtoken"
STAGING_LOCK_KEEP=1
release_staging_lock
assert_present "a stepped verb mid-job keeps the lock on exit" "$LOCK_FILE"
assert_eq "the job's token is still on it" "steppedjobtoken" "$(lock_token)"
assert_eq "the verb still considers itself the owner" "1" "$STAGING_LOCK_OWNED"

# A verb that FAILS is a clean exit with nothing left running, so error() clears KEEP and the lock is
# freed; the next attempt should not have to wait out the TTL.
# Runs the REAL error(), restored from the script, rather than hand-clearing KEEP: the property under
# test is that error() itself clears it, so setting it by hand would pass even if error() stopped.
#
# error() ends in `exit 1`, and the obvious way to survive that is to call it inside `( ... )`. Not
# here: release_staging_lock refuses to free a lock from any shell but the one that took it, so a
# subshell would be refused for THAT reason and this test would pass without error() clearing KEEP at
# all. Shadowing `exit` keeps the call in the owning shell, which is also where it runs in production.
new_fixture
lock_check "steppedjobtoken"
assert_present "the stepped verb holds the lock before it fails" "$LOCK_FILE"
eval "$REAL_ERROR"
exit() { return "${1:-0}"; }   # error() ends in `exit 1`
printf() { :; }                # error() prints the failure JSON on stdout; keep the test output clean
STAGING_LOCK_KEEP=1            # as deploy_content_setup leaves it on its success path
error 'something failed' 'test:failing_verb' >/dev/null 2>&1
unset -f exit printf
error() { LAST_ERROR="$1"; LAST_ERROR_STEP="${2:-}"; return 1; }
assert_absent "a failing stepped verb frees the lock (error() clears KEEP)" "$LOCK_FILE"
assert_eq "and withdraws our publication from the compat channel too" "1" "$LEGACY_WITHDRAWN"

echo "== clearing an abandoned lock is serialized by the kernel guard =="

# This is the bug the guard exists for. Deciding a lock is abandoned reads its CONTENTS; clearing it
# acts on the PATH. Unguarded those are two steps, so a racer that examined the same abandoned lock a
# moment earlier goes on to clear the WINNER's fresh lock and create its own on top, and both end up
# holding the mutex. The guard closes the gap, and the kernel releases it if the holder dies, so it
# cannot go stale the way the lock it protects can.
if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  plant_lock 2000 "abandonedtoken" "$DEAD_PID"

  # Stand in for the racer that gets there first: take the guard, announce it, then DO what the
  # guarded section does -- replace the abandoned lock with its own fresh one -- and only then let go.
  #
  # The waiter below must be made to reach its verdict through the guard ORDERING it, not by giving up
  # on the wait: with a wait long enough to outlast the holder, the only way it can report "held" and
  # leave a foreign token in place is by having entered the guard afterwards and found the winner's
  # fresh lock. Unguarded, this is the interleaving where it would instead clear that fresh lock and
  # create its own on top, leaving two operations holding the mutex.
  #
  # `9>&-` on the inner sleep matters: a child inherits fd 9 and with it the flock, so a sleep that
  # outlives its parent would keep the guard held after the holder had exited.
  HELD_MARKER="$ROOT/guard-held"
  (
    exec >/dev/null 2>&1   # see the holder below: a child on this script's stdout stalls a piped run
    exec 9>>"$LOCK_GUARD_FILE"
    flock 9
    : > "$HELD_MARKER"
    # Long enough that the waiter is demonstrably parked on the guard before the lock is replaced.
    sleep 1 9>&-
    # $BASHPID, not $$: inside a subshell $$ is still the PARENT shell's pid. (That is exactly the
    # semantic lock_write_exclusive relies on -- its create runs in a subshell and must record the
    # long-lived script process, not the subshell -- but here the holder wants its own pid.)
    printf 'active:%s:%s:%s' "$(date +%s)" "winnerstoken" "$BASHPID" > "$LOCK_FILE"
  ) &
  GUARD_HOLDER=$!
  LIVE_PIDS+=("$GUARD_HOLDER")
  until [ -e "$HELD_MARKER" ]; do sleep 0.05; done

  LOCK_GUARD_WAIT=10   # must outlast the holder, so the verdict comes from ordering, not a timeout
  lock_check "racertoken"
  assert_eq "a racer that arrives mid-steal is refused, not allowed to steal in turn" "1" "$?"
  assert_eq "the caller is told the action is locked" "Staging action is locked by another command." "$LAST_ERROR"
  assert_eq "it saw the winner's fresh lock, not the abandoned one it set out to clear" "winnerstoken" "$(lock_token)"
  assert_eq "and left that lock alone" "$GUARD_HOLDER" "$(lock_pid)"
  assert_eq "the refused racer does not own the lock" "0" "$STAGING_LOCK_OWNED"

  wait "$GUARD_HOLDER" 2>/dev/null

  # With the guard free and the lock genuinely abandoned again, the same call clears it and takes it.
  plant_lock 2000 "abandonedtoken" "$DEAD_PID"
  lock_check "racertoken"
  assert_eq "once the guard is free an abandoned lock is cleared" "0" "$?"
  assert_eq "the new owner's token replaces it" "racertoken" "$(lock_token)"
else
  skip "an abandoned lock is not cleared while another process holds the guard"
fi

# A host with no `flock` must refuse rather than steal unguarded: blocking costs availability, an
# unguarded steal costs correctness. This is also the path macOS takes.
new_fixture
# Models a host where flock is genuinely absent, so BOTH helpers have to be stubbed: stubbing only
# lock_guard_enter would describe a host that has flock but could not take the guard, which is a
# different case with a deliberately different answer (it refuses outright -- see the next block).
lock_guard_available() { return 1; }
lock_guard_enter() { return 1; }
plant_lock 2000 "abandonedtoken" "$DEAD_PID"
lock_check "racertoken"
assert_eq "with no guard available an abandoned lock is refused, not stolen" "1" "$?"
assert_eq "the caller is told the action is locked" "Staging action is locked by another command." "$LAST_ERROR"
assert_eq "the abandoned lock is left in place" "abandonedtoken" "$(lock_token)"
assert_eq "the refused caller does not own the lock" "0" "$STAGING_LOCK_OWNED"
# Reclaiming this job's OWN lock needs no steal, so it still works on a host with no flock at all.
lock_check "abandonedtoken"
assert_eq "its own lock is still reclaimable on a host with no flock" "0" "$?"
# ...and a FRESH own lock too, which is the no-flock fallback inside lock_reclaim_own_fresh itself
# (the stale lock above is reclaimed by the contended path instead). Without this fallback a host
# with no flock could never run a stepped deploy at all: every bookend verb after setup finds its
# own lock in place and has to reclaim it.
STAGING_LOCK_OWNED=0
plant_lock 10 "abandonedtoken" "$DEAD_PID"
lock_check "abandonedtoken"
assert_eq "its own FRESH lock is still reclaimed on a host with no flock" "0" "$?"
assert_eq "and records this process as its holder" "$$" "$(lock_pid)"
eval "$REAL_GUARD_FNS"

echo "== on a host WITH flock, failing to take the guard refuses rather than arbitrating without it =="

# The distinction that matters: "this host has no flock" means degrade (refuse to steal, but still
# serve this job's own lock), while "flock exists and we could not get the guard" means somebody is
# arbitrating RIGHT NOW. Carrying on in the second case would do the own-token reclaim's
# read-then-write unguarded, which is the exact race the guard exists to prevent.
new_fixture
lock_guard_available() { return 0; }   # pretend flock exists
lock_guard_enter() { return 1; }       # ...but the guard cannot be taken
plant_lock 2000 "abandonedtoken" "$DEAD_PID"
lock_check "racertoken"
assert_eq "a guard that cannot be taken refuses the command" "1" "$?"
assert_eq "and says why" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"
assert_eq "the existing lock is untouched" "abandonedtoken" "$(lock_token)"
assert_eq "the refused caller does not own the lock" "0" "$STAGING_LOCK_OWNED"

# Even its own FRESH lock is not reclaimed unguarded on such a host. The fast path used to, which
# was a read-then-write race: two verbs of ONE job -- an orphaned bookend still in its WP-CLI
# bootstraps and the relaunched twin the next poll started -- could both read the previous verb's
# dead pid and both reclaim. Fresh-and-ours only short-circuits WITH the guard now; see the race
# test further down for the two-process version of this.
plant_lock 10 "ourjobtoken" "$DEAD_PID"
lock_check "ourjobtoken"
assert_eq "even its own fresh lock is not reclaimed when the guard cannot be taken" "1" "$?"
assert_eq "and says why" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"
assert_eq "the fresh lock is untouched" "ourjobtoken" "$(lock_token)"
assert_eq "its recorded holder is untouched" "$DEAD_PID" "$(lock_pid)"

# Even its OWN lock is not reclaimed unguarded on such a host, once it is past the reclaim margin.
plant_lock 2000 "ourjobtoken" "$DEAD_PID"
lock_check "ourjobtoken"
assert_eq "its own stale lock is not reclaimed without the guard either" "1" "$?"
assert_eq "and says why" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"

# release_staging_lock takes the same view for a lock it cannot prove is still its own: leave it to
# age out rather than delete it blind.
STAGING_LOCK_OWNED=1
STAGING_LOCK_TOKEN="ourjobtoken"
STAGING_LOCK_KEEP=0
touch -t 200001010000 "$LOCK_FILE"   # also stale by mtime, so no fresh-own shortcut applies
release_staging_lock
assert_present "release leaves the lock alone when it cannot take the guard" "$LOCK_FILE"
assert_eq "ownership is still dropped so nothing retries the delete" "0" "$STAGING_LOCK_OWNED"
unset -f lock_guard_available lock_guard_enter
# shellcheck disable=SC1090
eval "$REAL_GUARD_FNS"

echo "== release_staging_lock never removes a lock that has stopped being ours =="

# Past LOCK_HARD_TTL another operation may take the lock from a holder that is still alive. When that
# holder finally exits, an unconditional `rm` would delete the NEW holder's lock on the way out and
# leave it running unprotected.
new_fixture
lock_check "ourtoken"
assert_eq "we own the lock" "1" "$STAGING_LOCK_OWNED"
plant_lock 0 "successortoken" "$$"   # somebody took it from us while we ran
release_staging_lock
assert_present "the successor's lock survives our release" "$LOCK_FILE"
assert_eq "and still carries the successor's token" "successortoken" "$(lock_token)"

echo "== only one of two racing acquires can win =="

# The acquire is an O_CREAT|O_EXCL create, so exactly one of any number of racers can win it. Run
# them CONCURRENTLY: a sequential pair only proves "the second create fails because the file now
# exists", which an exists-then-write implementation would also satisfy. Each racer reports by
# creating its own marker file, which is atomic enough for a counting assertion.
new_fixture
RACER_DIR="$ROOT/racers"
mkdir -p "$RACER_DIR"
RACER_PIDS=()
for i in $(seq 1 20); do
  (
    STAGING_LOCK_TOKEN="racer$i"
    lock_write_exclusive && : > "$RACER_DIR/won.$i"
  ) &
  RACER_PIDS+=($!)
done
# Waits for the RACERS specifically, never a bare `wait`. A bare one waits for every background job
# this shell owns, which includes the `sleep 300` liveness stand-ins from the tests above -- five
# minutes of dead time added to every run, with nothing to show for it. (The archived version of this
# suite got away with a bare `wait` only because its live_pid ran inside a command substitution, so
# those sleeps were never in THIS shell's job table. Registering them properly is what exposed it.)
wait "${RACER_PIDS[@]}"
WINNERS=$(find "$RACER_DIR" -name 'won.*' | wc -l | tr -d ' ')
assert_eq "exactly one of 20 concurrent exclusive creates wins" "1" "$WINNERS"
assert_present "and the lock file it created is there" "$LOCK_FILE"

echo "== two verbs of one job cannot both reclaim the same lock =="

# The interleaving FPM-kill-plus-repoll opens in production: a bookend verb is launched, its PHP
# request is killed (freeing the controller's advisory flock) while the verb is still in its WP-CLI
# bootstraps, and the next poll -- seeing a lock that records only the PREVIOUS verb's dead pid --
# launches the same verb again. Both carry the job's token. The reclaim is a read, a liveness
# decision and a write, and unguarded both processes could read "holder gone" and both take the
# mutex; lock_reclaim_own_fresh now takes the kernel guard around that window, so the first writer
# records its own live pid and the second is refused.
#
# Two REAL processes, so pids and liveness are real. Racer A is parked between its first read of the
# lock file and everything after it (a one-shot `cat` shim, armed only for A); racer B runs
# unimpeded; then A is released and replays its paused reclaim. Each racer that gets the lock drops
# a win marker and stays alive until told to exit, because the other's refusal depends on a LIVE
# recorded pid. Exactly one may win, under either ordering the barrier produces.
if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  RACE_DIR="$ROOT/race"
  mkdir -p "$RACE_DIR"
  plant_lock 10 "racejobtoken" "$DEAD_PID"   # the previous verb's lock: fresh, holder gone

  RACER_SCRIPT="$RACE_DIR/racer.sh"
  cat > "$RACER_SCRIPT" <<'RACER'
#!/usr/bin/env bash
# One racer of the same-token reclaim race; see the calling test for the scenario. All NFD_* inputs
# arrive in the environment.
set -- compat_check tok "$NFD_PROD_DIR" /nonexistent/stg http://p.test http://s.test 0 id slug name
# shellcheck disable=SC1090
source "$NFD_STAGING_SCRIPT"
set +e
trap - EXIT
cleanup() { :; }
resync_cached_options() { :; }
write_deploy_result() { :; }
legacy_lock_held() { return 1; }
legacy_lock_announce() { :; }
legacy_lock_withdraw() { :; }
error() { exit 1; }
LOCK_GUARD_WAIT=20   # must outlast the barrier, so a verdict comes from ordering, not a timeout

if [ -n "${NFD_PAUSE_AFTER_READ:-}" ]; then
  # One-shot: park this process after its FIRST read of the lock file, mid-reclaim, until released.
  # The pause runs inside whatever subshell the read happens in; the marker files cross processes.
  cat() {
    command cat "$@"
    local st=$?
    if [ "${1:-}" = "$LOCK_FILE" ] && [ ! -e "$NFD_PAUSE_CONSUMED" ]; then
      : > "$NFD_PAUSE_CONSUMED"
      : > "$NFD_READ_MARK"
      while [ ! -e "$NFD_GO" ]; do sleep 0.05; done
    fi
    return $st
  }
fi

lock_check "$NFD_RACE_TOKEN" || exit 1
: > "$NFD_WIN_DIR/won.$NFD_RACER_NAME"
# Stay alive until told: the other racer's refusal depends on this process still running.
while [ ! -e "$NFD_EXIT_GO" ]; do sleep 0.05; done
exit 0
RACER

  export NFD_PROD_DIR="$PRODUCTION_DIR"
  export NFD_STAGING_SCRIPT="$STAGING_SCRIPT"
  export NFD_RACE_TOKEN="racejobtoken"
  export NFD_WIN_DIR="$RACE_DIR"
  export NFD_EXIT_GO="$RACE_DIR/exit-go"
  export NFD_PAUSE_CONSUMED="$RACE_DIR/pause-consumed"
  export NFD_READ_MARK="$RACE_DIR/read-mark"
  export NFD_GO="$RACE_DIR/go"

  NFD_RACER_NAME=A NFD_PAUSE_AFTER_READ=1 bash "$RACER_SCRIPT" >/dev/null 2>&1 &
  RACER_A=$!
  TRIES=0
  until [ -e "$NFD_READ_MARK" ] || [ "$TRIES" -ge 200 ]; do sleep 0.05; TRIES=$((TRIES + 1)); done
  assert_present "racer A reached its read and parked" "$NFD_READ_MARK"

  NFD_RACER_NAME=B bash "$RACER_SCRIPT" >/dev/null 2>&1 &
  RACER_B=$!
  sleep 1          # let B run into whichever gate applies before A is released
  : > "$NFD_GO"

  # Settled means: each racer has either dropped a win marker (and parked) or exited refused. A live
  # racer with no marker of its own is still mid-flight.
  race_settled() {
    if kill -0 "$RACER_A" 2>/dev/null && [ ! -e "$RACE_DIR/won.A" ]; then return 1; fi
    if kill -0 "$RACER_B" 2>/dev/null && [ ! -e "$RACE_DIR/won.B" ]; then return 1; fi
    return 0
  }
  TRIES=0
  until race_settled || [ "$TRIES" -ge 300 ]; do sleep 0.05; TRIES=$((TRIES + 1)); done

  RECLAIM_WINNERS=$(find "$RACE_DIR" -name 'won.*' | wc -l | tr -d ' ')
  assert_eq "exactly one of two same-token verbs reclaims the lock" "1" "$RECLAIM_WINNERS"
  assert_eq "the lock still carries the job's token" "racejobtoken" "$(lock_token)"
  if [ -e "$RACE_DIR/won.A" ]; then EXPECTED_WINNER_PID=$RACER_A; else EXPECTED_WINNER_PID=$RACER_B; fi
  assert_eq "and records the winner's live pid, which is what refused the loser" "$EXPECTED_WINNER_PID" "$(lock_pid)"

  : > "$NFD_EXIT_GO"
  wait "$RACER_A" "$RACER_B" 2>/dev/null
  unset NFD_PROD_DIR NFD_STAGING_SCRIPT NFD_RACE_TOKEN NFD_WIN_DIR NFD_EXIT_GO
  unset NFD_PAUSE_CONSUMED NFD_READ_MARK NFD_GO
  unset -f race_settled
else
  skip "exactly one of two same-token verbs reclaims the lock"
fi

echo "== only the shell that took the lock may free it =="

# A subshell inherits STAGING_LOCK_OWNED and the token, and `$$` does not change inside one, so
# neither of those distinguishes it from its parent. create() runs rewrite_htaccess in a command
# substitution and rewrite_htaccess ends in `|| error ...`, so without this an error there frees the
# lock its parent is still working under, while `exit 1` ends only the subshell and create() carries
# on unprotected.
new_fixture
lock_check "parentjobtoken"
assert_present "the parent holds the lock" "$LOCK_FILE"
( release_staging_lock ) >/dev/null 2>&1
assert_present "a subshell does not free its parent's lock" "$LOCK_FILE"
assert_eq "and the parent's token is still on it" "parentjobtoken" "$(lock_token)"
# The same call from the owning shell must still work, or the guard would just disable releasing.
release_staging_lock
assert_absent "the owning shell still frees it" "$LOCK_FILE"

# Driven through the real error(), which is how the subshell case is actually reached.
new_fixture
lock_check "parentjobtoken"
(
  eval "$REAL_ERROR"
  printf() { :; }
  error 'htaccess failed' 'test:subshell_error'
) >/dev/null 2>&1
assert_present "an error() inside a command substitution leaves the lock held" "$LOCK_FILE"
assert_eq "the parent is still recorded as the owner" "1" "$STAGING_LOCK_OWNED"

echo "== the compat transient channel is honoured and announced on =="

# An operation running an older copy of lib/.staging knows nothing about the lock file: its mutex is
# the nfd_staging_lock transient. Both directions have to work, or a new-code deploy and an old-code
# clone run at once and the clone rebuilds the staging tree the deploy is copying.
new_fixture
LEGACY_HELD=1
lock_check "ourtoken"
assert_eq "lock_check refuses while an old-code operation holds the compat lock" "1" "$?"
assert_eq "and says which check refused" "lock_check:legacy_lock_held" "$LAST_ERROR_STEP"
assert_absent "no lock file is created when the compat lock blocks" "$LOCK_FILE"

new_fixture
lock_check "ourtoken"
assert_eq "a clean acquire succeeds" "0" "$?"
assert_eq "and announces on the compat channel, so old code can see us" "1" "$LEGACY_ANNOUNCED"
release_staging_lock
assert_eq "releasing withdraws rather than leaving old code to wait out the TTL" "1" "$LEGACY_WITHDRAWN"

# A later bookend verb of the same stepped job reclaims the lock, and that has to re-announce: the
# copy phase between verbs runs in PHP, which cannot write a WordPress transient through WP-CLI.
new_fixture
plant_lock 900 "steppedjobtoken" "$DEAD_PID"
lock_check "steppedjobtoken"
assert_eq "a later verb reclaims its own lock" "0" "$?"
assert_eq "and re-announces on the compat channel" "1" "$LEGACY_ANNOUNCED"

echo "== legacy_lock_held reads the old value format correctly =="

# The real function this time, against a stubbed WP-CLI. The old value is "active:<ts>", and ours adds
# a third field holding the token; the old parser takes field 2 either way, which is what lets both
# sides read one value.
new_fixture
eval "$REAL_LEGACY_FNS"
LEGACY_VALUE=""
wp() { printf '%s' "$LEGACY_VALUE"; }
STAGING_LOCK_TOKEN="ourtoken"

LEGACY_VALUE=""
legacy_lock_held
assert_eq "no compat value does not block" "1" "$?"

LEGACY_VALUE="active:$(date +%s)"
legacy_lock_held
assert_eq "a fresh old-format value blocks" "0" "$?"

LEGACY_VALUE="active:$(( $(date +%s) - LOCK_TTL - 60 ))"
legacy_lock_held
assert_eq "a stale old-format value does not block" "1" "$?"

LEGACY_VALUE="active:$(date +%s):someoneelsestoken"
legacy_lock_held
assert_eq "another job's fresh publication blocks" "0" "$?"

LEGACY_VALUE="active:$(date +%s):ourtoken"
legacy_lock_held
assert_eq "our own publication does not block us" "1" "$?"

# Noise is NOT a lock. WP-CLI prints whatever the site's boot prints, so stdout can carry a
# deprecation notice, a BOM ahead of wp-config's `<?php`, or a chatty mu-plugin. Treating any
# non-empty stdout as a held lock refused every staging operation on such a site forever, because
# the noise is not a transient and nothing expires it.
LEGACY_VALUE="garbage"
legacy_lock_held
assert_eq "output that is not a lock value does not block" "1" "$?"

LEGACY_VALUE="PHP Deprecated: strlen(): passing null is deprecated in /x.php on line 1"
legacy_lock_held
assert_eq "a deprecation notice does not block" "1" "$?"

# ...but noise ALONGSIDE a real value still finds the value.
LEGACY_VALUE="PHP Deprecated: something
active:$(date +%s):someoneelsestoken"
legacy_lock_held
assert_eq "a real value is found behind the noise" "0" "$?"

# A well-formed prefix with an unreadable timestamp IS a lock, just a corrupt one, so it blocks.
# That is bounded: WordPress expires the transient on its own TTL.
LEGACY_VALUE="active:0notanumber"
legacy_lock_held
assert_eq "a corrupt lock value still blocks" "0" "$?"

echo "== withdrawing from the compat channel only ever clears our own publication =="

# A blind delete takes whatever is on the channel, including an older copy's live claim, and that copy
# neither writes nor reads the lock file -- the transient is all there is.
new_fixture
eval "$REAL_LEGACY_FNS"
LEGACY_VALUE=""
LEGACY_DELETE_CALLS=0
wp() {
  case "${2:-}" in
    get) printf '%s' "$LEGACY_VALUE" ;;
    delete) LEGACY_DELETE_CALLS=$((LEGACY_DELETE_CALLS + 1)); LEGACY_VALUE="" ;;
  esac
  return 0
}
STAGING_LOCK_TOKEN="ourtoken"

LEGACY_VALUE="active:$(date +%s)"
legacy_lock_withdraw
assert_eq "a foreign publication is left alone" "0" "$LEGACY_DELETE_CALLS"
assert_ne "and is still on the channel" "" "$LEGACY_VALUE"

LEGACY_VALUE="active:$(date +%s):ourtoken"
legacy_lock_withdraw
assert_eq "our own publication is deleted" "1" "$LEGACY_DELETE_CALLS"

unset -f wp
legacy_lock_held() { [ "$LEGACY_HELD" = "1" ]; }
legacy_lock_announce() { LEGACY_ANNOUNCED=$((LEGACY_ANNOUNCED + 1)); }
legacy_lock_withdraw() { LEGACY_WITHDRAWN=$((LEGACY_WITHDRAWN + 1)); }

echo "== an unopenable lock guard is refused, never replaced =="

# This used to `rm -f` a guard it could not open and recreate it, so stealing kept working after
# somebody had run the script as root. The replacement is itself unguarded, so two processes could
# each replace the other's fresh guard, hold flocks on different inodes, and both enter the
# arbitration section -- the double-hold the guard exists to prevent.
#
# The guard has to be a REGULAR file with no permissions, not a directory. A directory is unopenable
# too, but `rm -f` cannot remove one, so the replacement would fail by itself and the test would pass
# against the very code it is meant to reject. Permission bits do not apply to root, so this needs a
# normal user: the GitHub runner is one, a local docker shell usually is not.
if [ "$HAVE_FLOCK" = "1" ] && [ "$(id -u)" != "0" ]; then
  new_fixture
  eval "$REAL_GUARD_FNS"
  rm -f "$LOCK_GUARD_FILE"
  : > "$LOCK_GUARD_FILE"
  chmod 0000 "$LOCK_GUARD_FILE"
  GUARD_BEFORE="$(stat -c %i "$LOCK_GUARD_FILE" 2>/dev/null || stat -f %i "$LOCK_GUARD_FILE" 2>/dev/null)"
  lock_guard_enter
  assert_eq "lock_guard_enter refuses a guard it cannot open" "1" "$?"
  assert_present "it leaves the guard in place" "$LOCK_GUARD_FILE"
  assert_eq "and does not replace it with a new object" "$GUARD_BEFORE" "$(stat -c %i "$LOCK_GUARD_FILE" 2>/dev/null || stat -f %i "$LOCK_GUARD_FILE" 2>/dev/null)"

  # A contended lock_check must then refuse rather than arbitrate unguarded.
  plant_lock "$((LOCK_TTL + 600))" "someoneelsestoken" "$DEAD_PID"
  lock_check "ourtoken"
  assert_eq "a contended lock_check refuses without the guard" "1" "$?"
  assert_eq "and says the guard was unavailable" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"
  assert_eq "the abandoned lock is left for a host that can arbitrate" "someoneelsestoken" "$(lock_token)"
  chmod 0600 "$LOCK_GUARD_FILE" 2>/dev/null
else
  SKIP=$((SKIP + 1)); printf '  skip - unopenable-guard tests need flock and a non-root user\n'
fi

echo "== reclaiming this job's own fresh lock queues on the guard =="

# The fresh-own reclaim used to bypass a held guard entirely, which was the read-then-write race:
# two verbs of one job could both read the previous verb's dead pid and both reclaim. It now QUEUES
# on the guard (flock -w LOCK_GUARD_WAIT) like every other arbitration. In production the wait is
# 30s and arbitration takes milliseconds, so a sibling verb simply serializes behind it; a guard
# held past the wait refuses the command rather than falling back to the unguarded read-then-write,
# because a guard held that long means arbitration is LIVE, which is exactly when the race is.
if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  eval "$REAL_GUARD_FNS"
  # A guard nobody can take: held by a background process for longer than LOCK_GUARD_WAIT.
  #
  # Readiness comes from a sentinel file, not a sleep. A fixed sleep is a race on a loaded box (CI
  # running three suites in a container), and the failure mode is silent: the guard is not held yet,
  # lock_check takes it, and the test passes without exercising the path at all.
  # Every redirection here is load-bearing. The holder's `sleep` inherits this script's stdout, and a
  # child holding that open stops a caller that PIPES the suite's output (CI does, and so does
  # `composer run test:shell`) from seeing EOF until the sleep expires -- adding its full duration to
  # every run, silently. `kill $!` does not help: with an `&&` list bash does not exec-optimize, so $!
  # is the subshell and the `sleep` is its child, which survives. Closing its stdout is what fixes it.
  # The sleep also closes fd 9 (like live_pid does): it must not inherit the guard flock, or killing
  # the subshell below would leave the guard held by an orphan sleep for its full duration.
  ( flock 9 && : > "$ROOT/guard-held" && sleep 20 >/dev/null 2>&1 9>&- ) >/dev/null 2>&1 9>>"$LOCK_GUARD_FILE" &
  GUARD_HOLDER=$!
  LIVE_PIDS+=("$GUARD_HOLDER")
  GUARD_WAITED=0
  while [ ! -e "$ROOT/guard-held" ] && [ "$GUARD_WAITED" -lt 100 ]; do
    sleep 0.1
    GUARD_WAITED=$((GUARD_WAITED + 1))
  done
  assert_present "the background holder took the guard (test precondition)" "$ROOT/guard-held"
  plant_lock 60 "steppedjobtoken" "$DEAD_PID"
  lock_check "steppedjobtoken"
  assert_eq "a guard held past the wait refuses the reclaim rather than bypassing the guard" "1" "$?"
  assert_eq "and says why" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"
  assert_eq "the lock is untouched" "steppedjobtoken" "$(lock_token)"
  assert_eq "its recorded holder is untouched" "$DEAD_PID" "$(lock_pid)"
  assert_eq "the refused verb does not own the lock" "0" "$STAGING_LOCK_OWNED"
  # Once the holder lets go, the same verb reclaims -- under the guard this time.
  kill "$GUARD_HOLDER" 2>/dev/null
  wait "$GUARD_HOLDER" 2>/dev/null
  lock_check "steppedjobtoken"
  assert_eq "once the guard frees, the verb reclaims its own fresh lock" "0" "$?"
  assert_eq "the job keeps its token" "steppedjobtoken" "$(lock_token)"
  assert_eq "and owns the lock" "1" "$STAGING_LOCK_OWNED"
else
  SKIP=$((SKIP + 1)); printf '  skip - guard-contention test needs flock\n'
fi

# A STALE own lock is a different matter: past the TTL another operation may steal it, so a reclaim
# and a steal could be in flight at once. That one still goes through the guard.
new_fixture
eval "$REAL_GUARD_FNS"
lock_guard_available() { return 0; }
lock_guard_enter() { return 1; }
plant_lock "$((LOCK_TTL + 600))" "steppedjobtoken" "$DEAD_PID"
lock_check "steppedjobtoken"
assert_eq "a stale own lock is NOT reclaimed without the guard" "1" "$?"
assert_eq "and says the guard was unavailable" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"
eval "$REAL_GUARD_FNS"

# A fresh own lock whose recorded step is still RUNNING is refused, not reclaimed, guard or no guard.
new_fixture
live_pid
plant_lock 60 "steppedjobtoken" "$LIVE_PID"
lock_check "steppedjobtoken"
assert_eq "a fresh own lock held by a live step is refused" "1" "$?"
assert_eq "and says which refusal it was" "lock_check:held_by_own_live_step" "$LAST_ERROR_STEP"
assert_eq "the running step keeps the lock" "steppedjobtoken" "$(lock_token)"
assert_eq "and the caller does not own it" "0" "$STAGING_LOCK_OWNED"

echo "== the own reclaim stands off from the TTL boundary =="

# At the TTL exactly, another operation is already entitled to steal, and a steal is `rm -f` plus an
# exclusive create while this reclaim is a read plus a write BY PATH. The guard now serializes those
# too, but the margin stays: it keeps the no-flock fallback honest (where nothing serializes) and it
# routes the near-boundary case into the contended section, which re-examines under the guard.
new_fixture
eval "$REAL_GUARD_FNS"
lock_guard_available() { return 0; }
lock_guard_enter() { return 1; }
# Inside the TTL but within the margin of it: never short-circuited, so with no guard to be had it
# is refused as arbitration.
plant_lock "$((LOCK_TTL - 5))" "steppedjobtoken" "$DEAD_PID"
lock_check "steppedjobtoken"
assert_eq "a lock within the margin is not reclaimed without the guard" "1" "$?"
assert_eq "it is treated as arbitration instead" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"

# Being comfortably inside the TTL changes nothing when flock exists but the guard cannot be taken:
# the read-then-write runs under the guard or not at all (the race test above is why).
new_fixture
lock_guard_available() { return 0; }
lock_guard_enter() { return 1; }
plant_lock "$((LOCK_TTL - LOCK_RECLAIM_MARGIN - 60))" "steppedjobtoken" "$DEAD_PID"
lock_check "steppedjobtoken"
assert_eq "a lock well inside the TTL is still not reclaimed when the guard cannot be taken" "1" "$?"
assert_eq "and says why" "lock_check:guard_unavailable" "$LAST_ERROR_STEP"
eval "$REAL_GUARD_FNS"

echo "== a loser never touches the winner's lock file =="

# The winner's lock file is EMPTY for the moment between the exclusive create and the write landing,
# and a quota-failed attempt leaves an empty file too. A version of lock_write_exclusive that removed
# "any empty lock file" could not tell those apart, so a LOSER deleted the WINNER's file in that
# window and both operations ended up under the mutex.
#
# The fix is that only the process that created the file may remove it. A loser's call must leave an
# empty file exactly as it found it.
new_fixture
: > "$LOCK_FILE"                       # the winner's file, created but not yet written
STAGING_LOCK_TOKEN="losertoken"
lock_write_exclusive
assert_eq "the loser's acquire fails" "1" "$?"
assert_present "and the winner's file is still there" "$LOCK_FILE"

echo "== a root-owned lock guard does not brick the account =="

# `flock` needs only a DESCRIPTOR, not a writable one, and the realistic way the guard becomes
# unopenable is a root run creating it under root's umask (0644: readable, not writable). Refusing
# outright there was not the small problem it looked like: the steal path needs the guard, so after
# any killed operation left a lock behind, every staging command was refused until a human deleted a
# file on the server.
if [ "$HAVE_FLOCK" = "1" ] && [ "$(id -u)" != "0" ]; then
  new_fixture
  eval "$REAL_GUARD_FNS"
  rm -f "$LOCK_GUARD_FILE"
  : > "$LOCK_GUARD_FILE"
  chmod 0444 "$LOCK_GUARD_FILE"        # readable, not writable: what a root run leaves behind
  lock_guard_enter
  assert_eq "the guard is taken read-only rather than refused" "0" "$?"
  lock_guard_leave

  # And an abandoned lock can therefore still be cleared, which is the thing that was bricked.
  plant_lock "$((LOCK_TTL + 600))" "someoneelsestoken" "$DEAD_PID"
  lock_check "ourtoken"
  assert_eq "an abandoned lock is still recoverable" "0" "$?"
  assert_eq "and the lock is ours now" "ourtoken" "$(lock_token)"
  chmod 0644 "$LOCK_GUARD_FILE" 2>/dev/null
else
  SKIP=$((SKIP + 1)); printf '  skip - read-only-guard test needs flock and a non-root user\n'
fi

echo "== a release that cannot take the guard still frees its own fresh lock =="

# Leaving it to age out blocks the site for half an hour after EVERY operation on a host whose guard
# cannot be taken. A lock still carrying our token and comfortably inside the TTL is one nobody is
# entitled to steal yet, so nobody can be arbitrating it, so removing it needs no guard -- the same
# argument lock_reclaim_own_fresh already relies on.
new_fixture
lock_check "ourtoken"
eval "$REAL_GUARD_FNS"
lock_guard_available() { return 0; }
lock_guard_enter() { return 1; }
release_staging_lock
assert_absent "our own fresh lock is released without the guard" "$LOCK_FILE"
assert_eq "and ownership is dropped" "0" "$STAGING_LOCK_OWNED"
assert_eq "and the compat channel is withdrawn from" "1" "$LEGACY_WITHDRAWN"

# A lock that is no longer ours is still left strictly alone.
new_fixture
lock_check "ourtoken"
plant_lock 5 "someoneelsestoken" "$DEAD_PID"
lock_guard_available() { return 0; }
lock_guard_enter() { return 1; }
release_staging_lock
assert_eq "a successor's lock is not touched" "someoneelsestoken" "$(lock_token)"
eval "$REAL_GUARD_FNS"

echo "== a lock file that could not be written is not left behind =="

# The O_EXCL open can succeed while the WRITE fails, on an account out of disk or inode quota. That
# leaves an empty file belonging to nobody: it carries no token, so no release can claim it, and it
# is aged from its mtime, so it blocks every staging operation for a full LOCK_TTL after the quota is
# freed.
new_fixture
STAGING_LOCK_TOKEN="sometoken"
printf() { return 1; }
lock_write_exclusive
WROTE_RC=$?
unset -f printf
assert_eq "the acquire reports failure" "1" "$WROTE_RC"
assert_absent "and no empty lock file is left to wedge the site" "$LOCK_FILE"

echo "== the compat channel is never written while the guard is held =="

# legacy_lock_announce and legacy_lock_withdraw run a WP-CLI bootstrap, which takes seconds -- longer
# on exactly the broken-object-cache hosts the wp() wrapper exists for. The guard is meant to cover
# the few milliseconds of lock arbitration, so holding it across a bootstrap makes every other
# staging command wait out LOCK_GUARD_WAIT and then be refused as locked, for nothing.
if [ "$HAVE_FLOCK" = "1" ]; then
  new_fixture
  eval "$REAL_GUARD_FNS"
  ANNOUNCE_SAW_GUARD=""
  legacy_lock_announce() {
    LEGACY_ANNOUNCED=$((LEGACY_ANNOUNCED + 1))
    # If the guard were still held by this shell, a fresh flock attempt on it from a CHILD would
    # block and time out. fd 9 is closed in the child so it does not inherit the lock itself.
    if flock -w 1 "$LOCK_GUARD_FILE" -c true 9>&- 2>/dev/null; then
      ANNOUNCE_SAW_GUARD="free"
    else
      ANNOUNCE_SAW_GUARD="held"
    fi
  }
  # Force the guarded steal path: an abandoned lock belonging to somebody else.
  plant_lock "$((LOCK_TTL + 600))" "someoneelsestoken" "$DEAD_PID"
  lock_check "ourtoken"
  assert_eq "the steal succeeded" "0" "$?"
  assert_eq "and announced once" "1" "$LEGACY_ANNOUNCED"
  assert_eq "with the guard already released" "free" "$ANNOUNCE_SAW_GUARD"
  legacy_lock_announce() { LEGACY_ANNOUNCED=$((LEGACY_ANNOUNCED + 1)); }
else
  SKIP=$((SKIP + 1)); printf '  skip - guard-release-before-announce test needs flock\n'
fi

echo "== lock_check survives the set -e the real script runs with =="

# THE BLIND SPOT THIS SUITE HAD. Line 38 turns `set -e` off so tests can assert on return values, and
# that hid a real break: lock_reclaim_own_fresh returns non-zero for the ordinary cases of "not our
# lock" and "ours but stale", and a bare call followed by `$?` EXITS under set -e. Every contended
# lock_check died before it could arbitrate -- no JSON for the PHP side to read, the steal path
# unreachable, and a lock left behind by any killed operation blocking every staging command until a
# human deleted the file.
#
# Called as a PLAIN STATEMENT, never `lock_check ... || true`: errexit is suppressed for the whole
# dynamic extent of a command in an `&&`/`||` list, so the guarded form would mask exactly this. That
# means the case has to be one where correct code returns 0, hence a stale lock of our OWN token:
# the reclaim declines it (past the margin), the guarded path then adopts it, and lock_check succeeds.
new_fixture
plant_lock "$((LOCK_TTL + 600))" "steppedjobtoken" "$DEAD_PID"
( set -e; lock_check "steppedjobtoken"; : > "$ROOT/reached" ) >/dev/null 2>&1
assert_present "a stale own lock is adopted without ending the run" "$ROOT/reached"
assert_eq "and the lock carries the job's token" "steppedjobtoken" "$(lock_token)"

# A free lock, the uncontended fast path, under the same conditions.
new_fixture
( set -e; lock_check "freshtoken"; : > "$ROOT/reached" ) >/dev/null 2>&1
assert_present "a free lock is taken without ending the run" "$ROOT/reached"

# And release_staging_lock, which the EXIT trap runs under set -e: its status becomes the script's,
# so a non-zero return there reports a successful deploy to the customer as a failure.
new_fixture
lock_check "releasetoken"
( set -e; release_staging_lock; : > "$ROOT/released" ) >/dev/null 2>&1
assert_present "release runs to completion under set -e" "$ROOT/released"

echo "== a holder we are not allowed to signal counts as alive =="

# Under CageFS /proc is namespaced, so a process owned by another user (root included) is invisible to
# `ps` and `kill -0` on it returns EPERM. Reading that as "dead" would let a lock be taken from an
# operation that is still writing files, so the EPERM message is matched explicitly.
new_fixture
kill() { printf 'bash: line 1: kill: (%s) - Operation not permitted\n' "${2:-}" >&2; return 1; }
ps() { return 1; }
lock_holder_alive 4242
assert_eq "EPERM from kill -0 reads as alive" "0" "$?"
kill() { return 1; }
lock_holder_alive 4242
assert_eq "a plain failure with no EPERM and no ps match reads as dead" "1" "$?"
unset -f kill ps

cleanup_live_pids

printf '\n%s passed, %s failed, %s skipped\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ]
