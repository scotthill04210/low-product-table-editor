# Releasing LOW Product Table Editor

WordPress updates this plugin from GitHub Releases on `scotthill04210/low-product-table-editor`. The release workflow runs when a tag that looks like `vX.Y.Z` is pushed.

## 1. Bump the version

Edit `low-product-table-editor.php` and set the same version in both places:

- the `Version:` plugin header
- the `LOW_PTE_VERSION` constant

The workflow fails the release if the tag and those two values do not match.

## 2. Commit and tag

```bash
git add low-product-table-editor.php
git commit -m "chore: release X.Y.Z"
git tag vX.Y.Z
git push origin HEAD
git push origin vX.Y.Z
```

Use the real version in place of `X.Y.Z`, for example `0.1.1` and tag `v0.1.1`.

## 3. Confirm the Release

Open the GitHub Release created for that tag. It should include auto-generated notes and an attached `low-product-table-editor.zip`.

The zip has one top-level folder, `low-product-table-editor/`. `docs/` and `tools/` are left out, along with the other paths in `.distignore`.

## 4. Update a test site

On the Plugins screen, click **Check for updates** on the LOW Product Table Editor row (next to Deactivate).

- If the release is newer than the installed copy, the row shows the usual WordPress update notice.
- If it is the same version, the screen shows that the plugin is up to date.
- Use the normal WordPress update action. The plugin folder stays `low-product-table-editor` and the plugin stays active.

## Private repository

A private repo will not serve release files to WordPress until the site sends a token. In `wp-config.php`, above the "That's all, stop editing" line:

```php
define( 'LOW_PTE_GITHUB_TOKEN', 'github_pat_xxx' );
```

Use a fine-grained personal access token with read access to this repository's contents, or a classic token with the `repo` scope. Do not commit the token and do not put it in the database.

The plugin sends it as an `Authorization: Bearer` header on GitHub API requests and on the package download. It is never printed.
