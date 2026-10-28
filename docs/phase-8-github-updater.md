# Cursor Prompt: GitHub Updater (run any time after Phase 1)

Prerequisite: Phase 1 is done. This prompt is independent of phases 2 to 7 and can be run right after Phase 1 so every later build can be updated from WordPress. Paths are relative to the plugin root.

## Context

You are a senior WordPress plugin developer working on **LOW Product Table Editor** (slug and text domain `low-product-table-editor`, namespace `LOW\ProductTableEditor`, prefix `low_pte_`, constants `LOW_PTE_`, `declare( strict_types=1 );`, WordPress Coding Standards, translatable strings, escaped output, no em dashes, no build step). It is admin-only and must work when activated on a single site of a multisite (no network activation hooks, no network options).

The code lives at **https://github.com/scotthill04210/low-product-table-editor**. WordPress must update the plugin from that repository's GitHub Releases, and the Plugins screen row must have a **Check for updates** link.

## Goal

Native WordPress update flow (Dashboard > Updates, the Plugins screen "update available" notice, one-click update) powered by GitHub Releases, plus a manual "Check for updates" link in the plugin's row on the Plugins screen.

## Tasks

### 1. `low-product-table-editor.php`
- Add the header `Update URI: https://github.com/scotthill04210/low-product-table-editor` (this stops WordPress.org from ever matching the slug and routes updates through the `update_plugins_github.com` filter).
- Define `LOW_PTE_GITHUB_REPO` as `scotthill04210/low-product-table-editor`.
- Load and register the updater from `Plugin::boot()`, but register the updater hooks even if WooCommerce is missing, so the plugin can still be updated in that state.

### 2. `includes/class-updater.php` (`LOW\ProductTableEditor\Updater`)

**Release lookup**
- `get_latest_release(): ?array` calls `https://api.github.com/repos/{repo}/releases/latest` with `wp_remote_get`, headers `Accept: application/vnd.github+json`, `User-Agent: LOW-Product-Table-Editor/{version}`, timeout 10.
- Cache the parsed result (tag, version, zip URL, body, published date, html URL) in a transient `low_pte_github_release` for 12 hours. On any failure (HTTP error, bad JSON, rate limit), cache a short-lived failure marker for 1 hour so the Plugins screen never slows down or hammers the API. Never throw, never show raw errors to users.
- Version = tag with a leading `v` stripped. Ignore drafts and prereleases. Use `version_compare()` against `LOW_PTE_VERSION`.
- Download URL: prefer a release asset named exactly `low-product-table-editor.zip`. Fall back to the release `zipball_url` only if no asset exists.
- Optional private repo support: if `LOW_PTE_GITHUB_TOKEN` is defined in `wp-config.php`, send `Authorization: Bearer ...` on API calls and on the package download (use the `http_request_args` filter limited to our package URL, with `Accept: application/octet-stream` for asset URLs). Never store the token in the database or print it anywhere.

