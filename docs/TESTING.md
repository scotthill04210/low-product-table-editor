# Testing

Repeat this after a release. Use a staging copy. Do not run the load-test generator on production.

## Keyboard

With a mouse unplugged, or using only the keyboard:

1. Tab through filters, paging, Expand all, Collapse all, and Keyboard shortcuts.
2. In the grid, arrow keys move between cells. Enter edits or commits. Escape cancels. Tab and Shift+Tab move across. Space toggles a row checkbox.
3. Change a price and press Ctrl+S or Cmd+S. The browser save dialog must not open while focus is inside the editor.
4. Open History, a bulk action, and the description dialog. Escape closes only the top dialog and returns focus to the button that opened it.

## Catalog script

1. Load the table. Expect 8 parents (7 variable, 1 simple) and 74 variations. Sold By is filled for every variation.
2. Edit a per-foot price to `0.3299`, save, and reload. The grid and the native product editor show `0.3299`.
3. Add a sale price lower than the regular price and save. Then try a sale price equal to the regular price. The editor and the server both reject it.
4. Paste a column of regular prices from Excel across two products.
5. Edit the long and short description of a parent, and the description of a variation.
6. Bulk: increase all by 5 percent, target both, rounding none. Preview, confirm, apply. History shows one batch. Undo from the toast and from History.
7. Bulk a sample with nearest-cent rounding and check the cents by hand.
8. Open the storefront product pages. Note the shop's Number of Decimals setting next to the 4-decimal stored prices. The display rounding is a client decision.
9. Bookmark a filtered URL, reload, then use Back and Forward.
10. Try a duplicate SKU, a stale edit from a second tab, and a Shop Manager login.

## Load test

On local, development, or staging only:

```
wp eval-file tools/generate-test-catalog.php
wp eval-file tools/generate-test-catalog.php cleanup
```

The default creates 40 draft variable products with 30 variations each (1,200 variations), tagged `_low_pte_test`. Cleanup deletes only those posts.

Record `GET /products` time and query count at 25 and 100 per page, time to first render, scroll at 100 parents, `PATCH /items` for 50 items, a 1,000 item bulk apply, and `POST /undo` for that batch. With `WP_DEBUG` on, responses include `X-LOW-PTE-Queries`, `X-LOW-PTE-Memory`, and `Server-Timing`.

Target: 25 parents and about 100 variations load in under 1.5 seconds, and a page of 100 stays usable.

## Uninstall

1. Deactivate. The `low_pte_daily_purge` cron is removed. The log table stays.
2. Delete with the default setting. Options are removed. The log table stays.
3. Activate again, check "Delete the log table when the plugin is deleted", delete. The log table and `low_pte_db_version` are removed.
4. On multisite, with the plugin on one site, uninstall cleans only that site.

## Cache

Plugin REST responses send `Cache-Control: no-store`. The Table Editor screen sends the same.

Cloudflare should bypass cache for `/wp-admin/*`, `/wp-json/low-pte/*`, and `?rest_route=` URLs.

## Staging copy, 28 Oct 2026

The live catalog was not replaced with the 1,200 variation generator. SSH is disabled, so that run has to be `wp eval-file` on a machine with WP-CLI.

* 8 parents (7 variable, 1 simple), 74 variations. 71 variations have Sold By. Variations 136, 137, and 138 only have a size attribute.
* Full catalog `get_products` at 25 and 100 parents: 118 ms the first time, 7 ms the second. `SAVEQUERIES` is off, so the query header stayed at 0.
* With `WP_DEBUG`, `GET /wp-json/low-pte/v1/products` sends `Cache-Control: no-store`, `X-LOW-PTE-Queries`, `X-LOW-PTE-Memory`, and `Server-Timing`.
* A sale price equal to the regular price is rejected. A duplicate SKU is rejected. A stale `date_modified` returns a conflict. Variation 106 stored `0.3299` exactly, then was restored to `63.00` with its original modified time. Menu order 184 stayed 36.
* Parent 133's storefront range is `2.06` to `630.00` while the cheapest stored variation price is `2.0574`. The shop's Number of Decimals is 2. That setting was not changed.
* A Shop Manager can load the products route. The temporary user was deleted.
* The daily purge cron is scheduled. The plugin was not deactivated or deleted.

## Later, not now

CSV or Excel import and export, paired roll and case pricing, saved column views, scheduled sale dates, per-role field restrictions, and more attribute filters.
