---
name: wp-module-staging
title: Database copy
description: How create/clone/deploy copy the database, and the environment variables that tune it.
updated: 2026-09-09
---

# Database copy

Staging tables live in the **same database** as production; they differ only by table prefix
(`staging_` prepended to the production prefix). `create`, `clone`, and `deploy_db` therefore copy
the database **server-side**, without moving the row data out through the client:

1. **Schema** is dumped structure-only (`mysqldump --no-data`), its table prefix rewritten, and
   imported. This preserves foreign keys, views, and the prefix/constraint handling.
2. **Row data** is copied with `INSERT ... SELECT`, one table at a time, in primary-key ranges
   (chunked) so no single statement is unbounded. Generated columns are excluded from the column
   list (MySQL recomputes them); a relaxed `sql_mode` lets legacy values such as `0000-00-00`
   datetimes copy verbatim.
3. **Triggers** are dumped and applied last — after the data — so they do not fire during the copy.

`deploy_db` (staging → production) additionally takes a compressed backup of production first and
rolls back to it if the deploy fails.

## Tuning (environment variables)

All optional; the defaults suit typical sites.

| Variable | Default | Effect |
|----------|---------|--------|
| `NFD_STAGING_COPY_CHUNK` | `50000` | Rows per `INSERT ... SELECT` batch for tables with a single numeric primary key. |
| `NFD_STAGING_COPY_PACE` | `0` | Seconds to sleep between chunks. Raise to spread a very large copy over more time. |
| `NFD_STAGING_SR_RATE_MB` | `25` | Paces the URL search-replace by table size (MB per second of read budget); `0` disables pacing. |
