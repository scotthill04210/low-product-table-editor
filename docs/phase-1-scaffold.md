# Cursor Prompt: Phase 1 of 7, Scaffold

Paste this entire file into Cursor. Paths are relative to the plugin root (the `low-product-table-editor/` folder).

## Context (same in every phase)

You are a senior WordPress and WooCommerce plugin developer building **LOW Product Table Editor**, an admin-only plugin that lets a shop manager edit every WooCommerce product and variation in one spreadsheet-style table (Tabulator grid), with REST saves, bulk price changes, an audit log, and undo.

- Catalog: insulation and refractories. Variable products where each variation has a "Sold By" attribute (Roll, Case, Each, Square Foot, Foot). Prices can have 4 decimals (example: 0.3299 per foot).
- Stack: WordPress 6.x, latest stable WooCommerce, PHP 8.1+, WPMU DEV hosting, Cloudflare in front. Must work when activated on a single site of a multisite. No network-level options, no network activation hooks, use `$wpdb->prefix`.
- Naming: name "LOW Product Table Editor", slug and text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, function and option prefix `low_pte_`, constants `LOW_PTE_`, CSS prefix `low-pte-`, REST namespace `low-pte/v1`.
- Code rules: `declare( strict_types=1 );` in every PHP file, WordPress Coding Standards, every user-facing string translatable with the text domain, escape all output, no frontend output at all.
- WooCommerce rules: use CRUD classes for every read and write. Never write `_price`, `_regular_price`, or `_sale_price` meta directly.
- Do not use em dashes anywhere (code comments, UI strings, docs).
- No build step. Plain JavaScript (ES2020), no npm, no bundler, no jQuery dependency in our code.
- Target file structure:

```
low-product-table-editor/
  low-product-table-editor.php
  uninstall.php
  includes/
    class-plugin.php
    class-installer.php
    class-admin-page.php
    class-rest-controller.php
    class-product-service.php
    class-bulk-service.php
    class-log-repository.php
  assets/
    js/app.js
    css/admin.css
    vendor/tabulator/
  languages/
  docs/
```

## Goal of this phase

A bootable plugin with a dependency check, HPOS declaration, an admin page under Products, a capability gate, an empty app shell, and assets that load only on that screen. No REST routes, no data, no grid yet.

## Tasks

1. **`low-product-table-editor.php`** (bootstrap)
   - Plugin header: Plugin Name `LOW Product Table Editor`, Description, Version `0.1.0`, Requires at least `6.4`, Requires PHP `8.1`, `Requires Plugins: woocommerce`, Author `Live Oaks Web`, `Update URI: https://github.com/scotthill04210/low-product-table-editor` (the GitHub updater arrives in the separate updater prompt), Text Domain `low-product-table-editor`, Domain Path `/languages`. Do NOT add a `Network:` header.
   - Block direct access (`defined( 'ABSPATH' ) || exit;`).
   - Define `LOW_PTE_VERSION`, `LOW_PTE_FILE`, `LOW_PTE_DIR`, `LOW_PTE_URL`, `LOW_PTE_MIN_PHP` (`8.1`).
   - Register a small PSR-4 style autoloader for the `LOW\ProductTableEditor` namespace that maps `Class_Name` to `includes/class-class-name.php` (lowercase, underscores to hyphens). Example: `LOW\ProductTableEditor\Admin_Page` loads `includes/class-admin-page.php`.
   - If PHP is below 8.1, show an admin notice and stop loading (do not fatal).
   - On `before_woocommerce_init`, declare HPOS compatibility: `\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LOW_PTE_FILE, true )`, guarded by `class_exists`.
   - On `plugins_loaded` priority 20, call `Plugin::instance()->boot()`.
   - `register_activation_hook` and `register_deactivation_hook` call static methods on `Plugin`. These must work per site and must not assume network activation.
2. **`includes/class-plugin.php`**
   - Singleton. `boot()` checks `class_exists( 'WooCommerce' )`. If missing, hook `admin_notices` with an escaped, translatable notice ("LOW Product Table Editor requires WooCommerce to be installed and active.") and return without registering anything else.
   - If WooCommerce is present, load `Admin_Page` and call its `register()`.
   - `activate()` stores option `low_pte_version` (autoload `no`). Nothing else yet.
   - `load_plugin_textdomain` on `init`.
