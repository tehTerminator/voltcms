# Project status

**Release:** 0.5.0-phase5  ·  **Host seen working:** PHP 8.3.35, MariaDB 10.6 (code is kept PHP 8.0 compatible)  ·  **Updated:** 2026-10-31

## Phase 1 — skeleton, config, database, installer, migrations, authentication: DONE (verified on the real host)
## Phase 2 — content CRUD, file storage, routing, publishing: DONE (verified on the real host)
See CHANGELOG for details.

## Phase 3 — base/child theme engine and default theme: DONE (verified on the real host)

Implemented
- Theme model: the built-in **Base** theme (`core/themes/base/`) and **child themes** in `user/themes/{name}/` (never touched by updates). Single level: a child's parent is Base.
- File resolution for layout, templates, partials and assets: child theme, then Base, then a built-in safe page. Memoised per request (no persistent cache is needed, so nothing to invalidate).
- Template selection: `templates/{type}-{slug}.php`, `templates/{type}.php`, `templates/content.php`; plus `home`, `private`, `404`, `error`.
- Theme API for templates: `partial()`, `get_theme_url()`, `theme_head_assets()`, `theme_footer_assets()`, `theme_info()`, plus the existing `e()`, `url()`, `content_url()`, `format_date()`, `csrf_field()`.
- Assets: global (declared in `theme.json`, Base then child) and template-specific by convention (`assets/css|js/templates/{template}.*`). Child assets are static files under `/user/themes/`; Base assets are served at `/assets/base/...` by `ThemeAssets` (path-safe, extension allowlist, ETag/304, one-year cache with `?v=`).
- Base theme rebuilt as layout + partials (`meta, vendor-css, vendor-js, item-css, item-js, preview-banner, header, footer, pagination, breadcrumbs, book-nav`) + templates + `theme.css`, a book-only stylesheet and `theme.js` (responsive tables).
- Admin **Themes** screen: list with validation status, activate (only after validation), switch back to Base, create a child-theme skeleton.
- Validation before activation: manifest rules, compatibility (`requires.cms`), asset existence, PHP syntax check without executing, no symlinks/hidden files/disallowed types, size limits.
- Runtime fallback: a child template/layout that throws or fails to parse -> page re-rendered with Base and logged; Base unusable -> plain built-in page; active theme folder missing/invalid name -> Base.
- `THEME_GUIDE.md`: the documented theme API.

Documents: `CODING_STANDARDS.md` (coding standard and naming conventions, added at the end of Phase 3 after review against the spec) and `THEME_GUIDE.md`.
Files added: engine `Theme`, `ThemeAssets`, `theme_helpers.php`; admin `ThemeValidator`, `ThemeAdmin`, view `themes.php`; base theme `theme.json`, `layout.php`, `templates/`, `partials/`, `assets/`; `THEME_GUIDE.md`; test scripts renamed to `tests/e2e_upgrade.py|.sh`.
Files changed: `PublicRenderer` (theme-aware, fallback), `PublicApp` (template candidates), `Router`/`App` (asset route), `AdminApp` (themes delegation, dashboard theme), admin `layout`/`dashboard` views, `core/VERSION`, `tests/run.php`.
Files removed from Base's top level (moved into `templates/`): `home.php`, `page.php`, `article.php`, `book.php`, `private.php`, `404.php`, `error.php`.
No database migration in this phase; the only new stored value is the `active_theme` setting.

Tests: see `TESTING.md` (204 unit/DB; upgrade + feature HTTP suite 3 + 89 + 69; Phase 1 regression 68; all passing on PHP 8.3.6 + MariaDB 10.11).

## Decisions made in Phase 3
- Base assets go through PHP (`/assets/base/`) instead of editing your root `.htaccess`, so the upgrade needs no server-config changes and also works on Nginx. Child assets are served directly by the web server for speed.
- Only `layout.php`, `templates/*` and `partials/*` PHP files are ever loaded; there is no `functions.php` and no hook system (Phase 5 adds blocks/regions through a defined mechanism instead).
- Theme code is trusted (full PHP). Validation catches mistakes; it is not a sandbox.
- Activation is blocked for invalid themes, but a theme that becomes broken later never takes the site down (fallback).
- Theme settings, child-of-child themes and an in-browser theme editor are not part of this phase (editor: Phase 6).

