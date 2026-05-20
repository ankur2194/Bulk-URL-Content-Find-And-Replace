=== Bulk URL Content Find & Replace ===
Contributors: ankur2194
Tags: find and replace, bulk edit, urls, content, search replace, posts, pages, elementor
Requires at least: 5.6
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: bulk-url-content-find-replace

Premium tool for administrators to bulk find and replace exact text inside post, page, CPT, and Elementor content by listing URLs or paths — with dry-run preview, full results dashboard, and CSV export.

== Description ==

**Bulk URL Content Find & Replace** is a premium administration tool that lets WordPress site owners and developers safely find and replace exact text inside post, page, custom-post-type, and Elementor content across a curated list of URLs or paths.

Unlike search-and-replace tools that scan the whole database blindly, this plugin operates surgically: you provide the list of URLs you want to touch, the exact text to find, and the exact text to replace it with. Nothing else is modified.

= Highlights =

* Exact, case-sensitive matching. No regex surprises.
* Accept full URLs (e.g. `https://example.com/sample-page/`) and relative paths (e.g. `/sample-page/`).
* Detect target post automatically via `url_to_postid()`, supporting any registered post type.
* Elementor-aware: also replaces text inside Elementor page content (`_elementor_data`) and refreshes Elementor's CSS cache afterward.
* **Dry Run** mode shows what *would* change before any database write.
* Premium results dashboard with summary tiles, detailed table, status colors, dashicons.
* One-click **CSV export** and **Copy results** to clipboard.
* Hardened security: capability checks, nonces, sanitisation, escaping, direct-access protection.
* Skips revisions, auto-drafts, and trashed posts. Duplicate URLs are processed only once.
* Translation-ready.

= Built for premium use =

* Object-oriented architecture with namespaced classes.
* Modular file structure: bootstrap, plugin container, admin page, replacer service, helper utilities.
* Polished, responsive admin UI that complements the native WordPress design language.

== Installation ==

1. Upload the `bulk-url-content-find-replace` folder to `/wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin from the **Plugins** screen.
3. Navigate to **Tools → Bulk URL Content Find & Replace**.

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

== Screenshots ==

1. Configuration card with Search, Replace, URLs, and Dry Run toggle.
2. Results dashboard with summary tiles, status colors, and detailed table.
3. CSV export and Copy Results actions.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
First public release.
