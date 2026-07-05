# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin: **Replacely**. An admin tool (Tools → Replacely) that does exact, case-sensitive find/replace inside the content of specific posts identified by a list of URLs/paths — including pages built with Elementor, Beaver Builder, Oxygen, and Bricks. Has a dry-run mode, a results dashboard, CSV export, and a persistent activity log.

There is **no build step, no package manager, and no test suite**. It is plain PHP loaded directly by WordPress; CSS/JS in `assets/` are hand-written and served as-is. To run it, drop the directory into `wp-content/plugins/` of a WordPress install and activate. Code only executes inside a WordPress runtime — there is no standalone entry point to invoke.

The committed `phpcs:ignore` annotations indicate the intended style is the **WordPress Coding Standards** (tabs for indentation, Yoda conditions, `wp_*` escaping/sanitization helpers), but no PHPCS config is committed.

## Architecture

Everything lives under the `Replacely\` namespace and uses the `REPLACELY_` constant prefix / `replacely_` function prefix. Load order is fixed in the bootstrap file (`replacely.php`): helper → replacer → admin page → plugin, then `Plugin::instance()->init()` on `plugins_loaded`.

Four classes, clean separation:

- **`Plugin`** (`includes/class-plugin.php`) — singleton container. Only wires up `Admin_Page` (admin context only). No business logic. It deliberately does **not** call `load_plugin_textdomain()` — translations load automatically (WordPress.org serves them and WP's JIT loader picks up bundled `.mo` files in `/languages`) because the text domain matches the slug.
- **`Admin_Page`** (`includes/class-admin-page.php`) — the controller + view layer. Registers the Tools menu, enqueues assets *only on its own screen*, and handles three `admin-post.php` actions: `replacely_run`, `replacely_export_csv`, `replacely_clear_log`. Also contains all HTML rendering (form, summary tiles, results table, updated-pages panel, activity log).
- **`Replacer`** (`includes/class-replacer.php`) — the core engine, framework-light. Constructed with `(search, replace, dry_run)`, exposes `process(array $lines)` returning `['rows' => [...], 'summary' => [...]]`. This is where to make changes to *what gets replaced and how*.
- **`Helper`** (`includes/class-helper.php`) — stateless statics + the canonical constants (`CAPABILITY = manage_options`, nonce names, `PAGE_SLUG`, activity-log option key/limit). URL normalization, line splitting/dedup, status→label/tone/dashicon maps, and the activity-log read/write/clear all live here.

### Request flow (POST-Redirect-GET)

`handle_post()` deliberately uses PRG: validate → run the `Replacer` → stash everything in **per-user transients** → `wp_safe_redirect` back to the page. `render()` then *consumes* the state (one-shot read, deletes the transient) so a browser refresh shows a clean empty form and never re-submits.

Two transients, both keyed by user ID with a 15-minute TTL:
- `replacely_last_state_{user_id}` — full form state (inputs, errors, results) to rehydrate the screen once.
- `replacely_last_results_{user_id}` — results only, read separately by the CSV export endpoint so export never re-processes.

The **activity log** is different and persistent: stored in the `replacely_activity_log` *option* (not a transient), only successfully-`updated` rows from **live** runs are appended (dry-run rows never are), newest-first, hard-capped at `Helper::LOG_LIMIT` (200).

### How replacement actually works (Replacer)

Per line: `Helper::normalize_to_url()` (absolute URLs pass through `esc_url_raw`; anything else is treated as a relative path on this site) → `url_to_postid()` → load the post. Lines short-circuit into status codes: `invalid_url`, `duplicate` (post ID already seen this run), `skipped` (auto-draft / inherit / trash / revision), `not_supported` (no `edit_post` cap), `no_match`, `preview` (dry run), `updated`, `failed`.

Content sources searched and counted per post:
1. **Classic `post_content`** — `substr_count` to count, `str_replace` to apply, saved via `wp_update_post`. This single pass also covers shortcode-based builders (WPBakery, Divi, Avada/Fusion) since they keep their content here.
2. **Page builders that store content in post meta** — Elementor, Beaver Builder, Oxygen, and Bricks. These are driven by a data-driven registry in `Replacer::builder_definitions()`, keyed by builder slug. Each entry declares a `format` and which meta keys to read:
   - `format` is `'json'` (Elementor `_elementor_data`), `'php'` (Beaver `_fl_builder_data` + `_fl_builder_draft`; Bricks `_bricks_page_content_2`/header/footer — already-unserialized arrays/objects from `get_post_meta`), or `'string'` (Oxygen `ct_builder_shortcodes`, a plain shortcode string treated like content).
   - `count_keys` are the front-end source of truth that contribute to the user-facing count; `apply_keys` is a superset that also gets rewritten (e.g. Beaver's draft mirror is applied but **not** counted, to avoid double-counting). Bricks' three regions are all both counted and applied since they're distinct content.
   - `scan_builders()` builds a plan (`counts` map + `writes` list + `encode_failed` flag); `replace_in_meta()` does the per-format decode/replace/encode; `replace_in_structure()` walks **arrays *and* objects**, touching only *string leaves* (scalars preserved). `apply_builder_writes()` saves each write with `update_post_meta(..., wp_slash($value))` (`wp_slash` recurses into arrays/objects). JSON re-encode failure with matches present aborts the whole post (reported as `failed`) rather than writing a corrupt value.
   - Cache invalidation: Elementor deletes per-post `_elementor_css` and runs one batch `files_manager->clear_cache()` at the end (guarded by `class_exists`); Beaver calls `\FLBuilderModel::delete_asset_cache($post_id)` per updated post (guarded). Oxygen/Bricks render text from their data at runtime, so no cache clear is needed for text changes.

Per-row data carries `content_replacements` (int) and `builder_replacements` (slug→count map); the summary aggregates the same. The UI/CSV render this via `Admin_Page::builder_map_from()` (which also reads the legacy flat `elementor_replacements` shape from old activity-log entries). Builder labels/dashicons live in `Helper::builders()` / `builder_label()` / `builder_dashicon()`.

To add another meta-based builder: add an entry to `builder_definitions()` (pick the right `format`), add its slug→label to `Helper::builders()` (and an icon in `builder_dashicon()`), and add a cache-clear branch in `apply_builder_writes()` if the builder caches rendered output. The UI, CSV, and activity log pick it up automatically.

Matching is intentionally exact and case-sensitive — **no regex**. If asked to add fuzzy/regex/case-insensitive matching, that's a deliberate design departure; confirm intent first.

## Conventions to follow when editing

- **Security boilerplate is non-negotiable and already pervasive — match it.** Every entry point checks `current_user_can( Helper::CAPABILITY )`, verifies a nonce (`check_admin_referer`), `wp_unslash`es `$_POST` before use, and escapes on output (`esc_html`/`esc_attr`/`esc_url`/`esc_textarea`). Every PHP file starts with the `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard.
- **All user-facing strings are translated** with the literal text domain `'replacely'` (string literal, not the `REPLACELY_TEXT_DOMAIN` constant — WP i18n tooling requires a literal). Use `_n()` for pluralized counts as the existing code does.
- **CSV export is hardened against formula injection.** `Admin_Page::handle_csv_export()` runs every data cell through `csv_escape_formula()`, which prefixes any value starting with `=`, `+`, `-`, `@`, tab, or carriage return with a single quote so spreadsheet apps treat it as text. Preserve this when changing the export.
- New status codes must be added in **three** `Helper` maps together: `status_label()`, `status_tone()`, `status_dashicon()` — they're looked up in parallel by the renderer.
- Bump `REPLACELY_VERSION` in `replacely.php` when changing `assets/` files — it's the cache-busting query string for enqueued CSS/JS. Also reflect releases in both `readme.txt` (WordPress.org format) and `README.md`.