3. **`includes/class-installer.php`**: empty class with a `maybe_install()` stub and a docblock saying the log table arrives in phase 4. Do not call it yet.
4. **`includes/class-admin-page.php`**
   - `register()` hooks `admin_menu` and `admin_enqueue_scripts`.
   - `add_submenu_page( 'edit.php?post_type=product', __( 'Table Editor', ... ), __( 'Table Editor', ... ), 'manage_woocommerce', 'low-pte', [ $this, 'render' ] )`. Store the returned hook suffix.
   - `render()` re-checks `current_user_can( 'manage_woocommerce' )`, then outputs an app shell:
     - `<div class="wrap low-pte-wrap">` with an `<h1>` "Table Editor".
     - A tab bar with two tabs: "Table" (active) and "History" (rendered but disabled, `aria-disabled="true"`, tooltip "Available in a later release").
     - A mount point `<div id="low-pte-app" class="low-pte-app" aria-live="polite">` containing a "Loading editor..." placeholder.
     - A `<noscript>` message.
     - All output escaped with `esc_html__`, `esc_attr`, etc.
   - `enqueue( string $hook )`: return immediately unless `$hook` equals the stored hook suffix. Then enqueue:
     - `low-pte-tabulator` (script and style) from `assets/vendor/tabulator/`, version pinned from the bundled file.
     - `low-pte-admin` style from `assets/css/admin.css`.
     - `low-pte-app` script from `assets/js/app.js`, depends on `low-pte-tabulator`, in footer.
     - Inline `window.LOW_PTE_CONFIG` (before the app script) via `wp_add_inline_script` with `wp_json_encode`: `restUrl` (`rest_url( 'low-pte/v1/' )`), `nonce` (`wp_create_nonce( 'wp_rest' )`), `perPageOptions` `[10,25,50,100]`, `defaultPerPage` `25`, `version`, and an `i18n` object with the strings used by the shell.
5. **Tabulator bundle**: download the latest stable Tabulator 6.x release (MIT) and place `tabulator.min.js` and `tabulator.min.css` in `assets/vendor/tabulator/`, plus its `LICENSE` and a `VERSION.txt` containing the exact pinned version. No CDN. If you cannot download files in this environment, create the folder with a `README.txt` saying which two files to drop in and stop that sub-task, then tell me.
6. **`assets/js/app.js`**: an IIFE that finds `#low-pte-app`, reads `window.LOW_PTE_CONFIG`, replaces the placeholder with the empty shell ("Table editor loads here in the next phase"), and exposes nothing on `window` except `LOW_PTE_CONFIG`.
7. **`assets/css/admin.css`**: minimal styles, every class prefixed `low-pte-`. Use WordPress admin colors and system fonts. Include a visible `:focus-visible` outline for `.low-pte-wrap` controls.
8. **`uninstall.php`**: guard with `WP_UNINSTALL_PLUGIN`, delete option `low_pte_version`. Leave a clearly marked TODO for the log table (phase 4). Handle multisite by looping `get_sites()` with `switch_to_blog()` and cleaning each site that has the option.
9. **`languages/`**: empty folder with a `.gitkeep`.

## Acceptance criteria

- Plugin activates on a single site with no PHP notices or warnings (`WP_DEBUG` on).
- Plugin activates on one site of a multisite without network activation and shows the menu only on that site.
- With WooCommerce deactivated, the plugin shows the admin notice and registers no menu.
- WooCommerce > Status shows no HPOS incompatibility warning for this plugin.
- Products > Table Editor appears for Administrator and Shop Manager, and a Customer or Editor visiting the URL directly gets the standard "not allowed" screen.
- Tabulator and `app.js` are loaded only on the Table Editor screen. View source on Dashboard, Products, and Orders pages: none of our handles appear.
- `grep -rn "—" .` in the plugin returns nothing. `phpcs --standard=WordPress` passes on the PHP files.

## Out of scope

REST routes, data loading, the grid, the database table. Stop after this phase and report what you built and anything you could not do.
