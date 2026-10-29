# Cursor Prompt: Import and Export (CSV first, Excel second)

Prerequisite: Phases 1 to 7 are done, version 1.0.0 is committed, and Phase 6 undo works. Paths are relative to the plugin root. This adds version 1.1.0.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer extending **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories. Variable products where each variation has a "Size" attribute and a "Sold By" attribute (Roll, Case, Each, Square Foot, Foot, Gallon). Prices can have up to 4 decimals (example: 0.3299 per foot). About 8 products and 75 variations, but build for 1,000+.
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare in front. Works when activated on one site of a multisite. No network-level options.
- Naming: text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, constants `LOW_PTE_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WordPress Coding Standards, translatable strings, escaped output, prepared SQL only, no em dashes anywhere, no build step, plain JS. If `assets/js/app.js` is large, put the new UI in `assets/js/import-export.js`, enqueued with a dependency on `low-pte-app`.
- WooCommerce rules: write ONLY through CRUD setters and `->save()`. Never write price meta directly. Set only `set_regular_price()` and `set_sale_price()`.
- Security: `manage_woocommerce` on every route and handler, `edit_post` per item, nonces, validate and sanitize server-side, `WP_Error` with status codes, no stack traces.
- Existing pieces to REUSE, not duplicate: the field validation matrix and `update_items()` conflict, save, and logging path in `Product_Service` (phase 3 and 4), batch chunking and per-parent sync in `Bulk_Service` (phase 5), and `undo_batch()` (phase 6). Refactor shared validation into a method both PATCH and import call, so rules cannot drift.

## Goal

Let the client export the catalog to a spreadsheet, edit it in Excel, and import it back with a preview, explicit confirmation, chunked apply, audit logging, and undo. Rows match on **SKU**. Import never creates, deletes, or trashes products.

## Stage 1: CSV export and import

### Export

- **Entry point:** a new third tab, **Import / Export**, next to Table and History (URL state `tab=importexport`).
- **Options:** scope (everything matching nothing, current table filters, or currently selected rows), a checkbox "Include descriptions" (default off), format CSV. Button **Download export**.
- **Delivery:** `admin-post.php?action=low_pte_export` with a nonce and `manage_woocommerce` check, streaming with `php://output` and `fputcsv`. Headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="low-pte-export-YYYYMMDD-HHMM.csv"`, `Cache-Control: no-store`. Write a UTF-8 BOM first so Excel reads accents correctly. Load products in batches of 50 parents with variations in one query and primed meta caches (same pattern as `get_products()`), so memory stays flat for thousands of rows. No N+1.
- **Columns, in this order:**

| Column | Notes |
|---|---|
| `sku` | The match key. Not editable by import. |
| `type` | `simple`, `variable`, or `variation`. Read-only. |
| `parent_sku` | Read-only. |
| `name` | Editable for simple and variable products. Read-only for variations. |
| `variation_label` | Read-only (the Size). |
| `sold_by` | Read-only. |
| `regular_price` | Raw stored string, never formatted. Empty for variable parents. |
| `sale_price` | Raw stored string. |
| `manage_stock` | `yes`, `no`, or `parent` (variations only). |
| `stock_quantity` | Integer or empty. |
| `stock_status` | `instock`, `outofstock`, `onbackorder`. |
| `weight` | Raw string. |
| `status` | `publish`, `draft`, `private` (products only). |
| `catalog_visibility` | `visible`, `catalog`, `search`, `hidden` (products only). |
| `description` | Only when "Include descriptions" is on. |
| `short_description` | Only when on, products only. |
| `modified` | Unix timestamp of the item's `date_modified` at export time. Read-only, used for "changed since export" detection. |
| `id` | Read-only, last column, for reference only. Never used for matching. |

- **Spreadsheet injection:** for text columns (`name`, `variation_label`, `sold_by`, `description`, `short_description`), prefix a single quote when a value starts with `=`, `+`, `-`, `@`, tab, or carriage return. Import strips that leading quote only when it is followed by one of those characters.
- Write the export as a reusable `Import_Export_Service::export_rows( array $args ): \Generator`.

### Import: parsing (`includes/class-import-export-service.php`, new)

