---
name: wp-module-staging
title: Content directory sync
description: How create/clone/deploy move wp-content (uploads, themes, plugins): the chunked resumable copy, the atomic delta swap, the aside lifecycle, and the staging mutex.
updated: 2026-10-13
---

# Content directory sync

Alongside the database, staging keeps its own copy of the large `wp-content` subtrees: `uploads`,
`themes`, and `plugins`. Two code paths in `lib/.staging` move them.

## create / clone (production → new staging)

`move_content_dirs` builds a fresh staging copy: for each dir it clears the destination, recreates
it, and `rsync -rt`s the production tree in. `-t` preserves modification times, which matters later:
it lets a subsequent deploy recognise unchanged files (see below). This path is an unavoidable full
copy because a new staging site has nothing to copy from yet.

## deploy (staging → production)

Deploy must replace production's content with staging's. It builds the replacement alongside the live
tree and swaps it in, so production is never left in a half-updated state.

### Phase 1 — `prepare_new_content_dirs` (build a delta snapshot)

For each dir it builds `wp-content/<dir>-new` with:

```
rsync -rt --exclude=.git --link-dest="<production>/wp-content/<dir>" <staging>/wp-content/<dir>/ <production>/wp-content/<dir>-new
```

`--link-dest` points at the current production dir. Files identical to production (same size and
mtime) are **hardlinked** into `-new` rather than copied, so the staging area costs only the changed
bytes, not a second full copy of the tree. Only new or changed files are actually written. (This is
the filesystem analogue of the server-side database copy: do not move data you do not have to.)

The quick check is size + mtime, so the benefit depends on the staging clone having preserved
production's mtimes. Clones made by the `-rt` path above do. A staging site cloned before `-t` was
added will not match on mtime, so its first deploy falls back to a full copy; the correctness and
safety guarantees below still hold.

A files deploy does not run that `rsync` in one shot. The single long process it needs is exactly what
the shared-host limits kill (see the last section), so the delta is built in short bounded bursts
instead, by `includes/Copy`. The UI's existing poll loop drives it: each poll advances the copy by
about ten seconds and returns, so no one request has to last longer than that. The copy keeps its
position in a checkpoint file and resumes from it, and the verified-and-resumable copy is what the
stepped deploy below is built on. A clone or create still uses the single `rsync`.

### The stepped files deploy

A files deploy is therefore not one bash run but a small state machine, advanced by the poll loop:

1. **setup** (bash) sweeps anything a previous attempt left behind.
2. **copy** (PHP, many bursts) builds the `-new` delta trees.
3. **core** (bash) downloads WordPress core with `--skip-content`. Without that flag `wp core
   download` copies the bundled themes and `hello.php` over production's inodes, and because the
   unchanged files in `-new` are **hardlinks to production**, writing through one of them writes
   through the other. The flag is load-bearing, not tidiness.
4. **finalize** (bash) does the swap below.

