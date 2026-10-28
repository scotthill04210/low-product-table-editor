# LOW Product Table Editor

Admin-only WordPress plugin that edits WooCommerce products and variations in one spreadsheet-style table.

**0.1.1** is the first published build. It adds the Products > Table Editor screen and updates from GitHub Releases. The working tree adds a read-only product grid. Inline editing, the audit log, bulk price changes, and undo are later releases.

## Read-only grid

Parent products and their variations render in one Tabulator table. `dataTree` is used instead of `groupBy` because parent rows must carry their own editable cells for simple products in phase 3. Prices are the raw stored strings, so a value such as 0.3299 is shown exactly as WooCommerce saved it. The product page number in the admin URL is `paged`, because `page` is already the WordPress screen slug.

## Requirements

- WordPress 6.4 or newer
- PHP 8.1 or newer
- WooCommerce

Activate it on a single site. It does not use network activation.

## Install

Download `low-product-table-editor.zip` from the [latest release](https://github.com/scotthill04210/low-product-table-editor/releases) and upload it with Plugins > Add New > Upload Plugin.

## Updates

On the Plugins screen, use **Check for updates** on the LOW Product Table Editor row. WordPress installs newer releases from this repository. Publishing steps are in [docs/RELEASING.md](docs/RELEASING.md).
