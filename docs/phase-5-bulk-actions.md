# Cursor Prompt: Phase 5 of 7, Bulk Price Actions

Prerequisite: Phase 4 passes its acceptance criteria. Paths are relative to the plugin root.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer building **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories, variable products with a "Sold By" attribute, prices with up to 4 decimals (example: 0.3299). About 8 products and 75 variations today, but build for 1,000+.
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare. Works on one site of a multisite.
- Naming: text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WPCS, translatable strings, escape output, no em dashes, no build step, plain JS. If `app.js` passes about 1,200 lines, split it into files under `assets/js/` registered with script dependencies (no bundler).
- WooCommerce rules: CRUD setters and `->save()` only, never write price meta directly. Set only `set_regular_price()` and `set_sale_price()`.
- Security: `manage_woocommerce` on every route, `edit_post` per item, nonce via `X-WP-Nonce`, validate everything server-side, `WP_Error` with status codes.

## Goal of this phase

Selection, action configuration, preview, explicit confirmation, and chunked apply with a progress bar, all under one shared batch id. Nothing is ever silently dropped.

## Server tasks

### `includes/class-bulk-service.php`

**Selection resolution** `resolve_selection( array $selection ): array`
- `{ "mode": "ids", "ids": [ ... ] }` or `{ "mode": "filter", "filters": { search, category, type, stock_status, status } }`.
- The target set is the priced items only: simple products and variations. Variable parent IDs expand to their variations (one batched query, same pattern as phase 2). Variable parents themselves are never targets.
- Filter mode resolves every matching parent ID without paging (reuse the phase 2 query logic with `limit => -1`), then expands to variations.
- Drop IDs the user cannot `edit_post`, and report them as skipped with reason.

**Calculation** `calculate( string $old, array $action ): array` returning `{ new, status, reason }`
- Action config: `operation` (`increase_percent`, `decrease_percent`, `increase_amount`, `decrease_amount`, `set_value`), `value` (string, max 4 decimals, non-negative), `target` (`regular`, `sale`, `both`), `rounding` (`none`, `cent`, `0.05`, `0.10`).
- Use integer math in ten-thousandths of a currency unit to avoid float drift: parse `old` and `value` to integers, percent math as `old * (10000 +/- pct_hundredths) / 10000`, half-up rounding. Rounding steps: `none` keeps 4 decimals, `cent` rounds to 100 units, `0.05` to 500, `0.10` to 1000. Format back with `wc_format_decimal( $v, 4, true )` (trim zeros, so 12.5000 becomes 12.5).
- Skip rules: increase and decrease skip an empty price (`reason: price_empty`). `set_value` does not skip empties.
- Validation rules (item becomes `error`, not written): result below zero, regular price result of zero or empty, sale price result not strictly lower than the resulting regular price. For `target = both`, validate the resulting pair together. For `sale` only, validate against the current regular price. For `regular` only, validate the current sale price against the new regular price.
- Per-field outcomes roll up to an item outcome: `changed` (at least one field changes), `unchanged` (computed equal to old), `skipped`, or `error`. Include per-field detail so the preview can show partial skips.