Those three bash verbs are three separate processes, minutes or hours apart. The staging mutex is what
makes them one hold, so nothing else can touch the trees in between: see [the staging
mutex](#the-staging-mutex).

### Phase 2 — `finalize_new_content_dirs` (atomic swap)

For each dir, promotion is two renames, never a delete of live production first:

1. `mv -T <dir> <dir>-old` — move current production aside (instant).
2. `mv -T <dir>-new <dir>` — promote the prepared tree (instant).

The `-old` asides are deleted only **after all three dirs are promoted**, so a failure partway through
leaves every already-promoted dir recoverable. If any promotion fails, each already-promoted dir is
rolled back from its aside, so a deploy is **all-or-nothing**, not left half-swapped.

Every rename that targets a live path uses `mv -T` (`--no-target-directory`). Plain `mv` of a
directory onto an **existing** directory nests the source inside it and still returns success; if a
live request recreated `<dir>` in the window after the aside, a plain `mv <dir>-new <dir>` would hide
the staged tree under `<dir>/<dir>-new` and the "successful" swap would then delete the wrong copy.
`-T` turns that into a failure that triggers rollback instead. The deploy worker only ever runs on
Linux (cPanel), whose coreutils `mv` supports `-T`. If production cannot be restored in place during a
rollback (because it was recreated), the sole surviving copy is kept under a `<dir>-recovered-…` name
that no cleanup path sweeps, rather than risk auto-deletion.

A promote that fails *because* production was recreated is not treated as a lost deploy. The recreated
directory holds only what was written in that microsecond race, which this deploy is replacing anyway,
so it is moved to a `<dir>-squatter-…` name and the promote is retried once. That is guarded hard: it
runs **only** when this run set production aside itself and `-old` still holds it. Otherwise the thing
at production's name could be the only copy — an NFS `is_dir()` misread leaving the aside flag unset,
or a concurrent restore having just put production back — and moving it away would risk losing it.

Aside names carry a sequence number as well as a timestamp and a pid (`-<ts>-<pid>-<seq>`). One
rollback can mint two names for the same dir inside the same second, and with only the first two
fields the second name equals the first; `mv -T` then fails onto the now non-empty directory and the
caller is left holding the tree it was trying to move to safety.

This replaced an earlier sequence that did `rm -rf <production dir>` and only then renamed the new
copy in. On large or busy sites that had two problems:

- **Data loss.** If the `rm` failed partway (for example a plugin writing into `uploads` while the
  `rm` ran, so a follow-up `rmdir` hit `Directory not empty`), production content was already gone
  with no way back.
- **Duration.** The multi-GB `rm` ran before the swap, doing O(total size) work inside the deploy's
  critical window.

### Deleting the asides, and why it happens after the mutex is released

On a normal deploy, deleting the asides is the **only step whose cost scales with the site's file
count**. The copy is chunked into bounded bursts, the core download is a fixed ~50MB, and the promote
itself is three renames. So it is the step that can outlive the PHP request, and `mv` is not an
option: the trees have to go.

(One other step shares the property. The sweep in `deploy_content_setup` removes any stale `-new`,
which is one unlink per file, holding the mutex, inside the request. It is empty on a normal deploy
and only has work after an attempt was abandoned, so it is a retry cost rather than a per-deploy one.
Releasing the mutex cannot help there, because setup needs those trees gone before the copy may
start, so chunking that sweep is an open follow-up.)

`deploy_content_finalize` therefore does three things in this order, and the order is the design:

1. `finalize_new_content_dirs` — the swap.
2. `release_staging_lock` — give up the mutex. Production is live and correct, and the asides are
   backups of content that is no longer in use anywhere, so deleting them needs no mutex.
3. `discard_content_asides` — the delete.

A request timed out during step 3 leaves an orphan finishing a job nothing is waiting on. The next
poll finds the lock free, finds no `-new` left to promote, and reports the success that has already
happened. Holding the mutex across the delete is what made a successful swap get reported as a
failure: the next poll collided with the orphan and had no way to tell "still running" from
"finished".

The alternative was to leave the asides for the **next** deploy's setup to sweep, so that finalize
stayed instant. That is a bad trade, and it is worth saying why, because it looks like the cheaper
option. An aside that outlives its own run is exposed to a live request recreating production's
directory — `wp_upload_dir()` remakes `uploads/YYYY/MM` on any media request, and `uploads` is the
first dir a swap touches. Once both names are occupied, **nothing on disk distinguishes the backup
from production**, and a near-empty recreated directory satisfies `[ -d <dir> ]` exactly as well as
the real tree does. Every mechanism that would be needed to resolve that ambiguity — a swap journal,
per-directory record states, a separate trash namespace, a completion marker — exists only because of
the deferral. Keeping the aside inside its own run removes the question instead of answering it.

### The sweep: `cleanup_failed_new_content_dirs`

Setup runs this before anything else, and so does the failure path of a finalize. A `-new` is always a
disposable, rebuildable staging copy and is removed. An `-old` is **never deleted here**, in either
branch:

- **production's name is free** → the aside IS production (a finalize was killed between its two
  renames, a window of microseconds) and it is restored with `mv -T <dir>-old <dir>`.
