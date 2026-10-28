# Cursor Prompt: Phase 7 of 7, Polish and QA

Prerequisite: Phase 6 passes its acceptance criteria. Paths are relative to the plugin root.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer finishing **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories, variable products with a "Sold By" attribute, prices with up to 4 decimals. Real catalog: 7 variable products, 74 variations, 1 simple product, imported from the client's price sheet.
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare in front, WP-CLI available. Works on one site of a multisite.
- Naming: text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WPCS, translatable strings, escape output, no em dashes, no build step.
- Performance targets: 25 parents with about 100 variations load in under 1.5 seconds on WPMU DEV, and a page size of 100 stays usable.

## Goal of this phase

Make it solid. Keyboard and accessibility, empty and error states, a load test with 1,000+ variations, a run against the real catalog, uninstall behavior, security review, and a release package. Fix defects you find; do not add features.

## Tasks

### 1. Keyboard and accessibility
- Every action works with the keyboard alone: filters, paging, expand and collapse, selection, cell editing, Save and Discard, modals, toasts, History tab.
- Cell navigation: arrows move between cells, Enter edits or commits, Tab and Shift+Tab move across, Esc cancels, Space toggles a row checkbox, Ctrl or Cmd+S saves when dirty (prevent the browser save dialog only while focus is inside the app). Document shortcuts in a small "Keyboard shortcuts" help popover.
- Visible focus indicators on every interactive element, including grid cells and frozen columns. Check contrast against WCAG 2.1 AA (4.5:1 text, 3:1 UI).
- Grid semantics: confirm Tabulator's output exposes `role="grid"`, `row`, and `gridcell`; add `aria-label`, `aria-expanded` on parent rows, `aria-selected` on selected rows, `aria-invalid` and `aria-describedby` on invalid cells, and column header labels where missing.
- Live regions: one polite `role="status"` region for save, undo, and bulk results, and `role="alert"` for errors. Toasts never steal focus.
- Modals: `role="dialog"`, `aria-modal="true"`, labelled and described, focus trap, focus returns to the trigger on close. The Escape key closes only the top modal.
- State is never conveyed by color alone (dirty, error, saved, conflict, skipped all have an icon or text). Respect `prefers-reduced-motion` and `prefers-color-scheme` contrast. Support browser zoom to 200 percent without losing function.
- Run axe DevTools on the Table tab, History tab, and each modal. Fix all serious and critical issues, and list any remaining minor ones in the report.

### 2. Empty and error states
- Friendly states for: no products at all, no filter matches, no log entries, REST 401 or 403 (session expired, offer reload), `rest_cookie_invalid_nonce`, 500 (generic message plus Retry, never raw server text), network offline (detect `navigator.onLine` and fetch failures), WooCommerce deactivated mid-session (server returns a clear WP_Error, client shows it), and a save that partially fails.
- A stale nonce on Save must not lose the user's edits: keep the dirty store, show "Your session expired. Reload the page in a new tab, then come back and press Save", or refresh the nonce if you can do it safely through a lightweight authenticated endpoint that returns a new `wp_rest` nonce (preferred; add `GET /nonce`, same permission checks, no-store headers).
- Long values: very long names, SKUs, and descriptions truncate with ellipsis and a title tooltip, never break the layout.

### 3. Load test (dev only)
- Add `tools/generate-test-catalog.php`, runnable with `wp eval-file`, that creates N variable products with M variations each (defaults 40 and 30, so 1,200 variations) with a "Sold By" attribute (Roll, Case, Each, Square Foot, Foot), 4 decimal prices on some, some sale prices, a mix of stock modes including `parent`, and unique SKUs. Tag created posts with a meta flag `_low_pte_test` and add a cleanup mode that deletes only flagged items. Refuse to run unless `WP_ENVIRONMENT_TYPE` is `local`, `development`, or `staging`.
- Measure and record in the report: `GET /products` time and query count at per_page 25 and 100, time to first render in the browser, scroll smoothness at 100 parents, `PATCH /items` time for 50 items, a 1,000 item bulk apply total time, and `POST /undo` time for that batch. Compare against the targets above and fix regressions (batching, cache priming, payload size, unnecessary re-renders).
- Confirm memory use stays reasonable (peak memory from `memory_get_peak_usage` in debug headers) at per_page 100.

