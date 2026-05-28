---
name: wp-module-staging
title: Integration
description: How the module registers and integrates.
updated: 2025-03-18
---

# Integration

## How the module registers

The module registers with the Newfold Module Loader via bootstrap.php. The host plugin typically registers a staging service in the container and exposes admin UI and/or REST API that use the module’s APIs for create, clone, and restore operations.

## Dependencies

This module has no runtime Composer requires; the host supplies WordPress and any external staging provider. For development, the repo uses wp-module-loader, WordPress, wp-browser, and php-standards. See composer.json.

## Staging metadata health check

On relevant admin loads and REST staging detail requests, the module runs an automatic health check that:

- Reconstructs `staging_config` and `staging_environment` from `ABSPATH` when the site lives under `/staging/{4-digit-id}/`.
- Removes orphaned `staging_config` when the staging directory no longer exists on disk.
- Rebuilds config from an on-disk staging directory when metadata is missing.
- Corrects swapped production/staging URLs.

See [health-check.md](health-check.md) for full behavior, logging, and admin notices.
