# Bulk URL Content Find & Replace

> A premium WordPress administration tool for safely performing **bulk find and replace** on post, page, and custom-post-type content — including pages built with Elementor — across a curated list of URLs or paths, with a dry-run preview, a polished results dashboard, CSV export, and a persistent activity log.

[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-purple.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/version-1.0.0-orange.svg)](#changelog)

---

## Overview

Most "search & replace" tools scan the entire WordPress database blindly, which is risky on production sites. **Bulk URL Content Find & Replace** takes the opposite approach: you provide an explicit list of URLs or paths, the exact text to find, and the exact text to replace it with. Only the content of those specific posts is touched — nothing else.

It is built for site owners, agencies, and developers who need surgical, predictable content edits across many pages at once.

## Features

- **Targeted by URL** — Operate only on the posts/pages you list. No accidental side-effects.
- **Exact, case-sensitive matching** — No regex foot-guns. What you type is what gets replaced.
- **Mixed URL and path support** — Accepts full URLs (`https://example.com/sample-page/`) and relative paths (`/sample-page/`).
- **Any post type** — Detects target posts via `url_to_postid()`, so it works with posts, pages, and any registered CPT (including those from other plugins or themes).
- **Elementor-aware** — Also finds and replaces text inside Elementor page content stored in `_elementor_data`, then refreshes Elementor's CSS cache so the changes appear on the front end right away.
- **Dry Run mode** — Preview the exact number of replacements per URL *before* writing anything to the database.
- **Premium results dashboard** — Summary tiles, status colors, dashicons, and a detailed per-URL table.
- **CSV export & Copy to clipboard** — Share or archive results in one click.
- **Persistent activity log** — Every live replacement is recorded on-screen: which page changed, when, by whom, how many replacements, with View/Edit links. Keeps the 200 most recent updates and can be cleared at any time. Dry-run previews are never logged.
- **Safety first** — Skips revisions, auto-drafts, and trashed posts. Duplicate URLs are deduplicated.
- **Hardened security** — Capability checks, nonces, input sanitisation, output escaping, and direct-access protection throughout.
- **Translation-ready** — Loaded with text domain `bulk-url-content-find-replace`.

## Requirements

| Requirement | Minimum |
| ----------- | ------- |
| WordPress   | 5.6     |
| PHP         | 7.2     |
| Tested up to (WP) | 6.5 |
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
   git clone https://github.com/<your-username>/bulk-url-content-find-replace.git
   ```
2. Activate **Bulk URL Content Find & Replace** from the **Plugins** screen in WordPress.

## Usage

1. Navigate to **Tools → Bulk URL Content Find & Replace** in the WordPress admin.
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
2. Each resolved post's `post_content` — and, for Elementor-built pages, its `_elementor_data` — is loaded and scanned for an exact, case-sensitive match of the search string.
3. Matches are counted and replaced with the replacement string (in Dry Run mode nothing is saved). Elementor content is decoded from JSON, replaced safely string-by-string, re-encoded, and saved; Elementor's cached CSS is then regenerated so the change shows on the front end.
4. Revisions, auto-drafts, and trashed posts are skipped automatically.
5. The most recent results are stored in a short-lived per-user transient (15 minutes) so the CSV export endpoint can stream them.
6. Every page actually changed by a **live** run is appended to a persistent activity log (newest first, capped at the 200 most recent entries) so you keep an on-screen audit trail of what was modified, when, and by whom. Dry-run previews are not logged.

## Project Structure

```
bulk-url-content-find-replace/
├── bulk-url-content-find-replace.php   # Plugin bootstrap, constants, activation hook
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

The codebase uses an object-oriented, namespaced architecture (`BUCFR\…`) with a clean separation between bootstrap, plugin container, admin UI, replacer service, and helper utilities.

## FAQ

**Is matching case-sensitive?**
Yes. Matching is exact — `Hello` and `hello` are treated as different strings.

**Can I use regular expressions?**
No. By design, the plugin uses exact string replacement to avoid the typical foot-guns of regex-based replacements on production data.

**Does it support custom post types?**
Yes. Any post type whose permalink resolves through `url_to_postid()` is supported, including CPTs registered by other plugins or themes.

**Does it work with Elementor?**
Yes. Elementor stores its page content as JSON in the `_elementor_data` post meta rather than in `post_content`, so the plugin searches and replaces inside that data as well — decoding it, replacing the text safely, re-encoding it, and saving. After a live run it regenerates Elementor's cached CSS so the change appears on the front end. Other page builders that keep content in their own storage are not covered.

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

## Changelog

### 1.0.0
- Initial release.

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

**Ankur Patel**
Website: [ankurpatel.in](https://ankurpatel.in/)

## Contributing

Bug reports, feature requests, and pull requests are welcome. Please open an issue to discuss substantial changes before submitting a PR.
