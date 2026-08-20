---
name: wp-module-staging
title: Staging health check
description: Automatic repair of staging metadata and filesystem inconsistencies.
updated: 2026-05-28
---

# Staging health check

The module can detect and repair inconsistent staging state without user action. Staging sites always live at deterministic paths:

- **URL:** `{production_url}/staging/{4-digit-id}`
- **Directory:** `{production_dir}/staging/{4-digit-id}/`

When `wp_options` metadata (`staging_config`, `staging_environment`) is missing, corrupt, or out of sync with the filesystem, the admin UI and deploy flows can break. The health check reconstructs or cleans up metadata from the path and disk.

## When it runs

Automatically when:

1. An administrator loads the host plugin staging settings page (`admin.php?page={plugin_id}#/settings/staging`).
2. An administrator loads **Tools → Staging** (legacy redirect).
3. Any wp-admin request on a site whose `ABSPATH` matches `/staging/{4-digit-id}/`.
4. A REST `GET /newfold-staging/v1/staging` request (before returning staging details).

Requires `manage_options`.

## Staging site behavior

If `ABSPATH` matches `/staging/\d{4}/`:

- The site is treated as staging (even if `staging_environment` is wrong).
- `staging_environment` is set to `staging` when incorrect.
- `staging_config` is rebuilt from `ABSPATH` and `site_url()` when missing or invalid.
- Swapped `production_url` / `staging_url` values are corrected.
- `WP_ENVIRONMENT_TYPE` set to `staging` in `wp-config.php` is used as a secondary signal.

## Production site behavior

If `ABSPATH` is not a staging path:

- **Orphaned metadata:** If `staging_config` references a `staging_dir` that does not exist on disk, `staging_config` is deleted so the user can create a new staging site.
- **Disk without config:** If no `staging_config` exists but a valid `staging/{4-digit}/` directory with `wp-config.php` exists, config is reconstructed from that directory.
- Swapped URLs are corrected when detected.

## Logging

Repairs are appended to:

`{production_dir}/nfd-private/nfd-staging-repair.log`

Each line includes timestamp, level, step, and a description of what was detected or fixed. The `nfd-private` directory uses the same `.htaccess` deny rules as the main staging log.

## Admin notice

After any repair, a one-time warning notice is shown:

> Staging configuration was automatically repaired.

## Related code

| Class | Role |
|-------|------|
| `StagingPath` | Path/URL parsing, validation, discovery |
| `StagingHealthCheck` | Repair logic and logging |
| `Staging` | Hooks, `isStaging()`, safe getters |
| `StagingApi` | Repair before `getStagingDetails` |
