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
| `NFD_STAGING_COPY_MAX_MB` | `64` | integer >= 1 | Target size of one copy statement. A per-table row count is derived from `AVG_ROW_LENGTH` and the smaller of it and the chunk above is used, so a table of very wide rows is not copied in one huge transaction. Only ever lowers the chunk, and is floored at 1000 rows. |
| `NFD_STAGING_COPY_PACE` | `0` | number >= 0 | Seconds to sleep between chunks. Raise to spread a very large copy over more time. |
| `NFD_STAGING_SR_RATE_MB` | `25` | number >= 0 | Paces the URL search-replace by table size (MB per second of read budget); `0` disables pacing. |

All four are validated before use. A value outside the
accepted range (including `0` for the chunk size, which is not a way to disable chunking) is
ignored, the default is used instead, and the reason is written to the staging log.

## Trigger isolation

Staging and production tables live in one database, so a trigger copied from one environment must
be rewritten to point at the other. If it is not, a trigger on staging writes into production (and
after `deploy_db`, a trigger on production writes into staging).

The rewrite handles the trigger name, its target table, and table references in the body, quoted or
bare, with or without a database qualifier. It cannot handle every case: a modifier or comment
between the keyword and the table, or a `CALL` into a stored procedure (procedures are not copied,
so nothing about what they write can be redirected).

The rewritten dump is therefore checked before it is imported, and the copy **fails** if anything
still refers to the source environment, naming the offending tables in the log. Table names inside
string literals and comments are ignored, so ordinary trigger text does not trip it.

If a site hits this, the trigger has to be looked at by hand: the operation is refused rather than
allowed to install something that writes across the boundary. Sites with no triggers, which is
most of them, are unaffected.

## Where deploy_db writes its backup

`deploy_db` dumps production before it changes anything. That archive is the whole database, so it
is written under `nfd-private/` (which carries a deny-all `.htaccess`) with a random name and `0600`
permissions, never into the document root. It is removed once the deploy succeeds, and the deploy
aborts before touching production if the archive is missing, truncated, or does not decompress.

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
