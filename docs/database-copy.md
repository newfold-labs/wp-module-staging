---
name: wp-module-staging
title: Database copy
description: How create/clone/deploy copy the database, and the environment variables that tune it.
updated: 2026-09-11
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

| Variable | Default | Accepted | Effect |
|----------|---------|----------|--------|
| `NFD_STAGING_COPY_CHUNK` | `50000` | integer >= 1 | Rows per `INSERT ... SELECT` batch for tables with a single numeric primary key. |
| `NFD_STAGING_COPY_PACE` | `0` | number >= 0 | Seconds to sleep between chunks. Raise to spread a very large copy over more time. |
| `NFD_STAGING_SR_RATE_MB` | `25` | number >= 0 | Paces the URL search-replace by table size (MB per second of read budget); `0` disables pacing. |

`NFD_STAGING_COPY_CHUNK` and `NFD_STAGING_COPY_PACE` are validated before use. A value outside the
accepted range (including `0` for the chunk size, which is not a way to disable chunking) is
ignored, the default is used instead, and the reason is written to the staging log.

## Consistency

The copy is **not a point-in-time snapshot**. The schema is dumped with `--skip-lock-tables` and the
rows are then copied table by table, chunk by chunk, while the source site keeps serving traffic.
Rows written to a table that has already been copied are missed, and rows written to a table not yet
reached are included, so a row can arrive in the copy without the related row another table would
have held. Foreign keys are disabled for the copy, so such a row is inserted rather than rejected.

This is deliberate. The previous implementation held a read lock across the whole dump, which is
what made a large site fail rather than merely lag; locking a multi-gigabyte production database for
the duration of a clone is not an option. For `create` and `clone` the target is a fresh staging
site, where a small amount of skew is acceptable. For `deploy_db` the source is the staging site,
which is normally idle.

If an exact copy matters for a particular run, quiesce writes to the source first (for example, put
the site in maintenance mode) rather than relying on the copy to serialise them.
