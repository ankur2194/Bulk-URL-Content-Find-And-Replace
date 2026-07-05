=== Replacely – Bulk Content Find & Replace by URLs ===
Contributors: ankur2194
Tags: find and replace, search replace, bulk edit, urls, page builder
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: replacely

Bulk find and replace exact text in post, page, and page-builder content (Elementor, Beaver, Oxygen, Bricks) by URL, with dry-run preview and CSV export.

== Description ==

**Replacely** is an administration tool that lets WordPress site owners and developers safely find and replace exact text inside post, page, custom-post-type, and page-builder content across a curated list of URLs or paths.

Unlike search-and-replace tools that scan the whole database blindly, this plugin operates surgically: you provide the list of URLs you want to touch, the exact text to find, and the exact text to replace it with. Nothing else is modified.

= Highlights =

* Exact, case-sensitive matching. No regex surprises.
* Accept full URLs (e.g. `https://example.com/sample-page/`) and relative paths (e.g. `/sample-page/`).
* Detect target post automatically via `url_to_postid()`, supporting any registered post type.
* Page-builder aware: also replaces text inside Elementor, Beaver Builder, Oxygen, and Bricks page content (which is stored in post meta, not `post_content`) and refreshes the relevant builder cache afterward.
* **Dry Run** mode shows what *would* change before any database write.
* Results dashboard with summary tiles, detailed table, status colors, dashicons, and replacement counts split by source (classic content vs. each page builder).
* One-click **CSV export** and **Copy results** to clipboard.
* Persistent **activity log** of every live replacement (page, post type, replacement count, user, timestamp, View/Edit links). Keeps the 200 most recent updates and can be cleared anytime. Dry runs are never logged.
* Hardened security: capability checks, nonces, sanitisation, escaping, direct-access protection.
* Skips revisions, auto-drafts, and trashed posts. Duplicate URLs are processed only once.
* Clean uninstall: deleting the plugin removes all of its data (activity-log option and per-user transients) from the database, multisite-aware. Your content changes are kept.
* Translation-ready.

= Supported page builders =

Replacely fully supports any editor or page builder that stores its content in the standard WordPress `post_content`, plus four popular builders that keep their content in dedicated post meta:

* **Block Editor (Gutenberg)** — block content in `post_content`.
* **Classic Editor (TinyMCE)** — content in `post_content`.
* **WPBakery Page Builder (Visual Composer)** — shortcodes in `post_content`.
* **Divi Builder** — shortcodes in `post_content`.
* **Avada / Fusion Builder** — shortcodes in `post_content`.
* **Elementor** — content in the `_elementor_data` post meta (JSON).
* **Beaver Builder** — content in the `_fl_builder_data` post meta (and its draft mirror).
* **Oxygen** — content in the `ct_builder_shortcodes` post meta.
* **Bricks** — content in the `_bricks_page_content_2` / header / footer post meta.

For the meta-based builders, the plugin decodes the stored structure, replaces the text inside it, writes it back losslessly, and clears the builder's cache where needed. Builders not listed above (for example Brizy, Cornerstone, Themify, and Zion) are not yet covered.

= Built for reliability =

* Object-oriented architecture with namespaced classes.
* Modular file structure: bootstrap, plugin container, admin page, replacer service, helper utilities.
* Polished, responsive admin UI that complements the native WordPress design language.

== Installation ==

