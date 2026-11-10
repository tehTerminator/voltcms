# Architecture (Phase 1)

See also: `CODING_STANDARDS.md` (conventions), `THEME_GUIDE.md` (theme API), `DATABASE_SCHEMA.md`, `TESTING.md`.

**Request flow:** `.htaccess` -> `index.php` -> `core/engine/bootstrap.php` (autoloader, helpers) -> `App::run()` -> `Env::init()` -> installed? -> `Router::resolve()` -> `AdminApp` or a public page.

**Layout:** `core/` is replaceable engine code (`engine/`, `admin/`, `migrations/`); `user/` is site data that updates must never overwrite (`db.php`, `config/`, `content/`, `uploads/`, `themes/`, `backups/`, `cache/`, `logs/`).

**Classes (`namespace Cms`, file per class, no Composer):** Config, Database, Migrator, Settings, Files, Logger, Env, Url, Request, Response, View, Session, Csrf, Crypto, Totp, RateLimiter, Auth, Router, App; admin: AdminApp + `views/*.php`.

**Conventions**
- Every POST is CSRF-checked centrally in `AdminApp::handle()`.
- Every admin route except sign-in requires a session whose user row still exists.
- SQL only through `Database` with prepared statements; table names via `{p}`.
- Output escaped with `e()`; admin pages carry a strict CSP with no inline script or style.
- Secrets (TOTP seeds) encrypted with a key derived from `app_key`; IPs/usernames/recovery codes stored as keyed hashes.
- Files that replace existing data are written via `Files::writeAtomic()` (temp file + rename).
- Errors are logged to `user/logs`, never displayed unless `debug` is set in `user/config/app.php`.

## Phase 2 additions

**Public request flow:** `index.php` -> `App::run()` -> version gate (503 if `installed_version` != `CMS_VERSION`) -> `Router::resolve()` -> `PublicApp` (`home`, `content`, or themed 404) -> `ContentRepository` (indexed queries) -> `ContentStore` (one file read) -> `PublicRenderer` (core/themes/base templates) -> `Response::publicHeaders()` (CSP with per-request nonce).

**Admin flow for content:** `AdminApp` (session, CSRF on every POST, auth, update gate) -> `ContentAdmin` (screens) -> `ContentWriter` (validate, sanitise, slugs, revisions, trash/purge) -> `ContentRepository` / `ContentStore`.
Admin-only classes (`ContentWriter`, `ContentAdmin`, `AdminApp`) are never loaded on public requests, except `ContentAdmin::preview()` which uses `PublicApp`.

**Storage rules**
- `user/content/posts/{id}.html` body (sanitised HTML), `user/backups/revisions/{content_id}/{revision_id}.html`, `user/backups/deleted/{id}-{timestamp}/`.
- Order of operations never loses data: the old body is saved as a revision before it is replaced; purge deletes the DB row first and then moves files; nothing is deleted from disk automatically.
- Visibility: public pages are `status='published' AND visibility='public' AND deleted_at IS NULL`. Private pages are reachable only by URL plus password (or admin session) and are excluded from every listing.

**Upgrades:** upload new files (not `user/`), sign in, the admin shows "Update required", apply it. It runs pending `core/migrations/*.sql` and stores the new version in `user/config/app.php`. Phase 7's verified updater will reuse this step.

## Phase 3 additions: themes

**Rendering path:** `PublicApp` picks template candidates -> `PublicRenderer::render()` -> `Theme::locate()` (child, then Base) -> template output -> `layout.php` (child, then Base) -> response. Helper functions (`partial()`, `get_theme_url()`, `theme_head_assets()` ...) read the current theme and the current template variables from static context held by `Theme` while a page renders.

**Failure handling:** `PublicRenderer` wraps rendering per theme. A `Throwable` (including `ParseError`) from a child theme is logged and the page is rendered again with Base; if Base fails, `Theme::safeFallback()` prints a minimal page.

**Assets:** child theme files are linked as `/user/themes/{name}/assets/...?v={mtime}` (static, served by the web server). Base theme files live in `core/` (not web-accessible) and are served by `ThemeAssets` at `/assets/base/...`; the route runs before the database/update gate and needs no session.

**Admin:** `ThemeAdmin` (list, activate, create skeleton) and `ThemeValidator` (admin only, never loaded on public requests). The active theme is the `active_theme` row in `settings`.

**Public API stability:** template names, partial names, variables and functions documented in `THEME_GUIDE.md`.

## Phase 4A additions

**File storage layout (all under `user/`, never touched by updates):** `uploads/YYYY/MM/{random}.{ext}` (media), `assets/{css,js}/{id}-{name}.{ext}` (code assets, web-readable 0644 because the web server serves them), `backups/assets/{id}/` (code versions, newest 10), `backups/deleted/media-{id}-{time}/` and `asset-{id}-{time}/` (archives of deleted items). Nothing is deleted from disk automatically; integrity reports list missing and orphaned files.

**Migrations:** `Migrator` runs `NNNN_name.sql` and `NNNN_name.php` files in version order. A PHP migration returns a function `(Database $db): void` and is used for conditional schema changes and data conversion. Conversion logic lives in testable classes (`CodeAssetConverter`), the migration file only calls it.