**WordPress hooks**
- `update_plugins_github.com` (filter, signature `( $update, $plugin_data, $plugin_file, $locales )`): only act when `$plugin_file` is our plugin basename. Return the update object (`id`, `slug`, `plugin`, `version`, `url`, `package`, `tested` and `requires` read from the release or plugin header when known) when a newer version exists, or `false`.
- `plugins_api` (filter): when `$args->slug` is our slug and `$action` is `plugin_information`, return an object for the "View details" modal with `name`, `slug`, `version`, `author`, `homepage` (repo URL), `requires`, `requires_php`, `download_link`, `last_updated`, and `sections` (`description`, `changelog` from the release body). Render the release body with `wp_kses_post( wpautop( ... ) )` (convert Markdown only with simple, safe handling; no external libraries).
- `upgrader_source_selection` (filter): GitHub archives extract to a folder like `scotthill04210-low-product-table-editor-abc123`. Rename the extracted folder to `low-product-table-editor` so the plugin is replaced in place and stays active. Only act for our plugin (check `$hook_extra['plugin']`). Return `WP_Error` on rename failure.
- `upgrader_process_complete` (action): delete the release transient after our plugin updates.
- `plugin_action_links_{plugin_basename}` (filter): append **Check for updates** to the row's action links (the same group as Deactivate). It is a link to `admin-post.php?action=low_pte_check_update` with a nonce (`wp_nonce_url`), escaped.
- `admin_post_low_pte_check_update` (action): verify nonce and `current_user_can( 'update_plugins' )`, delete the release transient and the failure marker, call `delete_site_transient( 'update_plugins' )` and `wp_update_plugins()`, then redirect back to `plugins.php` with `low_pte_update_checked=update|current|error`. Use `wp_safe_redirect` and `exit`.
- `admin_notices` (action, only on `plugins.php` when the query arg is present): a dismissible notice, escaped and translatable:
  - `update`: "A new version (X.Y.Z) is available. Update from the Plugins screen."
  - `current`: "LOW Product Table Editor is up to date (version X.Y.Z)."
  - `error`: "Could not reach GitHub to check for updates. Try again later."
- Also add a **View release** link in the row meta (`plugin_row_meta`) pointing to the releases page when an update exists. Keep the row clean otherwise.

**Behavior rules**
- Admin only: register nothing on the frontend and do no remote calls on frontend requests.
- No remote call on every admin page load. Calls happen only from WordPress's own update checks (`update_plugins` transient refresh) and the manual link, both served from the 12-hour cache unless the link is used.
- Multisite: works per site. Capability for the link is `update_plugins` (on multisite single-site activation, site admins may lack it, in which case the link is simply not shown).
- `uninstall.php`: also delete `low_pte_github_release` and the failure marker.

### 3. Release workflow: `.github/workflows/release.yml`
- Trigger on push of tags matching `v*.*.*`.
- Steps: check out, verify the tag matches the `Version:` header and `LOW_PTE_VERSION` in `low-product-table-editor.php` (fail the job if not), copy the plugin into a folder named `low-product-table-editor/` honoring `.distignore` (exclude `docs/`, `tools/`, `.github/`, `.git*`, dev files), zip it as `low-product-table-editor.zip` with the folder as the top-level directory, and create a GitHub Release with that zip attached as an asset and auto-generated release notes (`softprops/action-gh-release` or `gh release create`). Use the default `GITHUB_TOKEN` with `contents: write` permission.
- Add `.distignore` if it does not exist yet.

### 4. Docs
- Add `docs/RELEASING.md`: bump version in both places, commit, tag `vX.Y.Z`, push the tag, confirm the Release and zip, then click **Check for updates** on a test site. Include how to set `LOW_PTE_GITHUB_TOKEN` for a private repo.

## Acceptance criteria

- The Plugins screen row shows **Check for updates** next to Deactivate for users who can `update_plugins`. Clicking it with no newer release shows the "up to date" notice. After pushing a higher tag (for example `v0.1.1` over an installed `0.1.0`), clicking it shows the update notice and the standard "update available" row with a working **View details** modal.
- One-click update from the Plugins screen installs the release zip, the folder stays `low-product-table-editor`, and the plugin remains active afterward with no errors.
- With GitHub unreachable (block the host or use an invalid repo), the Plugins screen still loads fast, the manual link shows the error notice, and no PHP warnings appear.
- Network tab on a normal admin page load shows no GitHub requests. `GET` to the GitHub API happens only on WordPress update checks and on the manual link.
- The nonce and capability are enforced on the manual link (test a logged-in user without `update_plugins` and a missing nonce).
- Release workflow fails on a tag and header version mismatch, and succeeds with matching versions, producing a zip whose top-level folder is `low-product-table-editor/` and that excludes `docs/` and `tools/`.
- `phpcs --standard=WordPress` passes and there are no PHP notices with `WP_DEBUG` on.

## Out of scope

Auto-updates toggling UI (WordPress provides this natively once the update filter works), beta channels, and license keys. Stop after this task and report.