- **both names are occupied** → nothing on disk says which copy is production, so the aside is parked
  under `<dir>-recovered-<ts>-<pid>-<seq>`, a name no sweep touches, and left for a human or a support
  request to clear.

Leaking a tree in a rare case costs disk. Guessing wrong costs the customer's media library.

## The staging mutex

One site must never have two staging operations running against its trees at once: a clone rsyncing
staging while a deploy enumerates it, a destroy tearing down what a copy is reading, a legacy deploy
promoting over a half-built `-new`.

The mutex is a **file** at `<production>/nfd-private/nfd-staging-command.lock`, holding
`active:<refreshed_at>:<token>:<pid>`. A file rather than the WordPress transient it replaces, for two
reasons: the staging worker runs WP-CLI with the object-cache drop-in skipped, so a transient written
here is invisible to a PHP request reading it through a live persistent cache; and the lock belongs to
a filesystem operation, so it should not need a database to be readable.

- **Acquire** is `O_CREAT|O_EXCL` (`set -o noclobber`), so of any number of racers exactly one wins
  and no read-then-write window exists.
- **The token** is what makes setup, core and finalize one hold instead of three. The PHP controller
  mints it per job, stores it in the phase state, passes it to each verb, and refreshes the lock
  itself between copy bursts. A verb that finds the lock carrying its own token reclaims it; anyone
  else is refused.
- **Expiry tests liveness, not just age.** Past the TTL (1800s) a lock is only taken if its holder is
  really gone, because an operation that outlived the PHP request that started it is still writing
  files. A hard ceiling (21600s) covers a recycled pid. Under CageFS a process owned by another user
  is invisible to `ps` and `kill -0` returns EPERM, so EPERM is read as **alive**.
- **Clearing an abandoned lock is serialized by an `flock`** on a separate, permanent guard file.
  Deciding a lock is abandoned reads its contents; clearing it acts on its path, and unguarded those
  are two steps — a racer that examined the same abandoned lock a moment earlier would go on to clear
  the winner's *fresh* lock and create its own on top. On a host with no `flock` (macOS, so dev only)
  the lock is left alone rather than stolen: blocking costs availability, an unguarded steal costs
  correctness.
