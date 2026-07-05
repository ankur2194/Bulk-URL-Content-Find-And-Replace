# Replacely – Bulk Content Find & Replace by URLs

> A WordPress administration tool for safely performing **bulk find and replace** on post, page, and custom-post-type content — including pages built with Elementor, Beaver Builder, Oxygen, and Bricks — across a curated list of URLs or paths, with a dry-run preview, a polished results dashboard, CSV export, and a persistent activity log.

[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-purple.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/version-1.1.0-orange.svg)](#changelog)

---

## Overview

Most "search & replace" tools scan the entire WordPress database blindly, which is risky on production sites. **Replacely** takes the opposite approach: you provide an explicit list of URLs or paths, the exact text to find, and the exact text to replace it with. Only the content of those specific posts is touched — nothing else.

It is built for site owners, agencies, and developers who need surgical, predictable content edits across many pages at once.

## Features

- **Targeted by URL** — Operate only on the posts/pages you list. No accidental side-effects.
- **Exact, case-sensitive matching** — No regex foot-guns. What you type is what gets replaced.
- **Mixed URL and path support** — Accepts full URLs (`https://example.com/sample-page/`) and relative paths (`/sample-page/`).
- **Any post type** — Detects target posts via `url_to_postid()`, so it works with posts, pages, and any registered CPT (including those from other plugins or themes).
- **Page-builder aware** — Also finds and replaces text inside Elementor, Beaver Builder, Oxygen, and Bricks page content (which lives in post meta, not `post_content`), then refreshes the relevant builder cache so the changes appear on the front end right away.
- **Dry Run mode** — Preview the exact number of replacements per URL *before* writing anything to the database.
- **Results dashboard** — Summary tiles, status colors, dashicons, and a detailed per-URL table, with replacement counts broken down by source (classic content vs. each page builder).
- **CSV export & Copy to clipboard** — Share or archive results in one click.
- **Persistent activity log** — Every live replacement is recorded on-screen: which page changed, when, by whom, how many replacements, with View/Edit links. Keeps the 200 most recent updates and can be cleared at any time. Dry-run previews are never logged.
- **Safety first** — Skips revisions, auto-drafts, and trashed posts. Duplicate URLs are deduplicated.
- **Clean uninstall** — Deleting the plugin removes all of its data from the database (activity-log option and per-user transients), multisite-aware. Your content changes are kept.
- **Hardened security** — Capability checks, nonces, input sanitisation, output escaping, and direct-access protection throughout.
- **Translation-ready** — Loaded with text domain `replacely`.

## Supported page builders

Replacely fully supports any editor or page builder that stores its content in the standard WordPress `post_content`, plus four popular builders that keep their content in dedicated post meta:

| Editor / page builder | Where content is stored | Supported |
| --------------------- | ----------------------- | --------- |
| **Block Editor (Gutenberg)** | `post_content` (block HTML) | ✅ |
| **Classic Editor (TinyMCE)** | `post_content` | ✅ |
| **WPBakery Page Builder (Visual Composer)** | shortcodes in `post_content` | ✅ |
| **Divi Builder** | shortcodes in `post_content` | ✅ |
| **Avada / Fusion Builder** | shortcodes in `post_content` | ✅ |
| **Elementor** | `_elementor_data` post meta (JSON) | ✅ |
| **Beaver Builder** | `_fl_builder_data` post meta (serialized layout) + draft mirror | ✅ |
| **Oxygen** | `ct_builder_shortcodes` post meta (shortcode string) | ✅ |
| **Bricks** | `_bricks_page_content_2` / header / footer post meta (serialized tree) | ✅ |

For the meta-based builders, the plugin decodes the stored structure, replaces the text inside it, writes it back losslessly in the format the builder expects, and clears the builder's cache where needed (Elementor's CSS cache and Beaver Builder's asset cache). Builders not listed above — for example Brizy, Cornerstone, Themify, and Zion — are **not** yet covered.

## Requirements

| Requirement | Minimum |
| ----------- | ------- |
| WordPress   | 5.6     |
| PHP         | 7.2     |
| Tested up to (WP) | 7.0 |
| User capability   | `manage_options` |

## Installation

### From a ZIP

1. Download the latest release ZIP from this repository (or the Releases page).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**, then **Activate**.

### Manual installation

1. Clone or download this repository into your `/wp-content/plugins/` directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/<your-username>/replacely.git
   ```
2. Activate **Replacely** from the **Plugins** screen in WordPress.

## Usage

1. Navigate to **Tools → Replacely** in the WordPress admin.
2. Fill in the configuration card:
   - **Search text** — the exact string to find (case-sensitive).
   - **Replace text** — the exact string to put in its place.
   - **URLs** — one URL or path per line. Mix and match full URLs and relative paths.
   - **Dry Run** — leave enabled to preview without writing changes.
3. Click **Run**.
4. Review the results dashboard:
   - Summary tiles show totals (processed, updated, replacements, skipped, errors).
   - The detailed table breaks down each URL with status, post ID, and replacement count.
5. Use **Export CSV** or **Copy Results** to save the run for your records.
6. When the dry run looks correct, disable **Dry Run** and execute the live replacement.
7. Check the **Activity Log** at the bottom of the screen for a running history of pages changed by live runs, each with View/Edit links. Use **Clear log** to empty it (this only removes the log, never the content changes).

> [!WARNING]
> There is no built-in undo. **Always take a full database backup before running a live replacement.** Use Dry Run to verify the impact first.

## How It Works

1. URLs are normalised, deduplicated, and converted to post IDs via WordPress's `url_to_postid()`.
2. Each resolved post's `post_content` — and, for pages built with Elementor, Beaver Builder, Oxygen, or Bricks, the relevant builder post meta — is loaded and scanned for an exact, case-sensitive match of the search string.
3. Matches are counted and replaced with the replacement string (in Dry Run mode nothing is saved). Page-builder content is decoded from its stored format (JSON, a serialized layout, or a shortcode string), replaced safely string-by-string, written back in the exact format the builder expects, and the relevant builder cache (Elementor's CSS, Beaver Builder's assets) is cleared so the change shows on the front end. Counts are tracked per source so you can see how many replacements came from classic content vs. each builder.
4. Revisions, auto-drafts, and trashed posts are skipped automatically.
5. The most recent results are stored in a short-lived per-user transient (15 minutes) so the CSV export endpoint can stream them.
6. Every page actually changed by a **live** run is appended to a persistent activity log (newest first, capped at the 200 most recent entries) so you keep an on-screen audit trail of what was modified, when, and by whom. Dry-run previews are not logged.

## Project Structure

```
replacely/
├── replacely.php                       # Plugin bootstrap, constants, activation hook
├── uninstall.php                       # Removes all plugin data on deletion (multisite-aware)
├── readme.txt                          # WordPress.org-format readme
├── README.md                           # This file
├── assets/
│   ├── css/                            # Admin UI styles
│   └── js/                             # Admin UI scripts
└── includes/
    ├── class-plugin.php                # Plugin container / singleton
    ├── class-admin-page.php            # Admin screen, form, results dashboard, activity log
    ├── class-replacer.php              # Core find/replace service
    └── class-helper.php                # URL normalisation & shared utilities
```

The codebase uses an object-oriented, namespaced architecture (`Replacely\…`) with a clean separation between bootstrap, plugin container, admin UI, replacer service, and helper utilities.

## FAQ

**Is matching case-sensitive?**
Yes. Matching is exact — `Hello` and `hello` are treated as different strings.

**Can I use regular expressions?**
No. By design, the plugin uses exact string replacement to avoid the typical foot-guns of regex-based replacements on production data.

**Does it support custom post types?**
Yes. Any post type whose permalink resolves through `url_to_postid()` is supported, including CPTs registered by other plugins or themes.

**Which page builders are supported?**
Replacely fully supports the Block Editor (Gutenberg), the Classic Editor, WPBakery Page Builder (Visual Composer), Divi Builder, Avada / Fusion Builder, Elementor, Beaver Builder, Oxygen, and Bricks. Any editor or builder that stores its content in the standard WordPress `post_content` works automatically; Elementor, Beaver Builder, Oxygen, and Bricks are handled additionally because they keep their content in their own post meta. Builders not listed (such as Brizy, Cornerstone, Themify, and Zion) are not yet covered. See [Supported page builders](#supported-page-builders) for the full breakdown.

**Does it work with Elementor, Beaver Builder, Oxygen, and Bricks?**
Yes. These builders store their page content in post meta rather than in `post_content`, so the plugin reads that meta as well — decoding the stored structure (JSON for Elementor, a serialized layout for Beaver Builder and Bricks, a shortcode string for Oxygen), replacing the text inside it safely, and writing it back in the exact format the builder expects. After a live run it clears the relevant builder cache (Elementor's CSS cache and Beaver Builder's asset cache) so the change appears on the front end. Per-builder replacement counts are shown separately in the results, the activity log, and the CSV export.

**Will my revisions be modified?**
No. Revisions, auto-drafts, and trashed posts are skipped.

**How do I undo a replacement?**
There is no automatic undo. Always take a full database backup before running a live replacement, or use **Dry Run** first.

**Where are results stored?**
The most recent results are stored in a short-lived per-user transient (15 minutes) solely so the CSV export endpoint can stream them.

**What is the Activity Log?**
A persistent, on-screen audit trail of every page changed by a **live** replacement. Each entry records the page title, post ID, post type, resolved URL, replacement count, the user who ran it, and the timestamp, along with View and Edit links. It keeps the 200 most recent updates (older entries are pruned automatically) and survives across sessions. Dry-run previews are never logged, since nothing is written to the database.

**Does the Activity Log slow my site down or grow forever?**
No. It is stored in a single non-autoloaded option that is hard-capped at 200 entries, and it is only read and rendered on the plugin's own admin screen. Use **Clear log** to empty it at any time — clearing the log only deletes the log itself, not the underlying content changes.

**What happens to my data when I uninstall the plugin?**
The plugin cleans up after itself. Deleting it from the **Plugins** screen removes everything it stored in the database — the activity-log option and every per-user results/state transient (on multisite, the cleanup runs for each site in the network). The actual content changes made to your posts are intentionally left in place, since those are real edits to your site, not plugin data.

## Changelog

### 1.1.0
- Added find & replace support for three more page builders that store content in post meta — **Beaver Builder** (`_fl_builder_data` plus its draft mirror), **Oxygen** (`ct_builder_shortcodes`), and **Bricks** (`_bricks_page_content_2` and its header/footer regions) — alongside the existing Elementor support.
- Beaver Builder's per-post asset cache is now cleared automatically after a live replacement so the change shows on the front end.
- The replacement breakdown across the summary tiles, results table, "pages updated" panel, activity log, and CSV export now reports a per-builder split. The "Elementor replacements" tile became "Page builder replacements", and the CSV gained a "Builder Breakdown" column.

### 1.0.4
- Renamed the plugin to **Replacely – Bulk Content Find & Replace by URLs** and standardized internal prefixes, namespace, and admin asset identifiers (no user-facing functional change).
- Hardened the CSV export against spreadsheet formula injection.

### 1.0.3
- Added a per-source replacement breakdown: results and the activity log now show how many replacements came from classic post content vs. Elementor content (visible in the summary tiles, results table, "pages updated" panel, activity log, and CSV export).

### 1.0.2
- Fixed dashicon alignment in the Copy Results, Export CSV, View, and Edit buttons so the icons sit centered against their labels.

### 1.0.1
- Added clean uninstall: deleting the plugin now removes the activity-log option and all per-user results/state transients from the database (multisite-aware), leaving no plugin data behind. Content changes are preserved.

### 1.0.0
- Initial release.

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

**Ankur Patel**
Website: [ankurpatel.in](https://ankurpatel.in/)

## Contributing

Bug reports, feature requests, and pull requests are welcome. Please open an issue to discuss substantial changes before submitting a PR.