## Known limitations / risks
- Not yet run on the real host: please walk through the checklist in `TESTING.md`.
- Opcache on some hosts can serve old theme PHP for a few seconds after you upload a change.
- Per-theme asset URLs are cache-busted by file time (child) or CMS version (Base). A Base asset edited without a version bump may be cached for up to a year by browsers that already have it.
- Public pages are still not cached (Phase 8). A request does: 2-4 indexed queries, 1 body file read, a handful of `stat()` calls for theme resolution.
- Not reviewed visually in a browser by me.
- Slug renames still leave no redirect (planned with SEO work).

## Phase 4 was split into three parts (progress log)

Phase 4 (SEO, tags, search, sitemap, RSS, media library, plus the CSS/JS asset library, hierarchical addresses and thumbnails the owner asked for) has too many items for one reviewable release, so it ships in three parts: 4A stores and manages the data, 4B fixes the address scheme and media previews, 4C shows everything to visitors and search engines.

**Design change recorded (requested by the project owner, 2026-10-22):** per-page custom CSS and JavaScript are no longer typed into each page. They are created separately in the admin (Admin > Code) as stylesheet/script assets, stored as files in `user/assets/`, and linked to pages/articles from the editor. Existing per-page code is converted automatically by migration 0007 (old columns are kept as a safety copy and no longer read).