- **Upload:** `POST /import/preview` (multipart `file`, options in the body). Read from the temporary upload only. Never move the file into the web root. Limits: 5 MB and 5,000 data rows (clear error beyond that, telling the user to split the file). Allow `.csv` only at this stage, validate the extension and detect the real content type with `finfo` (accept `text/csv`, `text/plain`, `application/vnd.ms-excel` for CSV).
- **Encoding and format:** detect and strip a UTF-8 BOM, convert UTF-16 (Excel "Unicode Text") to UTF-8, reject anything that cannot be read as UTF-8. Auto-detect the delimiter among comma, semicolon, and tab. Handle CRLF and LF. Parse with `fgetcsv`.
- **Headers:** case-insensitive, trimmed, with aliases (for example "Regular price", "regular price", "Regular Price" all map to `regular_price`). Unknown columns are ignored and listed in the preview notice. A file with no `sku` column is rejected with a clear message.
- **Column rule:** a column that is absent leaves that field untouched. A column that is present with a blank cell means "clear this value", allowed for `sale_price`, `weight`, `stock_quantity`, `description`, `short_description`. A blank `regular_price` or `name` is a row error, never a clear.
- **Numbers:** accept only a dot decimal point, with at most 4 decimals. Strip a leading `$` and surrounding spaces. Reject commas with the message "Use a dot for decimals, no thousands separators." Reject negatives, `NaN`, scientific notation, and anything not matching `^\d+(\.\d{1,4})?$` after cleanup. Spreadsheet programs may rewrite `63.00` as `63`, so compare numerically as normalized decimal strings (`0.30` equals `0.3`).
- **SKU cleaning:** trim whitespace and non-breaking spaces. Matching is case-insensitive and exact otherwise. Warn when a SKU looks mangled by Excel (scientific notation like `1.2E+5`).

### Import: matching and validation

- **Resolve SKUs in bulk:** one prepared query per 500 SKUs against `{$wpdb->wc_product_meta_lookup}` (`sku` to `product_id`), with a fallback to `_sku` postmeta if the lookup table is unavailable. No per-SKU `wc_get_product_id_by_sku` calls.
- **Row outcomes:** `will_change`, `unchanged`, `not_found` (SKU not in the store, never created), `error` (validation failed), `changed_since_export` (the item's current `date_modified` differs from the file's `modified`), `duplicate_in_file` (the same SKU twice, both rows rejected).
- **Validation:** call the shared validation from `Product_Service` so rules match PATCH exactly: price format, sale price strictly lower than the resulting regular price, stock rules (quantity only when stock is managed, status only when not managed, `parent` only for variations whose parent manages stock), enums, and `edit_post` capability. Variable parents ignore price columns with a warning. Variations ignore `name`. A row with any invalid field is rejected as a whole (atomic per row), with every field error listed.
- **Compare against current values** (loaded in batches with primed caches). Only fields that differ become changes. A file exported and re-imported untouched must produce zero changes.
- **Preview response:** `{ token, summary, notices, rows, truncated }`.
  - `summary`: rows in file, matched, items that will change, individual cell changes, clears, unchanged, not found, errors, changed since export, duplicates.
  - `rows`: up to 500 detail rows `{ line, sku, name, field, old, new, status, message }`, with `truncated: true` beyond that while the summary still covers everything.
  - `token`: a random 32 hex string. Store the validated, normalized change set server side as a transient `low_pte_import_{user_id}_{token}` (gzip-compressed JSON, 1 hour TTL, one active import per user, replaced on a new preview). If it is too large to store, return a clear error rather than failing silently. Delete it after a successful apply, and via the existing daily purge.

### Import: options (on the Import panel)

- "Skip rows changed since export" (default on). When off, those rows are applied and flagged in the report.
- "Allow clearing values" (default off). When off, blank cells in present columns are ignored instead of cleared. When on, the preview lists every clear in a separate "Clears" group and requires a confirmation checkbox "This file clears N values."

### Import: apply

- `POST /import/apply` with `{ token, batch_id, chunk_index, chunk_total, options }`. Server reads the stored change set and processes the next 25 rows (by `chunk_index`), validates the token belongs to the current user, and returns per-row results.
- Reuse the save and log path. Check each item's `date_modified` conflict logic per the chosen option, then CRUD setters, `save()`, `try/catch` for `WC_Data_Exception`, and log changed fields with `source = import` (6 characters, fits the column). All chunks share one `batch_id` created by the client with `crypto.randomUUID()`, validated as a UUID v4 and owned by the same user.
- Recompute against CURRENT values at apply time and report `changed_since_preview` for anything that moved in between, never overwriting silently.
- At the end of each chunk, call `\WC_Product_Variable::sync( $parent_id )` once per affected parent and `wc_delete_product_transients( $parent_id )`. Never sync per variation. Preserve `menu_order` the same way phase 5 does.
- Make `undo_batch()` accept `import` batches. Update History source labels (Inline, Bulk, Import, Undo), the History filters, and the batch summary text.

### Import: UI

