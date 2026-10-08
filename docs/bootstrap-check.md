---
name: wp-module-staging
title: Bootstrap check
description: WP-CLI probe that reports a broken plugin or theme before staging starts.
updated: 2026-10-08
---

# Bootstrap check

Staging create, clone, file deploy, and environment switch each run at least one WP-CLI command that boots WordPress with active plugins and the active theme. Most other commands in `lib/.staging` pass `--skip-plugins` and `--skip-themes`. A single fatal — commonly `create_function()` removed in PHP 8, a `Cannot redeclare` conflict, or other deprecated syntax — kills that bootstrap. The shell script then exits before it can print JSON, and the UI only has a generic failure.

`StagingBootstrapCheck` runs first, against the directory that command will boot:

| Operation | Directory |
|-----------|-----------|
| Create, clone | Production |
| Deploy files, deploy files and database | Staging |
| Switch to staging / production | The destination |

Database-only deploy is not probed. Its WP-CLI calls skip plugins and themes, so a broken plugin does not fail it.

The probe is one command, capped at 10 seconds. `PATH` includes `/usr/local/bin`, which is where `wp` lives on the hosts this module targets. `runCommand()` adds that directory only later, and `lib/.staging` exports it inside the script, so the probe sets it itself:

```bash
PATH="$PATH:/usr/local/bin" timeout 10 wp --exec='…' eval 'echo "ok";' --path="$dir" --quiet
```

`--exec` is the same object-cache drop-in skip used by the `wp()` wrapper in `lib/.staging`. Plugins and themes are loaded. There is no `--skip-plugins` or `--skip-themes`.

Exit 0 is a pass, even when a plugin prints a UTF-8 BOM or extra text around `ok`. A missing `wp` or `timeout`, a disabled `exec()`, a directory that is not there, or the 10 second cap all fail open. A slow bootstrap is not a fatal, and the staging script already reports a missing tool. Any other non-zero exit is blocked only when the output contains a PHP fatal (`NFD_BOOTSTRAP_FATAL:`, `Fatal error`, `Parse error`, or WordPress's critical-error text). A database connection error, or a site that is not installed, is logged and left to the staging script.

## What the user sees

The fatal line is parsed for `/plugins/{slug}/`, `/themes/{slug}/`, or `/mu-plugins/{slug}/`. A single file such as `slug.php` counts. A `create_function()` deprecation on another line is ignored, so a PHP 7 notice does not hide a different fatal. Stack frames are ignored, because they usually start in WordPress core. The message does not include the filesystem path. The raw output, truncated, is appended to `{production_dir}/nfd-private/nfd-staging.log` with the exit status.

- **`create_function()`** — the named plugin, must-use plugin, or theme is incompatible with PHP 8. Deactivate it, or remove it from `mu-plugins`, and try again.
- **`Cannot redeclare`** — the named plugin (or both, when the fatal names two) has a code conflict. Deactivate or remove one and try again.
- **Any other fatal** — deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again. The slug is included when the fatal line has one.
- **Must-use plugin** — the message says to remove it from `mu-plugins`. Deactivating plugins does not fix it.

A bootstrap stops at the first fatal. Another broken plugin is reported on the next attempt.

The check runs in PHP before `runCommand()`, so a failure does not write `staging_config` or the auth token. The REST response is a `WP_Error` (`bootstrap_check`); the staging screen shows that message.

## Related code

| Class | Role |
|-------|------|
| `StagingBootstrapCheck` | Probe, fatal parsing, log line |
| `Staging` | Calls the probe from create, clone, file deploy, and switch |
| `StagingHealthCheck` | Separate: repairs staging metadata. See [health-check.md](health-check.md). |
