# Cursor Prompt: Phase 6 of 7, Undo

Prerequisite: Phase 5 passes its acceptance criteria. Paths are relative to the plugin root.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer building **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories, variable products with a "Sold By" attribute, prices with up to 4 decimals.
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare. Works on one site of a multisite.
- Naming: text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WPCS, translatable strings, escape output, prepared SQL, no em dashes, no build step, plain JS.
- WooCommerce rules: CRUD setters and `->save()` only, never write price meta directly.
- Security: `manage_woocommerce` on every route, `edit_post` per item, nonce via `X-WP-Nonce`, `WP_Error` with status codes.
- Log table `{$wpdb->prefix}low_pte_log` columns: `id, batch_id, user_id, product_id, parent_id, field, old_value, new_value, source (inline|bulk|undo), created_at, undone_at`.

## Goal of this phase

Batch undo that is conflict-safe, plus the post-save Undo toast and the Undo batch button in History. An undo never overwrites anything that changed after the original edit.

## Rules (enforce on the server)

1. **Conflict-safe restore.** For each logged entry, restore `old_value` only if the item's CURRENT value for that field still equals the logged `new_value`. If not, skip that entry and report it with reason `changed_since`. Never overwrite.
2. **Normalized comparison.** Compare using the same normalization used when logging (strings, `yes`/`no` booleans, `parent`, NULL for empty). Prices compare as normalized decimal strings so `0.30` and `0.3` are equal.
3. **Missing items.** If the product or variation no longer exists or is trashed, skip with reason `missing`.
4. **Undo is its own batch** with a new batch id and `source = undo`. Log rows for it record `old_value` = the value just replaced and `new_value` = the restored value.
5. **An undo batch cannot be undone.** Requests to undo a batch whose source is `undo` return 400 with a clear message.
6. **Mark restored entries.** Set `undone_at` (GMT now) on each original entry that was successfully restored. Skipped entries stay unmarked.
7. **Retry rule.** A batch with some entries unrestored can be undone again, and only entries with `undone_at IS NULL` are considered. Entries skipped for `changed_since` will skip again unless the value was put back, which is the correct safe behavior.
8. **Permissions.** `manage_woocommerce` for the route, `edit_post` for each item. Items the user cannot edit are skipped with reason `forbidden`.

## Server tasks

### `includes/class-product-service.php`, method `undo_batch( string $batch_id ): array`
- Load the batch rows with `get_batch_entries` equivalent that returns raw rows (not display data). 404 if the batch has no rows. 400 if `source = undo`. 409 if every entry is already undone.
- Group entries by `product_id` so each product is loaded, changed, and saved ONCE (not once per field). Prefetch products in one batch with meta cache priming, same pattern as `get_products()`.
- Apply restores with CRUD setters. For price pairs restored together (`regular_price` and `sale_price` on the same product), set both before a single `save()`. For `manage_stock`, `stock_quantity`, and `stock_status`, restore in an order that avoids WooCommerce re-deriving values: `manage_stock` first, then `stock_quantity`, then `stock_status` only when stock is not managed.
- Catch `\WC_Data_Exception` and `\Throwable` per product, mark that product's entries as failed with the message, and continue.
- After all saves, call `\WC_Product_Variable::sync( $parent_id )` once per affected parent and `wc_delete_product_transients( $parent_id )`.
- Log through `log_changes( $new_batch_id, 'undo', ... )` and set `undone_at` on restored originals in one prepared `UPDATE ... WHERE id IN (...)` per 500 ids.
- Return `{ undo_batch_id, restored: n, skipped: [ { product_id, name, field, reason, expected, current } ], failed: [ ... ], log_failed }`.

### `POST /undo/{batch_id}` in `class-rest-controller.php`
- `batch_id` must match a UUID pattern (validate with `validate_callback`). Calls `undo_batch()`. No request body needed.
- Extend `GET /log` batch rows with `can_undo` (source is not `undo` and `undone_count < total_count`) and `undo_state` (`none`, `partial`, `full`).

## Client tasks

### Post-save Undo toast
- The `PATCH /items` response already includes `batch_id`. After a successful save, show "Saved N items. **Undo**" for 15 seconds (pause the timer on hover or focus). The toast must be reachable by keyboard and announced politely to screen readers.
- Clicking Undo calls `POST /undo/{batch_id}` immediately (no extra confirmation, because the toast is the confirmation context), then shows a result toast: "Restored N changes" and, if any were skipped, "N could not be restored because they changed since. See History." Then refetch the current page.
- If the user has unsaved inline edits when they click Undo, keep those edits but warn that affected rows reloaded and conflicting edits were dropped (reuse the phase 3 row reload logic).
- Toast only appears for the latest save, and an Undo for an older batch remains available in History.

### Bulk completion report
- Replace the commented slot from phase 5 with an **Undo this batch** button in the final report, using the same confirmation modal as History.

### History tab
- Replace the commented slot from phase 4 with an **Undo batch** button on each batch row where `can_undo` is true. Hidden for `source = undo` batches and fully undone batches.
- Confirmation modal: "Undo this batch? This restores N changes across M items. Anything changed since will be left alone and reported." Buttons: **Undo batch** and **Cancel**. Focus management and Esc as in other modals.
- Show `undo_state` badges: "Undone" and "Partly undone". Expanded entry rows mark restored entries.
- After an undo, show a result modal: restored count, then a table of every skipped and failed item with its reason in plain language (`changed_since`: "Changed since this edit, left as is", `missing`: "Item no longer exists", `forbidden`: "You cannot edit this item"). Link the new undo batch id in History.
- Undo batches appear in History labeled "Undo" with no Undo button.

## Acceptance criteria

- Edit 5 prices, Save, click Undo in the toast within 15 seconds: all 5 values return to their exact previous strings (including 4 decimal values), `date_modified` updates, and History shows the original batch as "Undone" and a new "Undo" batch with matching rows.
- Run the bulk "Increase by 5 percent" on all 74 variations, then change 3 of those prices by hand, then undo the bulk batch: 71 are restored, 3 are skipped with `changed_since`, and the three hand edits are untouched. The report lists those 3.
- Undo a batch twice: the second request returns 409 if everything was restored. With partial restores, the second run touches only unrestored entries and never overwrites conflicts.
- Undoing an undo batch returns 400.
- Undo of an entry whose product was trashed reports `missing` and does not error the whole request.
- Parent variable price ranges are correct after undo. Query count scales with the number of products, not with the number of fields.
- Stock restore sanity check: undo a change to `manage_stock` plus `stock_quantity` on a variation and confirm both return correctly with no WooCommerce derived-value surprises.
- A Customer role gets 403. A malformed batch id gets 400. `phpcs` passes, no notices with `WP_DEBUG` on.

## Out of scope

Keyboard and accessibility polish, load testing, uninstall QA. Stop after this phase and report.