1. **Panel layout:** left card Export, right card Import. Import has a file drop zone and a keyboard accessible "Choose file" button, the two options, and **Preview import**.
2. **Preview modal or panel:** summary counts as a labeled list, notices (ignored columns, warnings), a table of changes (Line, SKU, Product, Field, Old, New, Status) with "Show only problems" and a status filter, and `truncated` messaging. Problems are marked with an icon and text, not color alone.
3. **Confirm button** states the exact count: "Apply 34 changes to 12 items". Over 50 items requires ticking "I understand this will change N items." Disabled at zero.
4. **Progress:** chunks of 25, progress bar with `role="progressbar"`, **Stop after this chunk**, retry once on network failure.
5. **Final report:** changed, unchanged, skipped (changed since export or preview), not found, errors, with every problem row listed and **Download problems as CSV** (client side, same spreadsheet injection protection). Include **Undo this batch** with the phase 6 confirmation. Nothing is dropped silently.
6. Disable Import and Export actions while there are unsaved inline edits, with the same message as bulk actions. After a successful apply, refetch the Table tab data.
7. Accessibility: labeled controls, visible focus, focus trap and Escape handling in modals, live region announcements, keyboard-only operation.

### Docs and release

- `docs/IMPORT-EXPORT.md`: the column reference above, the blank-cell rule, the "changed since export" behavior, a worked example (export, change three sale prices in Excel, import), and known Excel pitfalls (SKU mangling, decimal commas in some locales, "Save as CSV UTF-8").
- Add `docs/sample-import.csv` with 6 rows matching the test catalog structure.
- Bump to `1.1.0`, update `readme.txt` and `docs/CHANGELOG.md`, keep `.distignore` excluding docs and tools.

## Stage 2: Excel (.xlsx) support, only after Stage 1 passes and I confirm

Stop after Stage 1 and report. Do not start Stage 2 until I say so. When approved:

- Add `.xlsx` export and import using a bundled, MIT licensed streaming library (OpenSpout is the preferred candidate), committed under `includes/vendor/` with its LICENSE and a pinned version noted in `VERSION.txt`. No Composer at runtime, no CDN. Confirm its PHP requirement against 8.1 and the WPMU DEV host before bundling, and report the added size.
- Write SKU, text, and description cells as strings so Excel cannot mangle them, and write price columns as strings or number cells with an explicit 4 decimal format so values like `0.3299` round-trip. Freeze the header row, bold it, shade the read-only columns, and set column widths.
- Read only the first worksheet, reject files with macros (`.xlsm`), cap size and rows like CSV, and reuse the same parser output so validation and preview behave identically.
- Add `.xlsx` to the upload validation (extension plus ZIP signature plus content type check), and keep the CSV path working.

## Acceptance criteria

- **Round trip:** export everything, import the unchanged file, and the preview shows 0 changes. 4 decimal prices (`0.3299`, `0.4657`, `2.0574`) survive unchanged after opening and re-saving the CSV in Excel.
- Change 3 sale prices and 2 regular prices in Excel, import: the preview lists exactly 5 cell changes with correct old and new values. Apply logs one `import` batch with 5 rows. **Undo this batch** restores all 5 exactly.
- A sale price equal to or above the regular price rejects only that row, with the same message as the table editor. Other rows in the file still apply.
- A SKU not in the store is reported as `not_found` and never creates a product. The same SKU twice in the file rejects both rows.
- An item edited in the table after the export is flagged `changed_since_export` and skipped by default, applied when the option is off.
- Blank `sale_price` cells are ignored unless "Allow clearing values" is on. With it on, the preview shows each clear and requires the confirmation checkbox.
- A file missing optional columns (only `sku` and `regular_price`) changes only regular prices. A file with unknown columns shows them in the notice and ignores them.
- Semicolon and tab delimited files, a UTF-8 BOM file, and a UTF-16 "Unicode Text" file all parse. A file with `1,234.50` or `0,3299` is rejected row by row with a clear message.
- A cell starting with `=` in the export is neutralized and imports back to the original text.
- 5,001 rows, a 6 MB file, a non-CSV file, and a missing `sku` column are each rejected with a friendly message. A logged-out request gets 401, a Customer role gets 403, and the export link without a valid nonce fails.
- Preview writes nothing (check log row count and `date_modified`). A 1,000 row import previews in a few seconds and applies in chunks of 25 with flat query growth per chunk.
- Parent variable price ranges are correct after apply, `menu_order` is unchanged on every saved variation, and no direct price meta writes exist (`grep` check as in phase 3).
- `phpcs` passes, no PHP notices with `WP_DEBUG` on, no console errors, axe reports no serious or critical issues on the Import / Export tab and its dialogs.

## Out of scope

Creating new products or variations, deleting or trashing products, changing SKUs, attribute edits, images, categories, paired roll and case pricing, saved column views, and scheduled sale dates. Stop after Stage 1 and report timings, a sample export, and any deviations.
