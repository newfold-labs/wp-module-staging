---
name: wp-module-staging
title: Bootstrap check
description: WP-CLI probe that reports a broken plugin or theme before staging starts.
updated: 2026-10-08
---

# Bootstrap check

Switch runs `wp newfold sso`, which boots WordPress with active plugins and the active theme. Create copies those plugins into the new staging site, so it uses the same full probe on production. Clone and file deploy do not boot regular plugins: `wp core version` and `wp core download` run before WordPress loads, and the other commands pass `--skip-plugins` and `--skip-themes`. Must-use plugins, drop-ins, and `wp-config.php` still load. A fatal there — commonly `create_function()` removed in PHP 8, a `Cannot redeclare` conflict, or other deprecated syntax — kills the command. The shell script then exits before it can print JSON, and the UI only has a generic failure.

`StagingBootstrapCheck` runs first, against the directory that command will boot:

| Operation | Directory | Plugins and themes |
|-----------|-----------|--------------------|
| Create | Production | Loaded. The copy would otherwise fail on the first switch. |
| Clone | Production, then the existing staging site | Production: loaded, same reason as create. Staging: skipped. Its plugins are replaced before they would boot, and a full probe would block the clone that repairs them. |
| Deploy files, deploy files and database | Staging | Skipped. The deploy never loads them. |
| Switch to staging / production | The destination | Loaded. |

Database-only deploy is not probed. Its WP-CLI calls skip plugins and themes, so a broken plugin does not fail it.

The probe is one command, capped at 10 seconds. `PATH` includes `/usr/local/bin`, which is where `wp` lives on the hosts this module targets. `runCommand()` adds that directory only later, and `lib/.staging` exports it inside the script, so the probe sets it itself:

```bash
PATH="$PATH:/usr/local/bin" timeout 10 wp --exec='…' eval 'echo "ok";' --path="$dir" --quiet
```

`--exec` is the same object-cache drop-in skip used by the `wp()` wrapper in `lib/.staging`, and it defines `WP_DISABLE_FATAL_ERROR_HANDLER`. Switch and create omit `--skip-plugins` and `--skip-themes`. Clone of the existing staging site, and file deploy, pass both flags. Must-use plugins still load.

WordPress's own fatal handler would otherwise mail a recovery link and write options while that drop-in is skipped. Those writes go to the database and leave the object cache stale, so the next switch (`wp newfold sso` loads the drop-in) or clone dies with a generic error even after the broken file is restored. After every probe that actually booted WordPress, a second `wp eval` drops `alloptions` and the staging option keys for that install. That command skips plugins and loads the drop-in, so the delete hits the same cache the next request will read.

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
