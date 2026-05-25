=== Replacely – Bulk Content Find & Replace by URLs ===
Contributors: ankur2194
Tags: find and replace, search replace, bulk edit, urls, content
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: replacely

Bulk find and replace exact text in post, page, and Elementor content by URL, with dry-run preview, results dashboard, and CSV export.

== Description ==

**Replacely** is an administration tool that lets WordPress site owners and developers safely find and replace exact text inside post, page, custom-post-type, and Elementor content across a curated list of URLs or paths.

Unlike search-and-replace tools that scan the whole database blindly, this plugin operates surgically: you provide the list of URLs you want to touch, the exact text to find, and the exact text to replace it with. Nothing else is modified.

= Highlights =

* Exact, case-sensitive matching. No regex surprises.
* Accept full URLs (e.g. `https://example.com/sample-page/`) and relative paths (e.g. `/sample-page/`).
* Detect target post automatically via `url_to_postid()`, supporting any registered post type.
* Elementor-aware: also replaces text inside Elementor page content (`_elementor_data`) and refreshes Elementor's CSS cache afterward.
* **Dry Run** mode shows what *would* change before any database write.
* Results dashboard with summary tiles, detailed table, status colors, dashicons, and replacement counts split by source (classic content vs. Elementor).
* One-click **CSV export** and **Copy results** to clipboard.
* Persistent **activity log** of every live replacement (page, post type, replacement count, user, timestamp, View/Edit links). Keeps the 200 most recent updates and can be cleared anytime. Dry runs are never logged.
* Hardened security: capability checks, nonces, sanitisation, escaping, direct-access protection.
* Skips revisions, auto-drafts, and trashed posts. Duplicate URLs are processed only once.
* Clean uninstall: deleting the plugin removes all of its data (activity-log option and per-user transients) from the database, multisite-aware. Your content changes are kept.
* Translation-ready.

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

= Does it work with Elementor? =

Yes. Elementor stores its page content as JSON in the `_elementor_data` post meta rather than in `post_content`, so the plugin searches and replaces inside that data as well — decoding it, replacing the text safely, re-encoding it, and saving. After a live run it regenerates Elementor's cached CSS so the change appears on the front end. Other page builders that keep content in their own storage are not covered.

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