**Admin routing:** `AdminApp` keeps a prefix-to-controller map (`/admin/content`, `themes`, `media`, `code`, `tags`, `settings`). Session, CSRF, authentication and the update gate run once for all of them. A controller's `handle()` returns 0 when it handled the request, or 404/405.

**Linked CSS/JS:** `PublicApp::renderContent()` asks `CodeAssetRepository::urlsForContent()` (one query) and passes `$itemAssets` to the theme. Scripts and styles are ordinary same-origin files, so the public Content Security Policy needs no inline allowance for them.

**Search index:** `ContentWriter` keeps `search_index` in step with every save inside the same transaction as the content row. Query paths and limits are documented in the `SearchIndex` class header. Visitor-facing search is part of 4B.

**Media safety:** the file type is detected from content (`finfo`) and must be on the allowlist; images must have a valid header within size limits; stored names are random; `user/uploads/.htaccess` forbids script execution; SVG is not accepted.

## Phase 4B additions

**Addresses.** `content.path` is the single source of truth for a content item's URL. `ContentWriter` computes it from the parent's path plus the slug whenever an item is created, renamed or moved, and `repath()` rewrites every descendant's path and records a redirect for each old address, all inside the save transaction. `Router` sends any 1 to 12 segment path that no fixed route claimed to `PublicApp::content()`, which looks it up by path, then in `content_redirects` (only redirecting to published, public items, so a private or draft page never leaks through a redirect), then answers 404. Case differences redirect (301) to the canonical address. Themes build links only with `content_url()` / `Url::content()`.

**Thumbnails.** `Thumbnailer` (GD) makes a 400px-longest-side thumbnail at upload time (`MediaWriter::import`), stored beside the original in `thumbs/`, never failing the upload. Images that are already small get none, images too large for the PHP memory limit are skipped, and without GD nothing is recorded so the "Generate thumbnails" tool can pick them up later. Grids use `MediaRepository::thumbUrl()` (thumbnail if present, otherwise the original).

## Admin pickers (autocomplete), release 0.4.1-phase4b1

Long lists no longer render as `<select>` boxes (which loaded up to 2000 books or 200 images into every editor page). A picker is a `Cms\AutocompleteField` (server-rendered numeric input with `data-ac-*` attributes) that `assets/autocomplete.js` enhances in the browser: it hides the numeric input, adds a search box with a suggestion list, and keeps the chosen id in the hidden field, so form posts, server-side validation and the no-JavaScript fallback are unchanged. Suggestions come from `/admin/lookup/content-parent` and `/admin/lookup/media-image` (`LookupAdmin` -> `LookupService`): authenticated GET only, JSON, never cached, at most 12 results, queries capped at 100 characters. The script follows the ARIA 1.2 combobox pattern, debounces input (200 ms), cancels superseded requests, ignores late responses after the user leaves the field, and builds every row with `textContent` (no `innerHTML`), so titles cannot inject markup. The admin Content Security Policy gained `connect-src 'self'` for this; scripts and styles remain same-origin files. Admin assets (`admin.js`, `autocomplete.js`, `admin.css`) are served from a fixed whitelist.

## Phase 4C additions: discovery

**Routes.** `Router` matches the fixed public routes first (`/search`, `/feed.xml`, `/sitemap.xml`, `/robots.txt`, `/tag/{slug}`, `/assets/base/...`), then any 1 to 12 segment address is content. Reserved slugs (`search`, `tag`, `feed.xml`, `sitemap.xml`, `robots.txt`, ...) therefore can never be taken by content at the top level.

**SEO.** `Seo` turns a content row (plus site settings and an optional social image row) into the `$seo` theme variable. All decisions about what may be shown (noindex, private, missing site URL, fallbacks) are made there, in one place, with unit tests; the theme only prints. `PublicApp` builds `$seo` for content, home, tag and search pages.

**Syndication.** `Syndication` generates `robots.txt`, the sitemap and the RSS feed from the same "published, public, not trashed, not noindex" rule. The sitemap is streamed in batches (limit 50,000 URLs, no sitemap index) and answers conditional requests from a cheap ETag (count, newest change, highest id, checksum of addresses). The feed lists the newest 20 qualifying articles with excerpts and tags as categories. Both refuse (503) rather than guess a host when no site URL is configured.

**Tag pages and search.** `TagRepository::publicItems` and `SearchIndex::search` apply the same public filter; every result row carries the full address, so links go through `content_url()`. No new tables or migrations.

## Phase 5 (global settings) additions

**Shared settings.** `App::settings()` loads the settings table once per request; `Format::configure()` (time zone, date format) is applied right after, so public pages and admin lists format dates identically. `PublicApp` reads `items_per_page` from it. Invalid stored values (zone, format, page size) fall back to defaults instead of failing.

**Maintenance mode.** `App::run()` checks `maintenance_mode` for every route except the admin area and the static asset route. Visitors, crawlers and feed readers get `PublicApp::maintenance()` (503, `Retry-After`, no-store, noindex). A signed-in administrator passes: the session is started only when a `cmssid` cookie exists, so ordinary visitors never trigger a session during maintenance. The value must be exactly `1`, so a stray setting cannot lock visitors out.
