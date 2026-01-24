# Anchor Tools Plugin Update Architecture

This plugin uses the YahnisElsts Plugin Update Checker (PUC) library to pull
updates from a GitHub repository and surface them in the WordPress updates UI.
The configuration lives in `anchor-tools.php`.

## Overview
- Library: `yahnis-elsts/plugin-update-checker` (autoloaded from `vendor/`).
- Source: GitHub repo `https://github.com/joelhmartin/Anchor-Tools/`.
- Branch: `main`.
- Update delivery:
  - If a GitHub release asset is available, PUC will use it.
  - Otherwise it falls back to the repo zipball for the configured branch.

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
- `enableReleaseAssets()` switches the VCS API to prefer release assets.

## Release Workflow
1) Bump the plugin header `Version:` in `anchor-tools.php`.
2) Build a release ZIP that contains the plugin folder and all required files
   (including `vendor/` if you are not installing Composer dependencies on the
   target site).
3) Create a GitHub release for the new tag and upload the ZIP as a release
   asset.
4) WordPress will detect the update and offer it in the Updates screen.

If no release asset is present, PUC will use the GitHub zipball for `main`.

## Forcing an Update Check
- In WP Admin, go to Dashboard > Updates and click "Check Again".
- Or clear the update checker cache in the WordPress options table
  (PUC stores state in `puc_*` options).

## Debugging
The plugin logs two upgrader hooks to PHP error logs:
- `upgrader_pre_download`
- `upgrader_source_selection`

Check the PHP error log for `[Anchor Tools]` entries if updates fail.