**Endpoints** in `class-rest-controller.php`
- `POST /bulk/preview`: body `{ selection, action }`. Writes nothing. Response: `{ summary: { total, changed, unchanged, skipped, errors }, item_ids: [ all targeted ids ], rows: [ { id, parent_id, name, sold_by, field, old, new, change, status, reason } ], truncated: bool }`. Return rows for at most 500 items (`truncated: true` beyond that, with the summary still covering everything). Compute `change` as the signed difference.
- `POST /bulk/apply`: body `{ batch_id, selection: { mode: "ids", ids: [max 25] }, action, chunk_index, chunk_total }`.
  - Validate `batch_id` is a UUID v4. Accept a new id, or an existing id only if every existing row for it has the same `user_id` and `source = bulk` (so chunks append to one batch, and no one can append to someone else's).
  - Recompute every new price from the CURRENT stored price at apply time. Never trust preview values.
  - Max 25 ids (400 above that).
  - For each item: CRUD setters, `save()`, `try/catch` as in phase 3, per-item `changed|unchanged|skipped|error` with reason.
  - Log changed fields via `Product_Service::log_changes( $batch_id, 'bulk', ... )` (old values from before, new values from the saved product).
  - Core defers parent sync to shutdown (`wc_deferred_product_sync`), but verify that against the installed WooCommerce version. At the end of EACH chunk, call `\WC_Product_Variable::sync( $parent_id )` once per affected parent and `wc_delete_product_transients( $parent_id )`, so data returned and cached is consistent. Never sync per variation.
  - Response: `{ batch_id, results: [ { id, status, reason, message } ], applied, skipped, errors, log_failed }`.

## Client tasks

### Selection
- First column gets Tabulator `rowSelection` checkboxes. A parent checkbox selects and deselects all its variations and shows an indeterminate state when partly selected. Simple products select themselves.
- Header checkbox selects all rows on the current page. When it is checked and total matching products exceed one page, show a banner: "N items on this page are selected. Select all M items matching the current filters." Clicking switches to `mode: filter`. A "Clear selection" link resets.
- A selection toolbar appears when anything is selected: "N items selected" and a **Bulk price action** button. Selection survives expand and collapse. Selection clears on filter or page change, with the banner explaining that when it happens.
- Bulk actions are disabled while there are unsaved inline edits (phase 3 dirty store), with a message telling the user to save or discard first.

### Configure modal
- Fields: Operation (radio group: increase by percent, decrease by percent, increase by amount, decrease by amount, set to value), Value input (text, 4 decimals max, unit label switches between `%` and `$`), Target (regular, sale, both), Rounding (none default, nearest cent, 0.05, 0.10).
- Inline validation. **Preview** button calls `POST /bulk/preview`. Loading state, error state, focus management, Esc closes.

### Preview modal
- Summary line: items changed, unchanged, skipped (empty price), errors.
- Table of rows: Product (with parent), Sold By, Field, Old, New, Change, Status. Errors and skips are visibly marked with an icon and text, not color alone, and a filter toggle "Show only problems".
- If `truncated`, show "Showing the first 500 of N items. The summary covers all of them."
- Confirm button text states the exact count: "Apply to 74 items" (count of items that will change). Disabled when zero.
- If the changed count is over 50, require ticking "I understand this will change N items" before the button enables.
- **Back** returns to the configure step with values kept.

### Apply with progress
- Generate the `batch_id` with `crypto.randomUUID()` once per action. Send chunks of 25 ids sequentially using `item_ids` from the preview. Progress bar with `role="progressbar"`, `aria-valuenow`, and text "Applied 50 of 74".
- A **Stop after this chunk** button. Stopping leaves the applied portion in one undoable batch and reports exactly what was and was not applied.
- If a chunk request fails at the network level, retry that chunk once, then stop and report. Re-sending a chunk is safe because values are recomputed and unchanged results are reported as `unchanged`.
- Final report modal: counts of changed, unchanged, skipped, and errors, then a list of every skipped and failed item with its reason. Offer "Download report as CSV" (client side Blob) for problems. Nothing is dropped silently. After closing, refetch the current page.
- Leave a commented slot for an **Undo this batch** button (phase 6).

## Acceptance criteria

- Select all 74 variations, "Increase by percent 5", target both, rounding none: preview shows correct old and new for every item with 4 decimal prices preserved (for example 0.3299 up 5 percent gives 0.3464 with half-up rounding of 0.346395). Button reads "Apply to N items" with the checkbox required (over 50).
- Rounding options give correct results on a hand-calculated sample of at least 5 prices, including a case that rounds a sale price up to equal the regular price, which must report as an error and not write.
- Items with an empty sale price are reported as skipped for "increase sale", not dropped and not errors. Totals in the summary add up to the number of targeted items.
- Apply runs as 3 chunks for 74 items. All log rows share one `batch_id` with `source = bulk`. History tab shows it as one batch.
- Parent variable price ranges are correct after apply. Verify the number of `WC_Product_Variable::sync` calls equals the number of affected parents per chunk.
- "Select all matching current filters" works across pages and respects the active filters. A non-admin role without `manage_woocommerce` gets 403 from every bulk route.
- Preview writes nothing (confirm with a log row count and a changed `date_modified` check). A chunk of 26 ids returns 400. Reusing someone else's `batch_id` is rejected.
- Query count per chunk is roughly constant per item (no repeated parent loads). `phpcs` passes, no notices.

## Out of scope

Undo. Stop after this phase and report with timings for a 74 item and a 1,000 item action if you can generate test data.
