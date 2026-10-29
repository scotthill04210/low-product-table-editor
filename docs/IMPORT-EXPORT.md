# Import and Export

Export the catalog to a CSV, edit it in Excel, and import it back. Rows match on **SKU**. Import never creates, deletes, or trashes products, and it never changes a SKU.

## Columns

| Column | Notes |
|---|---|
| `sku` | Match key. Not changed by import. |
| `type` | `simple`, `variable`, or `variation`. Read-only. |
| `parent_sku` | Read-only. |
| `name` | Editable for simple and variable products. Ignored on variations. |
| `variation_label` | Read-only (Size). |
| `sold_by` | Read-only. |
| `regular_price` | Raw stored string. Empty on variable parents. |
| `sale_price` | Raw stored string. |
| `manage_stock` | `yes`, `no`, or `parent` (variations only). |
| `stock_quantity` | Integer or empty. |
| `stock_status` | `instock`, `outofstock`, `onbackorder`. |
| `weight` | Raw string. |
| `status` | `publish`, `draft`, `private` (products only). |
| `catalog_visibility` | `visible`, `catalog`, `search`, `hidden` (products only). |
| `description` | Only when "Include descriptions" is on. |
| `short_description` | Only when descriptions are on, products only. |
| `modified` | Unix timestamp at export. Used for "changed since export". |
| `id` | Read-only reference. Never used for matching. |

Unknown columns are ignored and listed in the preview. A file without `sku` is rejected.

## Blank cells

- A column that is missing leaves that field alone.
- A blank `sale_price`, `weight`, `stock_quantity`, `description`, or `short_description` clears the value only when "Allow clearing values" is on. Otherwise blanks are ignored.
- A blank `regular_price` or `name` is always an error for that row.

## Changed since export

When the file includes `modified`, the preview compares it to the current product. With "Skip rows changed since export" on (the default), those rows are skipped. With it off, they still apply and are flagged in the report.

## Worked example

1. Open Products > Table Editor > Import / Export.
2. Leave scope on Everything, leave descriptions off, and download the export.
3. In Excel, open the CSV (use UTF-8 if prompted). Change three sale prices and two regular prices. Save as CSV UTF-8.
4. Upload the file, leave the default options, and preview. The summary should show 5 cell changes.
5. Confirm and apply. History shows one Import batch. Undo this batch restores the five values.

## Excel pitfalls

- Prefer **CSV UTF-8 (Comma delimited)**. Semicolon and tab files are also accepted.
- Long numeric SKUs can become scientific notation. Format the SKU column as Text before saving, or check the preview warning.
- Use a **dot** for decimals. Values such as `1,234.50` or `0,3299` are rejected.
- Prices such as `0.3299` stay as text in the export. If Excel rewrites `63.00` as `63`, the importer still treats them as the same number when nothing else changed.
- Cells that start with `=`, `+`, `-`, or `@` are exported with a leading quote so Excel does not treat them as formulas. Import removes that quote when it is only a guard.
