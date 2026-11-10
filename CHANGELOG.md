# Changelog

## 0.5.0-phase5 (2026-10-31)
Phase 5, reduced: **blocks and regions were removed from the project plan at the owner's request**; this release adds the global settings that were still missing. No database changes.
- Site settings gained a Display card: items per page (5 to 50) for home, tag and search lists; display time zone and date format (dates stay UTC in storage, feeds and the sitemap); footer text with a `{year}` placeholder.
- Maintenance mode with an optional message: visitors, crawlers and feed readers get a 503 "back soon" page; a signed-in administrator can still browse; the admin area and static assets keep working; the dashboard shows a banner while it is on.
- Dates in pages and admin lists follow the chosen zone; `<time datetime>` uses the same zone so the machine date matches the displayed one. New helper `iso_date()`; `format_date()` gained an optional format argument.
- After a rejected value, the settings form shows what you typed.

## 0.4.2-phase4c (2026-10-26)
Third and last part of Phase 4 (discovery). No database changes.
- SEO and sharing tags: canonical address, Open Graph, Twitter cards, robots directive, SEO title override, description and image fallbacks, RSS link. Private pages and pages marked "do not index" expose nothing to link previews. New theme variable `$seo`.
- `/robots.txt`, `/sitemap.xml` (full hierarchical addresses, conditional GET) and `/feed.xml` (RSS 2.0, latest 20 public articles with tags as categories, conditional GET).
- Tag pages `/tag/{slug}` and tag links on pages and articles (private pages show none; tags with no public item are 404).
- Site search `/search` with a search box in the header; results use full addresses; never indexed by search engines.
- Site settings lists the sitemap, feed and robots addresses.
- Theme API: templates `tag`, `search`; partials `tags`, `search-form`; variables `$seo`, `$tags`; function `tag_url()`; `pagination` partial accepts extra query parameters.

## 0.4.1-phase4b1 (2026-10-25)
Admin UI request #1 (code and styling only; no database changes).
- **Changed:** the select boxes for the parent page (editor), the social preview image (editor) and the logo and default social image (Site settings) are replaced by a lightweight AJAX autocomplete with image previews. Pages that used to load up to 2000 books or 200 images into a drop-down now fetch at most 12 matches on demand.
- New JSON endpoints `/admin/lookup/content-parent` and `/admin/lookup/media-image` (administrator only, never cached); new admin assets `autocomplete.js` and `admin.css`; the admin Content Security Policy allows `connect-src 'self'`.
- Without JavaScript the pickers fall back to a numeric id field; the server validates every submitted id as before.

## 0.4.1-phase4b (2026-10-24)
Second part of Phase 4 (addresses and thumbnails). The visitor-facing discovery part (SEO tags, tag pages, search, sitemap, RSS, robots.txt) is 0.4.2-phase4c.
- **Changed:** Book Articles now live at hierarchical addresses (`/parent/child/grandchild`); pages, articles and top-level books stay at `/slug`. The same slug may be used under different parents.
- Old addresses (existing flat child addresses, renamed slugs, moved pages) redirect with 301 to the current address; a live address always wins; redirects never reveal private or draft pages.
- Renaming a parent or moving a page re-addresses its whole subtree in one transaction.
- Media thumbnails: created at upload with PHP GD, used by the media grid, "Generate thumbnails" for older uploads, "Create again" per image, archived with the original on delete, covered by the integrity report.
- Migrations 0009 (addresses) and 0010 (thumbnail columns).
- Theme API: content rows carry `path`; `content_url()` uses it.

## 0.4.0-phase4a (2026-10-23)
First half of Phase 4 (content data and admin tools). The visitor-facing half (SEO tags, tag pages, search, sitemap, RSS, robots.txt, old-slug redirects) is 0.4.1-phase4b.
- **Changed:** per-page custom CSS/JavaScript is replaced by reusable *code assets* created in Admin > Code and linked to pages and articles. Files are served as cacheable static files; each has a version history. Existing per-page code is converted by migration 0007 (old columns kept, unused).
- Media library: uploads checked by content type, random file names, alt text, delete to archive, integrity report.
- Site settings screen: name, public URL, language, description, logo, default social image. The installer records the site URL.
- Editor: tags, SEO title, social image, linked stylesheets/scripts. Tags admin screen.
- Search index maintained on every save; slug changes recorded as redirects (both used publicly from 4B).
- Migrations can now be PHP files; migrations 0003 to 0008.
- Theme API: new `$site` fields, `$itemAssets`; `$customCss`/`$customJs` deprecated (always empty). Header shows the logo.

## 0.3.0-phase3 (2026-10-21)
- Theme engine: Base theme + child themes in `user/themes/`, resolution child -> base -> built-in fallback.
- Template selection by `{type}-{slug}`, `{type}`, `content`; partials, layout and a documented theme API (`THEME_GUIDE.md`).
- `get_theme_url()`, global and template-specific CSS/JS assets, Base assets served at `/assets/base/` with long-lived caching.
- Themes admin screen: validate, activate, switch back, create child-theme skeleton. Validation includes PHP syntax checks.
- Runtime fallback to Base if a child theme fails; Base files rebuilt as layout + partials + templates + assets.
- Added `CODING_STANDARDS.md` (coding standard and naming conventions).
- No database migration. Test scripts renamed to `e2e_upgrade.*`.

## 0.2.0-phase2 (2026-10-21)
- Content model: pages, articles, hierarchical book articles; draft/published; trash; private pages with per-page password.
- Flat-file bodies with atomic writes, revisions (newest 20), archived purge, integrity report.
- Allowlist HTML sanitizer, Unicode-safe unique slugs with reserved names.
- Admin: content list with filters/pagination/bulk actions, editor, preview, revisions, book ordering.
- Public site: home (latest articles), `/slug` pages, canonical redirect, themed 404, minimal base theme, CSP with nonce.
- In-admin update step that applies database migrations after new files are uploaded.
- Migration `0002_content`. Removed the Phase 1 placeholder home view.

## 0.1.0-phase1 (2026-10-21)
- Initial skeleton: front controller, config, PDO layer, migrations, installer.
- Single-admin authentication with TOTP 2FA, recovery codes, throttling, CSRF, security headers, logging.
