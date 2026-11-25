# Performance and data integrity

Measured with real data (M5-01). Numbers are **indicative**: PHP 8.3, MariaDB 11.1 (private test instance, InnoDB), WSL2 on a 20-thread i9-12900H laptop, no persistent object cache, other processes running. Budgets are asserted on **counts** (queries, bytes, memory with headroom), time is logged only (`tests/wp/tests/PerfBudgetTest.php`).

## Reproduce
```bash
composer test:perf                                   # 5k and 10k galleries (about 10 s); the 1k smoke variant runs in test:wp
SWG_PERF_LOG=/tmp/perf.jsonl composer test:perf      # one JSON line per measurement
wp eval-file bin/seed.php -- --items=5000 --galleries=2   # local site: seeds the same data, then open the editor / a page with [sw_gallery id="<id>"]
wp eval-file bin/seed.php -- --purge                      # removes what the seeder created
```
`bin/seed.php` refuses unless `WP_ENVIRONMENT_TYPE` is `local`, `development` or `staging` (or `--force`). Trade-off: attachments are batched INSERTs into posts/postmeta with the metadata WordPress writes for an upload (10k in about 1 s, `wp_insert_attachment` needs minutes); six tiny real files in `uploads/swg-seed/` are shared by all attachments (`--no-files` writes only database rows). Attachment hooks do not run. Items are inserted in batches of 1000 (`append()` costs two queries per item).

## Measured (queries are per operation incl. the REST/render stack; cold object cache)
| Operation | Items | Queries | Memory | Bytes | ms (median of 3) |
|---|---|---|---|---|---|
| REST list, page 1 (100 per page) | 1k / 5k / 10k | 10 / 10 / 10 | 1.4 / 1.3 / 0.9 MB | 52 KB | 17 / 29 / 33 |
| REST list, last page | 1k / 5k / 10k | 10 / 10 / 10 | 0.7 MB | 53 KB | 17 / 34 / 43 |
| Render page 1 (60 per page, grid) | 1k / 5k / 10k | 18 / 18 / 18 | 1.1 / 0.8 / 0.8 MB | 63 KB HTML | 42 / 28 / 29 |
| Render later page / last page | 10k | 18 / 18 | 0.8 / 0.5 MB | 63 / 43 KB | 35 / 49 |
| Render, warm fragment cache hit | any | 0 | 0.13 MB | 63 KB | about 1 |
| Duplicate gallery | 1k / 5k / 10k | 33 / 34 / 33 | 2 KB | - | 138 / 284 / 122 |
| Reorder, one move (complete list sent) | 1k / 5k / 10k | 11 / 16 / 21 | 0.9 / 4.1 / 8.0 MB | 5 / 25 / 60 KB request | 78 / 202 / 244 |
| Bulk assign 100 attachments | 1k / 5k / 10k | 212 / 213 / 212 | 0.1-0.4 MB | 0.6 KB | 66-228 |
| Remove one item | 1k / 5k / 10k | 10 / 11 / 11 | 0.1 / 0.3 / 0.6 MB | - | 6-14 |
| Gallery list, 100 galleries with covers | (200 galleries) | 10 | 1.1 MB | 38 KB | 31 |

Response sizes are bounded by `per_page`, never by the gallery size. 1k reorder: `ms` of a single run.

## Regressions found and fixed
| Finding | Before | After |
|---|---|---|
| Reorder wrote one `UPDATE` per item (M2-07 claimed a `CASE` update) | 1009 / 5009 / about 10009 queries, 358 / 1452 ms | 11 / 16 / 21 queries: one `SELECT` of the positions, one `CASE` `UPDATE` per 1000 rows that really change (a swap writes 2 rows). Same transaction, same revision claim |
| Gallery list resolved every cover with two queries (post + meta) | 208 queries for 100 covers | 10 (covers primed once per page in `WpGalleryRepository::search`) |
| Bulk assign read every attachment separately for the access check | 311 queries for 100 | 212 (one primed query). The remaining two statements per item are `append()`; a batched `appendMany()` needs a core contract change and is a follow-up |
| (not slow, measured) thumbnails in render and REST | constant (primed posts and meta) | unchanged |

Indexes: `gallery_position (gallery_id, position, id)`, `gallery_attachment (gallery_id, attachment_id)` unique and `attachment_id` serve every query measured here (`ORDER BY position` is index-ordered, `count` and the join use the keys); no change proposed, so no migration. `IN (...)` lists are bounded by the callers (500 assign/remove, 1000 counts, 5000 repair) and `MAX_PER_PAGE`.

## Supported maximum per gallery
**10 000 items per gallery** are measured and supported (editor list, render, reorder, duplicate, remove). Beyond that:
- listing, rendering, adding, removing and duplicating are page based or single statements and are expected to keep working, but are **not measured** above 10 000; the System Status counts rows exactly up to 50 000, then reports `capped`;
- **reordering is refused** above 10 000 items (`PUT /galleries/{id}/order` accepts at most 10 000 ids and answers HTTP 400 `rest_invalid_param`; the editor sends the complete list). A move command for larger galleries is not implemented;
- split galleries that grow beyond that.

## Frontend (M5-02)
`e2e/tests/frontend-perf.spec.ts` (real Chromium): first row eager (columns, at most 6) with one `fetchpriority="high"`, everything else `loading="lazy"`, `decoding="async"` and `width`/`height`/`srcset`/`sizes` on every image; grid CLS 0.002 (budget 0.01) with identical item boxes before and after the images load; a page with a gallery requests exactly `layouts.css`, `lightbox.css`, `lightbox.js` (6.9 KB transferred including headers, budget 16 KB, no REST call); a page without a gallery requests no plugin asset. Build budgets (gzip) stay in `assets/scripts/check-build.mjs`.

## Migrations and uninstall (M5-05, M5-06)
- The schema has one version. **Next migration:** append the step to `Schema::steps()`, raise `Schema::CURRENT`, make the step repeatable (check the column or index before `ALTER`, or dbDelta), add the old layout to `MigrationUpgradeTest::legacy_table()` and extend its upgrade test.
- A stored schema version **newer than the code** (plugin downgrade) is never migrated or altered: `Migrator` refuses (log `swg_schema_newer`), every write fails with HTTP 503 `swg_schema_newer`, reads and rendering keep working, System Status shows `schema.newerThanCode`.
- Uninstall: `Lifecycle::uninstall_site()` (via `uninstall.php`) removes only galleries of every status with their meta, the item table, the plugin options and `swg_` transients, and only for sites that opted in. Attachments, other content, foreign tables and options are never touched (`UninstallDataTest`).
