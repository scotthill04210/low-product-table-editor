# Cursor Prompt: Phase 4 of 7, Audit Log

Prerequisite: Phase 3 passes its acceptance criteria and the client has reviewed it. Paths are relative to the plugin root.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer building **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories, variable products with a "Sold By" attribute, prices with up to 4 decimals.
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting. Works when activated on one site of a multisite: use `$wpdb->prefix`, no network-level options, no network activation hooks.
- Naming: text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WPCS, translatable strings, escape output, prepared SQL only, no em dashes, no build step, plain JS.
- Security: every route checks `current_user_can( 'manage_woocommerce' )`, nonce via `X-WP-Nonce`, `WP_Error` with status codes.

## Goal of this phase

Every write through the plugin is logged. Add the log table, the repository, logging in the save path, the History tab, and the retention cron.

## Tasks

### Database: `includes/class-installer.php`
- `maybe_install()` creates `{$wpdb->prefix}low_pte_log` with `dbDelta()` and stores option `low_pte_db_version` (autoload `no`). Run it on activation AND on `plugins_loaded` when the stored version differs from `LOW_PTE_DB_VERSION` (new constant, `1`), so a site that gets the plugin by any route still gets the table. Per site only.
- Follow dbDelta formatting rules exactly (two spaces after `PRIMARY KEY`, one column per line, `KEY` not `INDEX`, `$wpdb->get_charset_collate()`):

```sql
CREATE TABLE {$wpdb->prefix}low_pte_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  field VARCHAR(40) NOT NULL,
  old_value LONGTEXT NULL,
  new_value LONGTEXT NULL,
  source VARCHAR(10) NOT NULL,
  created_at DATETIME NOT NULL,
  undone_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY batch_id (batch_id),
  KEY product_id (product_id),
  KEY created_at (created_at)
) $charset_collate;
```

- `source` values: `inline`, `bulk`, `undo`. Dates stored in GMT with `current_time( 'mysql', true )`.

### `includes/class-log-repository.php`
- `insert_entries( string $batch_id, int $user_id, string $source, array $entries ): bool` where each entry is `{ product_id, parent_id, field, old, new }`. One multi-row prepared insert per call (chunk at 200 rows).
- `new_batch_id(): string` using `wp_generate_uuid4()`.
- `get_batches( array $filters ): array` with `user_id`, `product_id`, `date_from`, `date_to`, `page`, `per_page` (default 20). Group by `batch_id`, returning: `batch_id`, `user_id`, `user_name` (resolved in one batched `get_users( include => ... )`), `source`, `created_at`, `item_count` (distinct `product_id`), `change_count`, `summary` (per-field counts, for example "regular_price x 12, sale_price x 3"), `undone_count`, `total_count`. Also return `total` for paging.
- `get_batch_entries( string $batch_id ): array` returning entries with product titles resolved in one batched query (no N+1) and variation names including the parent title.
- `purge_older_than( int $days ): int` deleting in loops of 1000 rows (`DELETE ... WHERE created_at < %s LIMIT 1000`) until none remain.
- All SQL uses `$wpdb->prepare()`. Table name from `$wpdb->prefix`, never user input.

### Logging in the save path (`class-product-service.php`)
- `update_items()` creates ONE batch id per PATCH request, `source = inline`.
- For each saved item, record only fields whose value actually changed. Capture the old value from the product BEFORE calling setters, and the new value from the saved product AFTER `save()` (so the log holds what WooCommerce really stored). Normalize all values to strings; booleans as `yes` or `no`, variation inherited stock as `parent`, null as NULL.
- Log only after a successful save, never for failed or conflicted items.
- Add `batch_id` to the PATCH response (top level, null when nothing was written).
- If the log insert fails, do not fail the save. Return `log_failed: true` at the top level, `error_log` the error, and have the client show a visible warning toast: "Saved, but the change could not be logged, so it cannot be undone."
- Expose a reusable method `log_changes( string $batch_id, string $source, array $entries )` so phases 5 and 6 can share it. Field names used in the log: `regular_price`, `sale_price`, `sku`, `weight`, `manage_stock`, `stock_quantity`, `stock_status`, `name`, `status`, `catalog_visibility`, `description`, `short_description`.

### `GET /log` in `class-rest-controller.php`
- Params: `user` (int), `product` (int id, or search text resolved with the same logic as the product search), `date_from`, `date_to` (`Y-m-d`, validated), `page`, `per_page` (10, 20, 50), and `batch_id` (when present, returns that batch's entries instead of the batch list).
- No-cache headers already apply to the namespace. Keep them.

### History tab (client)
- Enable the History tab from phase 1. Switching tabs keeps the Table tab state and unsaved edits intact (hide, do not destroy). Tab state lives in the URL as `tab=history`.
- Filters: user select (users who have log entries), product search, date from and date to, Clear filters. Paged list of batches: When (local time), Who, Source (Inline or Bulk or Undo), Items, Changes, Summary.
- Row expand shows entries: Product (with parent), Field, Old, New, and a marker for entries already undone. Description fields show a truncated value with a "View" modal for the full text.
- Leave an empty, clearly commented slot for the **Undo batch** button (phase 6). Do not render it yet.
- Friendly empty and error states, same patterns as the Table tab.

### Retention and settings
- Option `low_pte_retention_days` (default 180, allowed 1 to 3650). Option `low_pte_delete_data_on_uninstall` (default `no`).
- A small "Log settings" panel at the bottom of the History tab, saved through `admin-post.php` (action `low_pte_save_settings`) with a nonce, `manage_woocommerce` check, sanitization, and a redirect back with a success notice. Fields: retention days, and a checkbox "Delete the log table when the plugin is deleted".
- Cron: hook `low_pte_daily_purge` scheduled with `wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ... )` on activation and self-healed on `admin_init` if missing. Callback calls `purge_older_than()`. Unschedule on deactivation.
- `uninstall.php`: always remove cron, the version option, and `low_pte_retention_days`. Drop the log table (and `low_pte_db_version`) ONLY when `low_pte_delete_data_on_uninstall` is `yes`. Keep per-site looping for multisite.

## Acceptance criteria

- Activation creates the table with the exact columns and indexes. Reactivation does not error or duplicate. On a multisite with the plugin activated on one site only, the table exists only for that site's prefix.
- Editing 3 fields on 2 items in one Save creates one batch with 6 log rows, correct old and new values, correct `parent_id` for variations, `source = inline`.
- A failed or conflicted item writes no log rows. Unchanged fields write no log rows.
- The History tab lists the batch with correct who, when, item count, and summary. Filters by user, product, and date range work and combine.
- Query count for loading the History list does not grow with the number of batches shown (check with debug headers).
- Cron event exists in `wp cron event list`. `wp cron event run low_pte_daily_purge` deletes rows older than the retention setting and leaves newer ones.
- Uninstall keeps the table by default and drops it when the option is checked.
- `phpcs` passes, no notices with `WP_DEBUG` on.

## Out of scope

Bulk actions, undo. Stop after this phase and report.