### 4. Run against the real catalog
- On a staging copy with the real data, run this script and record pass or fail for each step:
  1. Load the table. Counts are 8 parents (7 variable, 1 simple), 74 variations, "Sold By" column populated for every variation.
  2. Edit a per-foot price to `0.3299`, save, reload: value is exactly `0.3299` in the grid and in the native product editor.
  3. Add a sale price lower than regular, save, then try one equal to regular: blocked in the UI and by the server.
  4. Paste a column from Excel into Regular price across two products.
  5. Edit a description in the modal for a parent (long and short) and a variation.
  6. Bulk: increase all by 5 percent, target both, rounding none: preview, confirm, apply, check History shows one batch, then Undo from the toast and from History.
  7. Bulk with nearest cent rounding on a sample, verify by hand.
  8. Confirm the frontend product pages display the expected prices, and note the store's "Number of Decimals" setting vs the 4 decimal data (client decision, report only).
  9. Bookmark a filtered URL, reload, Back and Forward.
  10. Check duplicate SKU, stale conflict from two tabs, and a Shop Manager login (not just Administrator).

### 5. Uninstall and lifecycle
- Deactivate: cron `low_pte_daily_purge` is removed, data is kept.
- Delete with default settings: options removed, log table KEPT.
- Delete with "Delete the log table when the plugin is deleted" checked: log table and `low_pte_db_version` removed.
- Multisite with the plugin on one site only: uninstall cleans only that site, other sites are untouched.
- Re-activation after deletion recreates the table cleanly.

### 6. Security and standards review
- Confirm every route has a `permission_callback`, nonces are required, per-item `edit_post` is checked, batch sizes are capped (50 for PATCH, 25 for bulk apply), and unknown fields are rejected.
- Grep for: `update_post_meta`, `update_metadata`, `$wpdb->query(` without `prepare`, unescaped `echo`, `$_GET`, `$_POST`, `$_REQUEST` (only the settings `admin-post` handler may read `$_POST`, with nonce and capability checks), `var_dump`, `print_r`, `error_log` (only behind `WP_DEBUG`).
- Verify responses from `/wp-json/low-pte/v1/*` carry `Cache-Control: no-store` and that the admin page itself is not cached. Document the Cloudflare rule to add: bypass cache for `/wp-admin/*` and `/wp-json/low-pte/*` (and `?rest_route=` URLs).
- Run `phpcs --standard=WordPress` and fix findings. Run PHP lint on 8.1, 8.2, 8.3.
- Generate the translation template: `wp i18n make-pot . languages/low-product-table-editor.pot --exclude=assets/vendor,tools,docs`.
- Confirm no external requests happen from the admin page (check the Network tab): no CDN, no fonts, no analytics.

### 7. Release package
- Bump version to `1.0.0`. Add `readme.txt` (short description, installation, FAQ, changelog), and a `.distignore` that excludes `docs/`, `tools/`, `.git*`, `node_modules`, and dev files.
- Verify the built zip (`wp dist-archive . ../low-product-table-editor.zip` or equivalent) installs and activates on a clean site and contains `assets/vendor/tabulator/` with its license.
- Add `docs/CHANGELOG.md` and `docs/TESTING.md` with the scripts above so QA can be repeated after future updates.

## Acceptance criteria

- Performance targets met and recorded. A 1,000 item bulk apply completes without timeouts and undoes cleanly.
- Keyboard-only run through the whole real catalog script succeeds. axe reports no serious or critical issues.
- Every state in task 2 has been triggered and looks right.
- Uninstall matrix in task 5 passes in single-site and multisite.
- `phpcs` clean, no PHP notices or JS console errors with `WP_DEBUG` on, security grep list clean.
- A final report lists all measurements, remaining minor issues, and the "Later, not now" items untouched: CSV or Excel import and export (match on SKU, with preview), paired pricing (roll or case price recalculates each, SF, FT from a stored ratio, with preview), saved column views, scheduled sale dates, per-role field restrictions, more attribute filters (for example Sold By).
