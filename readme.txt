=== LOW Product Table Editor ===
Contributors: scotthill
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0

Edit WooCommerce products and variations in one spreadsheet-style table.

== Description ==

Shop managers edit regular prices, sale prices, stock, SKUs, names, visibility, status, and descriptions for every product and variation in one table. Saves are logged and can be undone. A bulk price action previews a change before it writes.

Prices are stored with up to 4 decimal places. The shop display still uses WooCommerce's Number of Decimals setting.

== Installation ==

1. Upload the plugin zip and activate it on one site.
2. Open Products > Table Editor.
3. WooCommerce must be active. The shop manager capability `manage_woocommerce` is required.

== Frequently Asked Questions ==

= Does undo overwrite a newer edit? =

No. Undo restores a value only when it still matches the logged edit. Anything changed since is left alone and listed.

= What should Cloudflare bypass? =

Bypass cache for `/wp-admin/*`, `/wp-json/low-pte/*`, and URLs that use `?rest_route=`.

== Changelog ==

= 1.0.0 =
* Spreadsheet editing, audit log, bulk price changes, and conflict-safe undo.
* Keyboard shortcuts, session recovery that keeps unsaved edits, and a 1.0.0 release package.
