# Cursor Prompt: Phase 3 of 7, Edit Path

Prerequisite: Phase 2 passes its acceptance criteria. Paths are relative to the plugin root. This is the last phase of the first client deliverable.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer building **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories. Variable products where each variation has a "Sold By" attribute. Prices can have 4 decimals (example: 0.3299 per foot).
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare. Single site of a multisite supported.
- Naming: text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WordPress Coding Standards, translatable strings, escape output, no em dashes anywhere, no build step, plain JS.
- WooCommerce rules: write ONLY through CRUD setters and `->save()`. Never write `_price`, `_regular_price`, `_sale_price` meta directly. Set only `set_regular_price()` and `set_sale_price()`; WooCommerce derives `_price` and syncs the parent range.
- Security: `permission_callback` with `current_user_can( 'manage_woocommerce' )` on every route, plus `current_user_can( 'edit_post', $id )` per item. Validate and sanitize server-side. Reject unknown fields. Return `WP_Error` with status codes. No stack traces.

## Goal of this phase

Inline editing with dirty tracking, a Save bar, `PATCH /items` with validation, per-item results, conflict detection, a description modal, and paste from Excel.

## Editable field matrix (enforce on the server)

| Field | Simple | Variable parent | Variation | Sanitize and validate |
|-------|--------|-----------------|-----------|-----------------------|
| `regular_price` | yes | no | yes | Must match `^\d+(\.\d{1,4})?$`. Not empty (reject clearing with "Regular price is required"). Store `wc_format_decimal( $v, false )`. |
| `sale_price` | yes | no | yes | Empty clears. Same format rule. Must be strictly lower than the resulting regular price (consider a regular price edited in the same request). |
| `sku` | yes | yes | yes | `wc_clean`. Empty allowed. Catch `\WC_Data_Exception` for duplicates and return it as that item's error. |
| `weight` | yes | no | yes | Empty or non-negative decimal, `wc_format_decimal`. |
| `manage_stock` | yes | no | yes | Simple: bool. Variation: `true`, `false`, or `"parent"`. |
| `stock_quantity` | yes | no | yes | Integer. Rejected unless the item's resulting `manage_stock` is `true`. |
| `stock_status` | yes | no | yes | `instock|outofstock|onbackorder`. Rejected when stock is managed (WooCommerce derives it from quantity). |
| `name` | yes | yes | no | `wc_clean`, required, max 200 chars. |
| `status` | yes | yes | no | `publish|draft|private`. Variations are read-only here. |
| `catalog_visibility` | yes | yes | no | `visible|catalog|search|hidden`. |
| `description` | yes (long) | yes (long) | yes | `wp_kses_post`. |
| `short_description` | yes | yes | no | `wp_kses_post`. |

Unknown fields or fields not allowed for the item type produce a per-item error, not a silent drop.

## Server tasks

### `includes/class-rest-controller.php`
- `GET /items/{id}`: full record for one item (all editable fields, full `description` and, for products, `short_description`, plus `date_modified`). 404 for unknown or non-product ids.
- `PATCH /items`: body `{ "items": [ { "id": 124, "date_modified": 1767000000, "fields": { "regular_price": "0.3299" } } ] }`. Max 50 items (400 above that). Each item needs `id`, `date_modified`, and a non-empty `fields` object.
- Response: `{ "results": [ { "id": 124, "status": "ok|error|conflict", "message": "", "errors": { "sale_price": "Sale price must be lower than the regular price." }, "item": { fresh row }, "parent": { fresh parent row without children, only for variations } } ] }`. HTTP 200 even when some items fail; per-item status carries the outcome.

### `includes/class-product-service.php`, method `update_items( array $items ): array`
1. **Conflict check first, for the whole request, before any write.** Compare each item's posted `date_modified` to the current `get_date_modified()->getTimestamp()`. A mismatch returns `status: conflict` with message "This item was changed by someone else. Reload the row to see the latest values." and that item is not written.
2. For each remaining item: load with `wc_get_product()`, check `edit_post`, validate every field (collect all field errors, do not stop at the first), apply with setters, `save()` inside `try/catch` for `\WC_Data_Exception` (message to the user) and `\Throwable` (generic message, `error_log` the detail when `WP_DEBUG`).
3. Only call setters for fields whose value actually differs from the current value.
4. Rely on core to sync the parent variable product when a variation saves. Do not call `WC_Product_Variable::sync()` in this phase (bulk handles it in phase 5). After saving, return the fresh row, and for variations also the fresh parent row so derived stock status refreshes.
5. Share the row-building code with `get_products()` so list rows and result rows are identical in shape. Phase 2 code should be refactored into a `build_row( \WC_Product $product ): array` helper.

## Client tasks