### Phase 4A: content data and admin tools (release 0.4.0-phase4a): DONE, confirmed on the owner's host
| Item | Status |
|---|---|
| Migrator supports `.php` migrations beside `.sql` | built |
| Migrations 0003 media, 0004 tags, 0005 SEO columns + slug redirect table, 0006 code assets, 0007 convert old custom CSS/JS, 0008 search index table | built |
| Media library: upload (MIME-checked, random names), browse, alt text, delete-to-archive, integrity | built |
| Code assets (CSS/JS library): create/edit with version history, load order, link to pages/articles, served as cacheable files | built |
| Site settings screen: name, site URL, language, description, logo, default social image | built |
| Editor: tags field, SEO title, social image, linked stylesheets/scripts | built |
| Tags admin screen (list, rename, delete) | built |
| Search index maintained on every save (not yet visible to visitors) | built |
| Slug changes recorded as redirects (not yet honoured publicly) | built |
| Linked CSS/JS rendered on public pages; header shows logo | built |
| Tests, docs, packaging | built (tested here; not yet on the owner's host) |

### Phase 4B: addresses and thumbnails (release 0.4.1-phase4b): BUILT, tested here, awaiting the owner's tests on their host
Added at the owner's request after 4A was confirmed working (2026-10-24): hierarchical page addresses and media thumbnails.
| Item | Status |
|---|---|
| Hierarchical addresses: Book Articles live at `/parent/child/grandchild`; pages and articles stay at `/slug` | built |
| `content.path` column (unique), per-parent slug uniqueness, subtree re-pathing when a slug or parent changes | built |
| Migration 0009: compute paths for existing content, relax the global slug uniqueness, rename the redirect column | built |
| Old flat child addresses (and every renamed or moved address) answer with a 301 to the current address | built |
| Address shown in the editor and lists; `content_url()` and all links use the full path | built |
| Thumbnails created at upload (GD): grid and previews use them; fallbacks when GD is missing or the image is tiny/huge | built |
| "Generate thumbnails" for images uploaded before this release (time-limited steps); "Create again"; archive on delete; integrity | built |
| Tests, docs, packaging, upgrade test from the 4A release | built |

Tests (details in `TESTING.md`): 366 unit/database; 4B end-to-end 4 + 66; 4A end-to-end 4 + 92; upgrade-from-Phase-1 suite 3 + 89 + 69; Phase 1 regression 68; all passing on PHP 8.3 + MariaDB 10.11.

Decisions made in 4B
- The address is stored (`content.path`) rather than computed on each request, so lookups are one indexed query and the sitemap/search in 4C can use it directly.
- Uniqueness is per full address: the same slug can exist under different parents; reserved names (admin, search, tag, ...) are reserved only at the top level; trashed items keep their address until purged.
- Only Book Articles have parents, so only they get multi-segment addresses. Existing URLs of pages and articles do not change.
- A redirect is only served when the target is published and public.
- Thumbnails: 400px longest side, JPEG stays JPEG, PNG/GIF become PNG with transparency, WebP stays WebP; images already that small get none; thumbnails are never required (the grid falls back to the original).
- A path may be at most 500 characters (including parents); the editor refuses changes that would push any descendant over that.

Known limitations (4B)
- Needs MySQL 5.7+ / MariaDB 10.2+ for the 500-character unique key; the migration fails with a clear message otherwise.
- A page reached through a parent that is private or draft is still reachable at its full address (the parent's slug appears in the address); only the parent's content stays hidden.
- Links typed by hand into page bodies that point to old flat child addresses keep working through the redirect but are not rewritten.
- Thumbnails are not regenerated when GD settings change; use "Create again".
- Editing the slug of a parent with very many descendants updates them all in one request.
- Not yet run on the owner's host.

### Admin UI requests from the owner (numbered list, added after 4B)
| # | Request | Status |
|---|---|---|
| 1 | Replace select boxes for parent content and media selection with a lightweight AJAX autocomplete component | built in 0.4.1-phase4b1 (tested here; not yet confirmed by the owner) |
| 2.. | (the owner's message was numbered "1."; further items may follow) | not received |

Decisions for request 1: progressive enhancement (the field is still a numeric input when JavaScript is off); at most 12 results per request, 200 ms debounce; excludes the edited page and its descendants from the parent list; thumbnails shown for images; remaining `<select>` boxes (status, type, visibility) are short fixed lists and stay. Not changed: tag entry (typed text), code-asset links (checkboxes).

### Phase 4C: public output and discovery (release 0.4.2-phase4c): BUILT, tested here, awaiting the owner's tests on their host
Started 2026-10-26 on the owner's instruction to proceed. No database migration.
| Item | Status |
|---|---|
| Canonical URL, Open Graph and Twitter tags, meta robots, `<title>` override, description fallback chain, feed link (`$seo` theme variable, `meta.php` partial) | built |
| `/robots.txt` | built |
| `/sitemap.xml` (public, published, indexable items only; full hierarchical addresses; conditional GET) | built |
| `/feed.xml` RSS 2.0 (latest 20 public articles, categories from tags; conditional GET) | built |
| Tag pages `/tag/{slug}` and tag links on content (`$tags`, partial `tags`, template `tag`) | built |
| Search page `/search` and a search form in the header (template `search`, partial `search-form`) | built |
| Settings screen shows the sitemap, feed and robots addresses | built |
| Theme API additions documented in THEME_GUIDE.md | built |
| Tests, docs, packaging, upgrade test from the 4B1 release | built |

Tests (details in `TESTING.md`): 442 unit/database; 38 browser-behaviour; 4C end-to-end 2 + 70; 4B 4 + 96; 4A 4 + 92; upgrade-from-Phase-1 3 + 89 + 69; Phase 1 regression 68; all passing.

Decisions made in 4C
- One rule decides what search engines and feed readers may see: published, public, not trashed, not marked "do not index". The sitemap and the feed use it; private pages additionally expose nothing to link previews.
- Sitemap and feed are never generated for a guessed host: without a configured site URL they answer 503 with an explanation (robots.txt still works).
- The feed carries excerpts, not full article text (keeps it small and avoids republishing bodies by default).
- `/search` is disallowed in robots.txt and marked noindex; tag pages are indexable.
- ETags let crawlers and readers skip unchanged documents (the sitemap ETag includes a checksum of the addresses so a rename is always noticed).

Known limitations (4C)
- The sitemap is a single file limited to 50,000 URLs (no sitemap index); the feed has no full-text option; no JSON-LD structured data and no hreflang.
- Search has no request throttling and the substring (LIKE) path is slower on very large sites; it needs at least 2 characters.
- Pages marked "do not index" still appear in the site's own tag pages and search; they are only withheld from the sitemap, the feed and search engines.
- Child themes that override `header.php` or `meta.php` do not get the search box or the new tags until they copy them from the base theme.
- Social images use the original upload (not a resized copy), so very large images make slow previews.
- Not yet run on the owner's host.

### Phase 4A: tests and decisions
Tests (details in `TESTING.md`): 316 unit/database, 4A end-to-end 4 + 92, upgrade-from-Phase-1 suite 3 + 89 + 69, Phase 1 regression 68; all passing on PHP 8.3 + MariaDB 10.11.

Decisions made in 4A
- Code assets are files (not database text): cacheable by browsers, versioned by `?v=`, editable with proper history, and no inline code means no dependence on CSP nonces when page caching arrives in Phase 8. Per-page code is removed from the editor; the old columns stay as an unread safety copy (a later migration may drop them after you confirm the conversion).
- Upload types: JPG, PNG, GIF, WebP, PDF. SVG is refused because it can carry scripts. Type is read from file content. Images are validated by header and dimensions, not fully decoded or re-encoded (a truncated image with a valid header is accepted; it is harmless but shows broken).
- Default upload limit is the smallest of 5 MB, `upload_max_filesize` and `post_max_size`; the screen shows the effective value.
- Tags are typed as comma-separated names in the editor (max 15 per item, 60 characters each); a tag's web address stays fixed when renamed; there is no tag merge.
- Search: InnoDB FULLTEXT for plain Latin words, LIKE for everything else (Hindi, short words, stopwords). The Hindi/Devanagari tokenizer limitation of MySQL FULLTEXT is why.
- Social-image and logo pickers list the newest 200 images; a searchable picker can come later.

Known limitations (4A)
- Not yet run on the owner's host.
- Deleting a media file does not scan page bodies for embedded copies of its address; the screen warns that such images will break.
- The search index of a very large site may need several presses of "Rebuild search index" after the update.
- Slug redirects are recorded but old addresses still return 404 until 4B (4B makes them redirect).
- Linked scripts run with full page access: only link code you trust.

## Scope change (owner, 2026-10-30): blocks and regions are dropped
The owner removed blocks and regions (spec section 6, and "blocks" in the theme guide and cache-invalidation lists) from this CMS's to-do list. Nothing for them was built, so nothing needed undoing. Themes therefore have no regions, no block templates and no `regions` entry in `theme.json`. Menus were only ever planned as a block type, so they are dropped with it (navigation stays a theme/partial matter).

Remaining phases from the original plan
| Phase | Content | Status |
|---|---|---|
| 5 (reduced) | Global settings that were still missing | built (below), awaiting the owner's tests on their host |
| 6 | Theme editor, validation and revisions (edit child-theme files safely in the admin) | not started |
| 7 | Backup and restore, secure core updater | not started |
| 8 | Security review, performance (caching with invalidation), observability and maintenance tools, installation testing, final documentation | not started |

### Phase 5 (reduced): global settings (release 0.5.0-phase5): BUILT, tested here, awaiting the owner's tests on their host
Most "global site settings" already existed from 4A (name, URL, language, description, logo, social image). This release adds the rest.
| Item | Status |
|---|---|
| Items per page for home, tag and search lists (5 to 50) | built |
| Display time zone (default UTC; public and admin dates use it) and date format choices | built |
| Footer text (plain text, `{year}` placeholder) shown by the base theme | built |
| Maintenance mode: visitors get a 503 page with your message; signed-in administrators can still browse; assets and admin keep working | built |
| Settings screen reorganised (Site, Display, Maintenance cards); dashboard banner while maintenance is on | built |
| Theme API: `$site['footer']`, `format_date($utc, $format = null)`, `iso_date($utc)` | built |
| Tests, docs, packaging, upgrade test from the 4C release | built |

Tests (details in `TESTING.md`): 472 unit/database; Phase 5 end-to-end 2 + 51; 4C 2 + 70; 4B 4 + 96; 4A 4 + 92; upgrade-from-Phase-1 3 + 89 + 69; Phase 1 regression 68; 38 browser-behaviour; all passing.

Decisions made in Phase 5
- Storage stays UTC everywhere; only display is converted (RSS and the sitemap keep their standard UTC formats). The same zone applies to the admin lists so an editor sees the dates visitors see.
- Maintenance mode is a hard switch with one message and a signed-in administrator bypass (no per-IP allow-list, no scheduled windows). It applies to every public route including feed, sitemap and robots.txt (so crawlers see a temporary 503, not a missing site) but not to the admin area or the static asset route.
- Settings are validated strictly on save and also defended on read (a corrupted stored value falls back to the default).
- Footer text is plain text (escaped), not HTML, to keep it safe and simple.

Known limitations (Phase 5)
- One time zone for the whole site; no per-user zone. The "UTC" labels in the revision history screens are intentionally raw UTC.
- The footer text has no HTML or link support; menus, blocks and regions are not part of this CMS.
- Maintenance mode has no countdown, no allow-list of visitor IPs and no automatic end time.
- Not yet run on the owner's host.

## Next exact task
Wait for the owner's confirmation that Phase 5 (and the earlier 4B, 4B1, 4C releases) work on their host, then start Phase 6 (theme editor, validation and revisions). Pending: further numbered admin UI requests from the owner (only #1 received).
