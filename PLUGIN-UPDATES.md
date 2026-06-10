# Anchor Tools Plugin Update Architecture

This plugin uses the YahnisElsts Plugin Update Checker (PUC) library to pull
updates from a GitHub repository and surface them in the WordPress updates UI.
The configuration lives in `anchor-tools.php`.

## Overview
- Library: `yahnis-elsts/plugin-update-checker` (autoloaded from `vendor/`).
- Source: GitHub repo `https://github.com/joelhmartin/Anchor-Tools/`.
- Branch: `main`.
- Update delivery:
  - PUC accepts `anchor-tools.zip` for normal lowercase installs.
  - If the installed plugin directory is `Anchor-Tools`, PUC accepts
    `anchor-tools-Anchor-Tools.zip`, whose ZIP root is `Anchor-Tools/`.
  - The release ZIP is required. The updater intentionally does not fall back
    to GitHub's auto-generated source archives.

## Requirements
- The Composer vendor folder must be present (`vendor/autoload.php`).
- The update checker is initialized in `anchor-tools.php` on load.

## Authentication (optional but recommended)
For private repos or higher API limits, supply a GitHub token:

- `.env` file in the plugin root:
  ```
  GITHUB_ACCESS_TOKEN=your_token_here
  ```
- Or environment variable `GITHUB_ACCESS_TOKEN`.
- Or a `GITHUB_ACCESS_TOKEN` PHP constant.

The plugin loads `.env` via Dotenv if the file exists.

## How It Is Wired
Configuration in `anchor-tools.php`:
- `PucFactory::buildUpdateChecker(...)` points at the GitHub repo URL.
- `setBranch('main')` pins updates to the main branch.
- `setAuthentication($token)` is used when a token is provided.
- `enableReleaseAssets($expected_zip_name, REQUIRE_RELEASE_ASSETS)` makes the
  matching release asset mandatory and rejects misnamed assets.
- `puc_vcs_update_detection_strategies-anchor-tools` is filtered to
  `latest_release` only, so PUC cannot fall through to generated tag or branch
  ZIPs.

## Release Workflow
1) Bump the plugin header `Version:` in `anchor-tools.php`.
2) Build a release ZIP that contains the plugin folder and all required files
   (including `vendor/` if you are not installing Composer dependencies on the
   target site).
   The GitHub Actions release workflow stages both `Anchor-Tools/` and
   `anchor-tools/`, then uploads `anchor-tools-Anchor-Tools.zip` and
   `anchor-tools.zip`.
   This lets the updater select a package whose root folder exactly matches the
   installed plugin directory without using asset filenames that differ only by
   case.
   `anchor-tools-Anchor-Tools.zip` is uploaded first so older installed updater
   code, which accepts the first release asset, can update dev sites installed
   from the GitHub clone folder name.
3) Create a GitHub release for the new tag and upload both ZIP files as release
   assets.
4) WordPress will detect the update and offer it in the Updates screen.

Do not rely on GitHub's generated "Source code" ZIPs for plugin updates. Those
archives unpack as repository-derived folders such as `Anchor-Tools-<ref>`,
which can force PUC to rename the extracted package. On Kinsta Local/macOS
case-insensitive filesystems, case-only rename attempts like `anchor-tools` to
`Anchor-Tools` can fail with:

```
Update failed: Unable to rename the update to match the existing directory.
```

The dual release assets avoid that failure for local development sites installed
as either `anchor-tools` or `Anchor-Tools`.

## Forcing an Update Check
- In WP Admin, go to Dashboard > Updates and click "Check Again".
- Or clear the update checker cache in the WordPress options table
  (PUC stores state in `puc_*` options).

## Debugging
The plugin does not attach global upgrader logging filters. If updates fail,
check the WordPress update error shown in the admin UI and the PHP error log for
messages from WordPress or the Plugin Update Checker library.