1. Upload the `replacely` folder to `/wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin from the **Plugins** screen.
3. Navigate to **Tools → Replacely**.

== Frequently Asked Questions ==

= Is matching case-sensitive? =

Yes. Matching is exact and case-sensitive, so `Hello` and `hello` are not considered the same.

= Can I use regular expressions? =

No. By design, the plugin uses exact string replacement to avoid the typical foot-guns of regex-based replacements on production data.

= Does it support custom post types? =

Yes. Any post type whose permalink resolves through `url_to_postid()` is supported, including CPTs registered by other plugins or themes.

= Which page builders are supported? =

Replacely fully supports the Block Editor (Gutenberg), the Classic Editor, WPBakery Page Builder (Visual Composer), Divi Builder, Avada / Fusion Builder, Elementor, Beaver Builder, Oxygen, and Bricks. Any editor or builder that stores its content in the standard WordPress `post_content` works automatically; Elementor, Beaver Builder, Oxygen, and Bricks are handled additionally because they keep their content in their own post meta. Builders not listed (such as Brizy, Cornerstone, Themify, and Zion) are not yet covered.

= Does it work with Elementor, Beaver Builder, Oxygen, and Bricks? =

Yes. These builders store their page content in post meta rather than in `post_content`, so the plugin reads that meta as well — decoding the stored structure (JSON for Elementor, a serialized layout for Beaver Builder and Bricks, a shortcode string for Oxygen), replacing the text inside it safely, and writing it back in the exact format the builder expects. After a live run it clears the relevant builder cache (Elementor's CSS cache and Beaver Builder's asset cache) so the change appears on the front end. Per-builder replacement counts are shown separately in the results, the activity log, and the CSV export.

= Will my revisions be modified? =

No. Revisions, auto-drafts, and trashed posts are skipped.

= How do I undo a replacement? =

There is no automatic undo. Always take a full database backup before running a live replacement, or use **Dry Run** first to verify the impact.

= Where are results stored? =

The most recent results are stored in a short-lived per-user transient (15 minutes) solely so the CSV export endpoint can stream them.

= What is the Activity Log? =

A persistent, on-screen audit trail of every page changed by a live replacement. Each entry records the page title, post ID, post type, resolved URL, replacement count, the user who ran it, and the timestamp, with View and Edit links. It keeps the 200 most recent updates (older entries are pruned automatically) and survives across sessions. Dry-run previews are never logged, since nothing is written to the database. It is stored in a single capped option and can be emptied with **Clear log** — clearing the log deletes only the log, not the underlying content changes.

= What happens to my data when I uninstall the plugin? =

The plugin cleans up after itself. Deleting it from the **Plugins** screen removes everything it stored in the database: the activity-log option and every per-user results/state transient (on multisite, this runs for each site in the network). The actual content changes made to your posts are intentionally left in place, since those are real edits to your site rather than plugin data.

== Screenshots ==

1. Configuration card with Search, Replace, URLs, and Dry Run toggle.
2. Results dashboard with summary tiles, status colors, and detailed table.
3. CSV export and Copy Results actions.

== Changelog ==

= 1.1.0 =
* Added: support for three more page builders that store content in post meta — **Beaver Builder** (`_fl_builder_data` plus its draft mirror), **Oxygen** (`ct_builder_shortcodes`), and **Bricks** (`_bricks_page_content_2` and its header/footer regions) — in addition to the existing Elementor support.
* Added: Beaver Builder's per-post asset cache is cleared automatically after a live replacement so changes show on the front end.
* Changed: the replacement breakdown (summary tiles, results table, "pages updated" panel, activity log, and CSV export) now reports a per-builder split. The single "Elementor replacements" tile is now "Page builder replacements", and the CSV gains a "Builder Breakdown" column.

= 1.0.4 =
* Renamed the plugin to "Replacely – Bulk Content Find & Replace by URLs" and standardized internal prefixes, namespace, and admin asset identifiers. No user-facing functional change.
* Hardened the CSV export against spreadsheet formula injection.

= 1.0.3 =
* Added: replacement counts are now broken down by source — classic post content vs. Elementor content — across the results summary tiles, the results table, the "pages updated" panel, the activity log, and the CSV export.

= 1.0.2 =
* Fixed: dashicons in the Copy Results, Export CSV, View, and Edit buttons are now vertically centered with their button labels.

= 1.0.1 =
* Added: clean uninstall. Deleting the plugin now removes the activity-log option and all per-user results/state transients from the database (multisite-aware), leaving no plugin data behind. Content changes are preserved.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds find & replace support for Beaver Builder, Oxygen, and Bricks (in addition to Elementor), with a per-builder replacement breakdown.

= 1.0.4 =
Plugin renamed to Replacely. No functional changes.

= 1.0.3 =
Results and the activity log now show how many replacements came from classic content vs. Elementor content.

= 1.0.2 =
Fixes button icon alignment on the admin tool screen.

= 1.0.1 =
Adds clean uninstall — all plugin data is removed from the database when the plugin is deleted.

= 1.0.0 =
First public release.
