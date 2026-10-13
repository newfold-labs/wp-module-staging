---
name: wp-module-staging
title: Content directory sync
description: How create/clone/deploy move wp-content (uploads, themes, plugins), and why the deploy swap is delta-based and atomic.
updated: 2026-10-12
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

Deploy must replace production's content with staging's. It runs in two phases so production is never
left in a half-updated state.

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

### Phase 2 — `finalize_new_content_dirs` (atomic swap)

For each dir, promotion is two renames, never a delete of live production first:

1. `mv -T <dir> <dir>-old` — move current production aside (instant).
2. `mv -T <dir>-new <dir>` — promote the prepared tree (instant).

The `-old` asides are deleted only **after all three dirs are promoted**, so a failure partway through
leaves every already-promoted dir recoverable. If any promotion fails, each already-promoted dir is
rolled back from its aside, so a deploy is **all-or-nothing**, not left half-swapped. The final aside
deletion is the only slow step; it runs after production is live and is **non-fatal** — with
`--link-dest` the unchanged files are hardlinks, so removing `-old` mostly just drops extra links, and
an interruption there only leaves a reclaimable `<dir>-old` behind. `cleanup_failed_new_content_dirs`
sweeps stray `-new` and `-old` dirs on the next run.

Every rename that targets a live path uses `mv -T` (`--no-target-directory`). Plain `mv` of a
directory onto an **existing** directory nests the source inside it and still returns success; if a
live request recreated `<dir>` in the window after the aside, a plain `mv <dir>-new <dir>` would hide
the staged tree under `<dir>/<dir>-new` and the "successful" swap would then delete the wrong copy.
`-T` turns that into a failure that triggers rollback instead. The deploy worker only ever runs on
Linux (cPanel), whose coreutils `mv` supports `-T`. If production cannot be restored in place during a
rollback (because it was recreated), the sole surviving copy is kept under a `<dir>-recovered-<ts>`
name that no cleanup path sweeps, rather than risk auto-deletion.

This replaced an earlier sequence that did `rm -rf <production dir>` and only then renamed the new
copy in. On large or busy sites that had two problems:

- **Data loss.** If the `rm` failed partway (for example a plugin writing into `uploads` while the
  `rm` ran, so a follow-up `rmdir` hit `Directory not empty`), production content was already gone
  with no way back.
- **Duration.** The multi-GB `rm` ran before the swap, doing O(total size) work inside the deploy's
  critical window.

## Caveats

- **Quick-check, not checksum.** `--link-dest` uses rsync's default size + mtime comparison. A staging
  file whose size and mtime both still match production but whose content differs (only possible via an
  mtime-preserving rewrite, e.g. a restore tool) would be hardlinked to production's bytes and ship
  stale. `--checksum` would close this but must read every byte of the tree, which defeats the delta
  and risks the reaper, so the quick check is accepted as in any rsync-based deploy.
- **Empty staging replaces production.** A deploy copies staging content over production, so if the
  staging `<dir>` is empty (user cleared it, or a partial clone) the corresponding production dir is
  emptied too. This is deploy semantics, not a bug, but it is unrecoverable once the aside is deleted.

## Why duration matters: the shared-host process reaper

On cPanel userboxes the deploy worker is launched detached (`nohup … &`) so it survives the PHP-FPM
request timeout, which reparents it to PID 1. An hourly `lsphp_reaper` cron SIGKILLs any PID-1 user
process that is not `lsphp`, with no grace period, so a deploy still running at the top of the hour is
killed. The delta copy and the instant renames keep a normal deploy short enough to finish between
reaper runs; the old full-copy-then-delete could not on a large tree, and was observed being killed
mid-deploy. Keeping per-deploy work proportional to the change set, not the total size, is what keeps
it under that ceiling. An initial create/clone of a very large tree is still a full copy and remains
exposed to this; that is tracked separately.
