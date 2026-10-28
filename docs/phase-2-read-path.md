# Cursor Prompt: Phase 2 of 7, Read Path

Prerequisite: Phase 1 passes its acceptance criteria. Paths are relative to the plugin root.

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer building **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories. Variable products where each variation has a "Sold By" attribute (Roll, Case, Each, Square Foot, Foot). Prices can have 4 decimals (example: 0.3299 per foot). Test data: 7 variable products, 74 variations, 1 simple product.
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare in front. Works on a single site of a multisite. No network-level options.
- Naming: slug and text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, constants `LOW_PTE_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );`, WordPress Coding Standards, translatable strings, escape output.
- WooCommerce rules: CRUD classes for every read. Show raw stored prices (never formatted with `wc_price`).
- Security: every REST route needs a `permission_callback` that checks `current_user_can( 'manage_woocommerce' )`. Cookie auth with the `X-WP-Nonce` header (`wp_rest`). Return `WP_Error` with status codes, never stack traces.
- No em dashes anywhere. No build step, plain JS, Tabulator bundled locally (already in `assets/vendor/tabulator/`).

## Goal of this phase

`GET /wp-json/low-pte/v1/products` returns paged parent products with their variations nested, using batched queries. Tabulator renders the result read-only with the full filter bar, per-page selector, paging, and URL state.

## Server tasks

### `includes/class-rest-controller.php`
- On `rest_api_init`, register `GET /products` in namespace `low-pte/v1`.
- Args with `validate_callback` and `sanitize_callback`:
  - `page` int, min 1, default 1
  - `per_page` enum `10|25|50|100`, default 25
  - `search` string (`sanitize_text_field`), max 100 chars
  - `category` string, a product_cat slug (`sanitize_title`)
  - `type` enum `simple|variable`
  - `stock_status` enum `instock|outofstock|onbackorder`
  - `status` enum `publish|draft|private|pending`
- `permission_callback`: `current_user_can( 'manage_woocommerce' )`, else `WP_Error( 'low_pte_forbidden', ..., [ 'status' => rest_authorization_required_code() ] )`.
- Send no-cache headers on every response from this namespace: add a `rest_post_dispatch` filter that, for routes beginning with `/low-pte/v1`, sets `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private`. Cloudflare must never cache these.
- When `WP_DEBUG` is true, add headers `X-LOW-PTE-Queries` (`$wpdb->num_queries` delta for the request) and `Server-Timing` (total ms), so N+1 problems are visible.

### `includes/class-product-service.php`, method `get_products( array $args ): array`
1. **Search** (only if `search` is non-empty):
   - `$ids = \WC_Data_Store::load( 'product' )->search_products( $term, '', true, true );` (includes variations and all statuses).
   - Also run one prepared `$wpdb` query on `{$wpdb->postmeta}` joined to `{$wpdb->posts}` for `product_variation` rows where `meta_key LIKE 'attribute\_%'` and `meta_value LIKE %term%`, so searching "Roll" matches variations by attribute value. Escape with `$wpdb->esc_like()` and `prepare()`.
   - Map every hit to its parent ID with ONE query (`SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN (...)` using a placeholders array). Variations map to `post_parent`, products map to themselves. De-duplicate.
   - If the search finds nothing, return an empty result immediately. Otherwise pass the parent IDs as `include` to the next step.
2. **Parents**: `wc_get_products()` with `return => 'ids'`, `paginate => true`, `limit => per_page`, `page`, `orderby => 'title'`, `order => 'ASC'`, `type => ['simple','variable']` (always restrict to these two), `status` (default `['publish','draft','private','pending']`), plus `category` (array of slug), `stock_status`, `include`. Read `total` and `max_num_pages` from the result. Paging counts parents only.
3. **Variations**: ONE `WP_Query` with `post_type => 'product_variation'`, `post_parent__in => $parent_ids`, `post_status => ['publish','private']`, `posts_per_page => -1`, `fields => 'ids'`, `orderby => ['menu_order' => 'ASC', 'ID' => 'ASC']`, `no_found_rows => true`.
4. **Prime caches** before building objects: `update_meta_cache( 'post', array_merge( $parent_ids, $variation_ids ) )` and `_prime_post_caches( ..., false, false )`. Then `wc_get_product()` for each id. No per-product queries.
5. **Row shape** (JSON), parents with a `children` array of variation rows:

```json
{
  "id": 123, "parent_id": 0, "type": "variable",
  "name": "Ceramic Fiber Blanket", "sold_by": "",
  "regular_price": "", "sale_price": "",
  "manage_stock": false, "stock_quantity": null, "stock_status": "instock",
  "sku": "CFB", "weight": "",
  "description_preview": "First 20 words...",
  "status": "publish", "catalog_visibility": "visible",
  "date_modified": 1767000000, "variation_count": 12,
  "edit_url": "https://.../post.php?post=123&action=edit",
  "children": [ { "id": 124, "parent_id": 123, "type": "variation", "...": "..." } ]
}
```

   - Prices: use `get_regular_price( 'edit' )` and `get_sale_price( 'edit' )`, returned as the raw stored strings.
   - `manage_stock`: for variations return `true`, `false`, or the string `"parent"` (WooCommerce stores `parent` when stock is inherited). Do not collapse `parent` to false.
   - `sold_by` (variations): from `get_variation_attributes()`, find the attribute whose label (via `wc_attribute_label`) equals "Sold By" (case-insensitive, filterable with `low_pte_sold_by_label`). Resolve taxonomy attribute slugs to term names with `get_term_by`, batching term lookups with a static cache. Empty value displays as "Any". If no "Sold By" attribute exists, fall back to all attribute values joined with " / ".
   - `description_preview`: `wp_trim_words( wp_strip_all_tags( ... ), 20 )`. Parents use the long description; variations use their description. Never send the full text in this route.
   - `date_modified`: Unix timestamp from `get_date_modified()` (needed for conflict detection in phase 3).
   - Simple products are parent-level rows with `type: "simple"`, their own price and stock fields, and no children.
   - Variable parent rows leave price cells empty (the parent has a price range, not an editable price).
6. **Response**: `{ items: [...], total, total_pages, page, per_page }`.

### `includes/class-admin-page.php`
- Add `config.categories` to `LOW_PTE_CONFIG`: flattened hierarchical `product_cat` list (`[ { slug, name, depth } ]`) via `get_terms`, hide_empty false. This avoids a separate route.

## Client tasks (`assets/js/app.js`, `assets/css/admin.css`)

- **Top bar**: search input (debounce 300 ms), category select, type select, stock status select, post status select, "Clear filters" button with an active-filter count badge (disabled when 0), per-page select (10, 25, 50, 100, default 25), "Expand all" and "Collapse all" buttons. Every control has a visible label or `aria-label`.
- **URL state**: use `URLSearchParams` with params `search`, `category`, `type`, `stock_status`, `status`, `page`, `per_page`. Preserve `post_type` and `page` (the admin page slug) params. Use `history.replaceState` for typing and filter changes, `pushState` for page changes, handle `popstate`. Loading a bookmarked URL restores all controls and fetches that state.
- **Fetching**: `fetch` with `X-WP-Nonce` and `credentials: 'same-origin'`. Use `AbortController` so a slower earlier request never overwrites a newer one. Any filter change resets `page` to 1.
- **Grid**: Tabulator with `dataTree: true`, `dataTreeChildField: 'children'`, `dataTreeStartExpanded: true`, no client-side sorting, no Tabulator pagination (we page on the server), virtual DOM on, `layout: 'fitColumns'` with sensible min widths, sticky header (native) and first column frozen.
  - Column order: **Product** (parents show the name in bold plus a "N variations" badge, variations are indented; frozen), **Sold By** (read-only), **Regular price**, **Sale price**, **Stock**, **SKU**, **Weight**, **Description** (preview, truncated with ellipsis), **Status**.
  - Parent rows get a distinct group-header look (`low-pte-parent-row`). Variation rows get `low-pte-variation-row`.
  - Stock cell text: "In stock" / "Out of stock" / "On backorder", or the quantity when stock is managed, or "Inherited (parent)" when `manage_stock === 'parent'`.
  - Prices render the raw string. No currency symbols, no rounding.
  - Everything is read-only in this phase.
- **Expand all / Collapse all**: loop top-level rows and call `treeExpand()` or `treeCollapse()`.
- **Paging controls** below the grid: Prev, Next, numbered pages (with ellipsis for long ranges), and "Showing X to Y of N products".
- **States**: loading skeleton rows (CSS shimmer, respects `prefers-reduced-motion`), empty state ("No products match these filters" with a Clear filters button), error state with a Retry button and a friendly message. A 403 or `rest_cookie_invalid_nonce` shows "Your session expired. Reload the page."
- **Tradeoff note for the README comment block**: `dataTree` was chosen over `groupBy` because parent rows must carry their own editable cells for simple products in phase 3.

## Acceptance criteria

- With the test catalog, the first load shows 8 parents, 74 variations nested, expanded by default.
- Debug headers show a flat query count that does not grow with the number of variations. Compare per_page 10 vs 100 and confirm the count is roughly constant.
- 25 parents with about 100 variations returns in under 1.5 seconds on the target host (record the number).
- Search "Roll" returns every parent that has a Roll variation. Search a SKU returns its parent. Search with no hits shows the empty state.
- Category, type, stock status, and post status filters each work alone and combined. Paging counts parents.
- Reloading, bookmarking, and Back/Forward all restore filter and page state. Clear filters resets the URL and the controls.
- Prices with 4 decimals (for example 0.3299) display exactly as stored.
- Responses carry `Cache-Control: no-store`. A request without a nonce returns 401 or 403. A Customer-role user gets 403.
- No PHP notices with `WP_DEBUG` on. `phpcs` passes.

## Out of scope

Editing, saving, descriptions modal, logging, bulk actions. Stop after this phase and report timings, query counts, and any deviations.
