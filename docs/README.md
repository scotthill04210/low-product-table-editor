# LOW Product Table Editor: Cursor Prompts

Current release in the working tree: **1.0.0**. Phases 1 through 8 are built. The published 0.1.1 zip has the scaffold and the GitHub updater. See `docs/TESTING.md` for the QA script and `docs/CHANGELOG.md` for the release notes.

Each file in this folder is one self-contained Cursor prompt. Paste the whole file into Cursor, one phase at a time. Do not start a phase until the previous one passes its acceptance checks.

| File | Phase | Deliverable |
|------|-------|-------------|
| phase-1-scaffold.md | 1 | Bootstrap, WooCommerce check, HPOS, admin page, asset gating |
| phase-2-read-path.md | 2 | GET /products, filters, paging, read-only Tabulator grid, URL state |
| phase-3-edit-path.md | 3 | Inline edit, dirty tracking, PATCH /items, conflicts, description modal, paste |
| phase-4-audit-log.md | 4 | Log table, logging on every write, History tab, retention cron |
| phase-5-bulk-actions.md | 5 | Selection, config, preview, confirm, chunked apply |
| phase-6-undo.md | 6 | Batch undo, conflict-safe restore, post-save Undo toast |
| phase-7-polish-qa.md | 7 | Keyboard and a11y, empty and error states, load test, uninstall |

| phase-8-github-updater.md | Any time after 1 | Updates from GitHub Releases (scotthill04210/low-product-table-editor), "Check for updates" link on the Plugins screen row, release workflow |

Run the updater prompt right after Phase 1 so every later build can be installed through WordPress. It adds `includes/class-updater.php`, `.github/workflows/release.yml`, `.distignore`, and `docs/RELEASING.md` to the file structure.

Phases 1 to 3 are the first client deliverable. Client feedback after phase 3 decides what comes next.

## Conventions (repeated inside every prompt)

- Plugin name "LOW Product Table Editor", slug and text domain `low-product-table-editor`
- PHP namespace `LOW\ProductTableEditor`, function and option prefix `low_pte_`, constants `LOW_PTE_`, CSS prefix `low-pte-`
- `declare( strict_types=1 )`, WordPress Coding Standards, all user-facing strings translatable
- No em dashes in code comments, UI strings, or docs

## Later, not now

CSV or Excel import and export (match on SKU, with preview), paired pricing (roll or case price recalculates each, SF, FT price from a stored ratio, with preview), saved column views, scheduled sale dates, per-role field restrictions, more attribute filters (for example Sold By).