- **Reclaiming the job's own lock goes through the same guard.** The reclaim is a read, a liveness
  decision and a write, and two verbs of the *same* job can run it at once: FPM kills a request
  mid-verb (freeing the controller's advisory flock while the bash verb is still booting WP-CLI), the
  next poll sees a lock recording only the previous verb's dead pid and launches the verb again.
  Unguarded, both read "holder gone" and both reclaimed the mutex. Under the guard the first writer
  records its own live pid and the second is refused (`held_by_own_live_step`). When `flock` exists
  but the guard cannot be taken the command is refused rather than reclaimed unguarded; only a host
  with no `flock` at all still runs the reclaim unguarded, where steals cannot happen anyway.
- **Only the shell that took the lock frees it**, and only a lock still carrying its own token. A
  blocked caller, a subshell, and a holder whose lock was taken from it past the hard ceiling all
  leave it alone.
- **The unbounded rsyncs keep the lock alive and attributed while they run.** The liveness probes test
  the recorded pid, but the recorded pid was the bash script's, and the processes doing site-size
  work are its *children*: a reaped parent's rsync survives up to an hour, during which the lock read
  as abandoned while the rsync still filled a tree. A clone's full copy and the legacy deploy's delta
  now run under a detached watcher (`lock_watch_child`) that rewrites the lock every minute with a
  fresh timestamp and the *child's* pid, and stops by itself when the child exits or the lock stops
  being ours; the script re-attributes the lock to itself once the child is reaped. Other long
  children (core download, the sweeps, `deploy_db`'s dump/import) still record only the bash pid —
  their exposure is bounded by the lock's TTL and liveness rules and is no worse than the shipping
  code's.
- The old `nfd_staging_lock` transient is still published and honoured, purely so an operation running
  an **older copy** of `lib/.staging` on the staging install still blocks. It is a compatibility
  broadcast, never read for a decision — and it is weak: the older copy deletes the transient
  unconditionally on every exit of its own, and nothing republishes ours during the long copy phase.
- **A version handshake refuses a cross-version deploy outright.** Production and staging each run
  their own copy of the plugin, so their lock protocols can differ. Before every command that copies
  content between the installs (`clone`, the deploys, the stepped job's setup verb), the worker reads
  the brand plugin's installed version on both installs and refuses on a mismatch, telling the user to
  update the older side. Refuse-and-tell on purpose: updating a plugin from inside a staging command
  is a bigger side effect than the command itself. An unreadable version proceeds on the broadcast
  alone (a peer that cannot answer `wp plugin get` cannot run staging commands either).

`includes/StagingCommandLock.php` is the PHP half, reading and refreshing the same file. Both halves
are covered by tests: `tests/bash/test-staging-lock.sh` and `StagingContentCopyWPUnitTest`.

## Caveats

- **Quick-check, not checksum.** `--link-dest` uses rsync's default size + mtime comparison. A staging
  file whose size and mtime both still match production but whose content differs (only possible via an
  mtime-preserving rewrite, e.g. a restore tool) would be hardlinked to production's bytes and ship
  stale. `--checksum` would close this but must read every byte of the tree, which defeats the delta
  and risks the reaper, so the quick check is accepted as in any rsync-based deploy.
- **Empty staging replaces production.** A deploy copies staging content over production, so if the
  staging `<dir>` is empty (user cleared it, or a partial clone) the corresponding production dir is
  emptied too. This is deploy semantics, not a bug, but it is unrecoverable once the aside is deleted,
  which is now at the end of the same run rather than at the start of the next one.
- **Hardlinks need one filesystem.** `--link-dest` can only hardlink when the link-dest, the staging
  source, and the destination are on the same filesystem. They are here (all under the account's home),
  so this holds; if that ever stops being true, rsync silently falls back to copying and the deploy
  still works, just without the delta saving.
- **A parked tree is nobody's job to clean up.** `<dir>-recovered-…` and `<dir>-squatter-…` are
  deliberately outside every swept namespace, so they occupy disk until a human removes them. They are
  written only on paths that are already going wrong, so they should be rare, but there is no automatic
  reclaim and a site that hits one repeatedly will accumulate them.
- **An un-upgraded peer is still only covered best-effort.** The version handshake (see The mutex)
  closes cross-version concurrency in the steady state, but only once BOTH installs run a version that
  has it: a peer still running an older plugin copy performs no handshake when it initiates, knows
  nothing about the lock file, and deletes the compat transient unconditionally on every exit of its
  own — so a copy or a single directory's `rsync` that outlasts the transient's 1800s TTL, or any
  old-copy exit, stops being visible to that peer. Structurally unfixable from this side: shipped code
  cannot be made to cooperate retroactively. The window closes by itself once auto-updates bring both
  sides to a handshake-aware version.

## Why duration matters: the shared-host process reaper

On cPanel userboxes the deploy worker is launched detached (`nohup … &`) so it survives the PHP-FPM
request timeout, which reparents it to PID 1. An hourly `lsphp_reaper` cron SIGKILLs any PID-1 user
process that is not `lsphp`, with no grace period, so a deploy still running at the top of the hour is
killed. Two more limits bite the same way: `ulimit -t 120` caps CPU seconds per process, and a process
monitor kills anything over roughly 1.3GB RSS.

The delta copy and the instant renames keep a normal deploy short enough to finish between reaper runs;
the old full-copy-then-delete could not on a large tree, and was observed being killed mid-deploy.
Chunking the copy into bursts (above) removes the long-lived process altogether for a files deploy: no
single step is unbounded except the aside delete, and that one is deliberately made safe to lose by
releasing the mutex before it starts. Keeping per-deploy work proportional to the change set, not the
total size, is what keeps it under that ceiling.

An initial create/clone of a very large tree is still a single full copy and remains exposed to this,
as does the database copy; both are tracked separately.