### Editing
- Click a cell to edit. Enter commits and moves down, Tab commits and moves right, Esc cancels, arrow keys navigate between cells when not in an input.
- Editable columns by row type follow the matrix. Non-editable cells get `low-pte-readonly` styling and ignore clicks.
- Custom editors: text input for prices and weight (so raw decimals are preserved and no number-spinner rounding), integer input for quantity, select for stock status, a three-way select for variation manage stock (`Yes`, `No`, `Inherit from parent`), select for status and visibility.
- Stock rules in the UI: when `manage_stock` is `parent` or `false`, the quantity cell is disabled and shows "Inherited" or a dash. When it is `true`, the stock status cell is disabled and shows the derived value.
- Inline validation with a tooltip on the cell (regex, non-negative, sale price lower than regular price). Invalid edits are blocked and the cell keeps its previous value.

### Dirty state
- One store: `Map<itemId, { date_modified, fields: { field: { original, value } } }>`. If an edited value equals the original, remove the entry.
- Highlight dirty cells (`low-pte-dirty`) using a left border and background, not color alone.
- Sticky bottom bar: "N unsaved changes" with **Save changes** and **Discard**. Hidden at zero. `beforeunload` warning while dirty. Disable page changes, filter changes, and per-page changes while dirty, with a message telling the user to save or discard first (the server owns paging, so edits would otherwise be lost).

### Save
- Send only changed fields, in batches of at most 50 items per request, sequentially.
- Apply per-item results: `ok` clears dirty state for that item and updates the row from the returned `item` and `parent`; `error` keeps edits, marks the failing cells `low-pte-error` with the server message as tooltip text; `conflict` marks the row with a "Reload row" action that calls `GET /items/{id}`, replaces the row, and drops that row's dirty edits.
- Per-row status icon in the first column after save: saved check (clears on next edit) or error icon with a tooltip. Icons also carry text for screen readers.
- Toast on success: "Saved N items." (the Undo link arrives in phase 6). Toasts use `role="status"`.
- Partial failure leaves failed cells marked and everything else saved.

### Description modal
- Clicking a Description cell calls `GET /items/{id}` and opens an accessible modal (`role="dialog"`, `aria-modal`, focus trap, Esc closes, focus returns to the cell).
- Parents and simple products show two labeled textareas: Description and Short description. Variations show one: Description.
- "Apply" writes into the dirty store (it does not save to the server). The cell preview updates and is marked dirty. Save still happens from the Save bar.

### Paste from Excel
- Enable the Tabulator clipboard and range selection modules (check the pinned version's docs for `selectableRange`, `clipboard`, `clipboardPasteParser`, `clipboardPasteAction`).
- Pasting a block fills downward and across from the selected cell. Cells that are not editable (parent rows for price columns, derived stock cells) are skipped without consuming a pasted value, so a column of variation prices pastes cleanly across several parents.
- Every pasted value goes through the SAME validate-and-mark-dirty function as typed edits (one `applyEdit( row, field, value )` function). Invalid pasted values are rejected, and a single toast summarizes how many were rejected and why.
- Copy (Ctrl or Cmd+C) of a selected range puts tab-separated raw values on the clipboard.

## Acceptance criteria

- Editing `regular_price` to `0.3299` on a variation saves and reloads as `0.3299`. The frontend and the native product editor show the same stored value.
- Sale price equal to or above regular price is blocked in the UI. Bypassing the UI with a direct REST call returns a per-item error.
- Clearing a sale price removes it. Editing a variation price updates the parent's displayed price range in WooCommerce (check a variable product page).
- Duplicate SKU returns a per-item error and does not abort other items in the batch.
- Two browser tabs: edit and save in tab A, then edit the same item in tab B (loaded earlier) and save. Tab B gets a conflict for that item, other items in the batch still save.
- Confirm in a test that saving a variation does not make a sibling variation's `date_modified` change. If parent `date_modified` changes after a child save, document it and make sure the conflict check cannot false-positive in the same request.
- A variation with `manage_stock = parent` shows "Inherited" and a disabled quantity cell, and saves without being coerced to `false`.
- A 51-item PATCH returns 400. An unknown field returns a per-item error. A Customer role gets 403.
- Paste a 10-row Excel column into Sale price across two parents: values land on variation rows only, invalid values are rejected with a summary, valid ones are dirty until saved.
- No direct writes to price meta anywhere: `grep -rnE "update_post_meta|update_metadata" includes/` returns nothing related to prices.
- `phpcs` passes. No PHP notices with `WP_DEBUG` on.

## Out of scope

Audit logging, bulk actions, undo, History tab. Stop after this phase and report. This is the first client review point, so also write a short "How to test this build" list (6 to 10 steps) at the end of your report.
