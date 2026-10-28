# LOW Product Table Editor

Admin-only WordPress plugin that edits WooCommerce products and variations in one spreadsheet-style table.

**0.1.1** is the first published build. It adds the Products > Table Editor screen and updates from GitHub Releases. The working tree adds the product grid, inline editing, the audit log, bulk price changes, and conflict-safe undo. The published 0.1.1 zip does not include the grid.

## Product grid

Parent products and their variations render in one Tabulator table. `dataTree` is used instead of `groupBy` because parent rows carry their own cells. Prices are the raw stored strings, so a value such as 0.3299 is shown exactly as WooCommerce saved it. Click a cell to edit. Description opens in a dialog and is kept locally until Save changes. The product page number in the admin URL is `paged`, because `page` is already the WordPress screen slug.

## History

The History tab lists each Save as one batch. Open a batch to see the old and new value of every field that changed. Log settings at the bottom of that tab set how long entries are kept. A daily task deletes rows older than that. The log table stays when the plugin is deleted unless "Delete the log table when the plugin is deleted" is checked.

## Bulk prices

Select simple products or variations, then choose Bulk price action. Preview shows the new price for every item before anything is saved. Apply sends the selected items in groups of 25. Increase and decrease skip an empty price and list it in the report. A result below zero, a regular price of zero, or a sale price that is not below the regular price is reported as an error and is not saved.

## Requirements

- WordPress 6.4 or newer
- PHP 8.1 or newer
- WooCommerce

Activate it on a single site. It does not use network activation.

## Install

Download `low-product-table-editor.zip` from the [latest release](https://github.com/scotthill04210/low-product-table-editor/releases) and upload it with Plugins > Add New > Upload Plugin.

## Updates

On the Plugins screen, use **Check for updates** on the LOW Product Table Editor row. WordPress installs newer releases from this repository. Publishing steps are in [docs/RELEASING.md](docs/RELEASING.md).
