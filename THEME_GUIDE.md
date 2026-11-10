# Theme guide

A site always uses the **Base** theme (`core/themes/base/`) or a **child theme** (`user/themes/{name}/`) that sits on top of it.
A child theme contains only the files it wants to change; everything else comes from Base. Child themes live in `user/`, so CMS updates never touch them.

## Quick start: change the footer and add your own CSS
1. Admin > **Themes** > *Create a child theme* (name "My site", folder `my-site`). This creates `user/themes/my-site/` with `theme.json`, `assets/css/child.css`, and empty `templates/` and `partials/` folders.
2. Create `user/themes/my-site/partials/footer.php` (FTP or file manager):
   ```php
   <footer class="container py-4 text-secondary small border-top">&copy; <?= e(gmdate('Y')) ?> <?= e($site['name']) ?>. All rights reserved.</footer>
   ```
3. Put your CSS in `user/themes/my-site/assets/css/child.css`.
4. Admin > Themes > **Activate**. The CMS checks the theme first and tells you what is wrong if it cannot be activated.

## Folder layout
```
my-site/
  theme.json                 required: metadata and global assets
  layout.php                 optional: the page shell around every template
  templates/                 optional: home.php, page.php, article.php, book.php, content.php, private.php, 404.php, error.php, page-about.php ...
  partials/                  optional: header.php, footer.php, meta.php, vendor-css.php ... (see list below)
  assets/css|js|img|fonts/   static files
```

## theme.json
```json
{
  "name": "My site",
  "version": "1.0.0",
  "author": "Your name",
  "description": "Short description",
  "parent": "base",
  "requires": { "cms": ">=0.3.0" },
  "assets": { "css": ["css/child.css"], "js": ["js/child.js"] }
}
```
| Key | Rules |
|---|---|
| `name` | required, 1-60 characters (shown in the admin) |
| `version` | required, like `1.0.0` |
| `author`, `description` | optional text (80 / 300 characters) |
| `parent` | omit or `"base"`. Only Base can be a parent |
| `requires.cms` | optional, like `">=0.3.0"` (operators `>=`, `>`, `<=`, `<`, `=`). Activation is refused when the running CMS version does not satisfy it |
| `assets.css` / `assets.js` | paths **inside `assets/`**, loaded on every page after Base's own files. A listed file may be one the child does not have if Base has it |

The folder name (`my-site`) is the theme's identifier: lowercase letters, digits and hyphens, max 39 characters, not `base`.

## How files are found (resolution order)
For every file the CMS asks for, in this order:
1. `user/themes/{active}/{path}`
2. `core/themes/base/{path}`
3. a built-in safe page (only if the Base files themselves are unusable)

So you override a file by creating the same path in your child theme. This works for `layout.php`, `templates/*.php`, `partials/*.php` and `assets/**`.
Symbolic links that point outside the theme folder are ignored.

## Which template is used
For a page, article or book article the CMS tries, most specific first:
1. `templates/{type}-{slug}.php` (for example `page-about.php`, `article-my-first-post.php`; only for slugs made of `a-z 0-9 _ -`)
2. `templates/{type}.php` (`page`, `article`, `book`)
3. `templates/content.php`

Other screens use `home`, `tag` (a tag's page), `search`, `private` (password form), `404` and `error`.
The first candidate found (child first, then Base) is rendered, and its output is passed to `layout.php` as `$content`.

## Variables
Available in the layout, every template and every partial (a partial can add or override variables with `partial('name', [...])`):

| Variable | Meaning |
|---|---|
| `$site` | `['name', 'lang', 'url' (public site URL or ''), 'description' (default description), 'footer' (footer text from Site settings, '' if none), 'logo' (URL or ''), 'logo_alt', 'social_image' (URL or '')]` from Admin > Site settings |
| `$title` | page title (without the site name) |
| `$metaDescription` | meta description text ('' if none) |
| `$noindex` | true when search engines should not index the page (older variable; prefer `$seo['robots']`) |
| `$seo` | `['title' (SEO title from the editor or ''), 'description', 'canonical' (absolute URL or ''), 'robots' ('noindex, nofollow' ... or ''), 'og' (property => content), 'twitter' (name => content), 'feed' (absolute feed URL or '')]`. Plain text, escape when printing. Printed by `meta.php` |
| `$tags` | tags of the page or article (`name`, `slug`); an empty list for private pages and on list pages |
| `$preview` | true in the admin preview |
| `$itemAssets` | `['css' => [URLs], 'js' => [URLs]]`: the stylesheets and scripts linked to this page or article in the editor (Admin > Code), in load order. Printed by `item-css.php` and `item-js.php` |
| `$nonce` | CSP nonce for the current request. Only needed for inline `<style>`/`<script>` blocks in your own theme files |
| `$customCss`, `$customJs` | **Deprecated since 0.4.0, always `''`.** Per-page code is now a linked asset (see `$itemAssets`). They are still defined so older themes keep working |
| `$template` | name of the template in use (`article`, `page-about` ...) |
| `$theme` | `['name','title','version','author','is_child']` of the active theme |
| `$content` | (layout only) the rendered template HTML |

Per template:

| Template | Extra variables |
|---|---|
| `home` | `$articles` (rows: `id, slug, title, excerpt, published_at`), `$page`, `$pages` |
| `page`, `article`, `book`, `content` | `$item` (the content row: `id, type, parent_id, slug, title, status, visibility, excerpt, meta_description, noindex, published_at, created_at, updated_at`, plus internal fields you should ignore), `$body` (already sanitised HTML, print it raw) |
| `book` | also `$crumbs` (public ancestors, root first), `$children` (public child pages in order), `$neighbors` (`['prev' => row|null, 'next' => row|null]`) |
| `tag` | `$tag` (`id, name, slug`), `$items` (rows: `type, path, slug, title, excerpt, published_at`), `$total`, `$page`, `$pages` |
| `search` | `$q` (the query), `$searched` (true when the query was long enough), `$results` (same row shape as `$items`), `$total`, `$page`, `$pages` |
| `private` | `$item`, `$error` (message or '') |
| `error` | `$code`, `$message` |
| `404` | none |

## Functions
| Function | Purpose |
|---|---|
| `e($text)` | HTML-escape text. **Use it on everything that is not already sanitised HTML** (`$body` is the only raw value) |
| `url('/path')` | site URL (works in a subfolder install) |
| `tag_url($tag)` | URL of a tag page (`/tag/{slug}`) |
| `content_url($row)` | URL of a content row: its full address (`/parent/child/grandchild` for Book Articles). Always use this; never build URLs from `$row['slug']` |
| `format_date($utc, $format = null)` | formats a stored UTC timestamp in the **time zone and date format chosen in Site settings** (default `21 Oct 2026`); pass a PHP `date()` format, e.g. `'H:i'`, to override the format |
| `iso_date($utc)` | the same date as `Y-m-d` in the site's zone, for `<time datetime="...">` (so the machine date agrees with the displayed one) |
| `csrf_field()` | hidden CSRF input for forms (private-page form uses it) |
| `partial('name', $vars = [])` | include `partials/name.php` (child first, then Base) |
| `get_theme_url('css/x.css')` | URL of an asset (path inside `assets/`); child file wins over Base's; `''` if neither has it. With no argument it returns the active theme's assets folder |
| `theme_head_assets()` | `<link>` tags for the global and template-specific CSS (used by the layout) |
| `theme_footer_assets()` | `<script defer>` tags for the global and template-specific JS |
| `theme_info()` | same as `$theme` |

## Partials shipped by Base
`meta` (description, robots, canonical, Open Graph, Twitter, feed link), `vendor-css`, `vendor-js`, `item-css`, `item-js`, `preview-banner`, `header` (logo, site name, search box), `footer`, `pagination` (variables `page`, `pages`, `base`, optional `query`), `breadcrumbs`, `book-nav`, `tags` (tag links), `search-form`.
Override just the one you need. For example, `vendor-css.php` is the only place Bootstrap's CSS is linked: override it to self-host Bootstrap or use another framework.
`item-css.php` and `item-js.php` print the linked stylesheets and scripts from `$itemAssets`. Keep them (or print the same lists yourself) or linked code will not load. `header.php` shows `$site['logo']` when a logo is set in Site settings.

## Assets
* **Global:** files named in `theme.json` (Base's first, then the child's) load on every page.
* **Template-specific:** `assets/css/templates/{template}.css` and `assets/js/templates/{template}.js` load automatically only on pages rendered with that template (for example `templates/article.css` on articles, or `templates/page-about.css` on that slug template). Either theme may provide them. No `theme.json` entry is needed.
* **Overriding a Base asset:** create the same path in the child (for example `assets/css/theme.css`). The child's file replaces Base's.
* **URLs and caching:** child assets are static files at `/user/themes/{name}/assets/...?v={file time}`; Base assets are served by the CMS at `/assets/base/...?v={CMS version}` with a one-year cache that changes whenever the version or file changes.
* Allowed asset types: css, js, png, jpg, jpeg, gif, webp, svg, ico, woff, woff2, txt (and `json`, `md`, `html` files may exist in the theme but are not served by the Base route).
* Scripts must be files. Inline `<script>` and inline event handlers are blocked by the page's Content Security Policy; styles may use inline `style=""` attributes but not inline `<style>` blocks (except the nonce-carrying per-item block).

## Validation (before activation)
A child theme can be activated only if: `theme.json` is valid and complete; the folder name is valid; `parent` is `base` or omitted; `requires.cms` is satisfied; listed assets exist (in the theme or Base); every PHP file parses; there are no symbolic links, no hidden files and no disallowed file types; it has at most 500 files, none over 2 MB.
Warnings (not blocking): PHP files that are never loaded (only `layout.php`, `templates/*` and `partials/*` are used; there is no `functions.php`).

## When something goes wrong
* A child template or layout throws an error, or contains a PHP syntax error: the visitor gets the page rendered by the Base theme instead, and the error is written to `user/logs/app.log`. The Themes screen shows syntax errors in red.
* Base's own files are damaged: a plain built-in page shows the title and content.
* The active theme's folder is deleted or its name is invalid: the Base theme is used automatically and the Themes screen warns you.
* `theme.json` is damaged after activation: templates still render; the manifest's assets stop loading until it is fixed.
* To go back at any time: Admin > Themes > *Switch back to Base*.

## Security notes
* Theme PHP files are trusted code with full PHP access. Install only themes you wrote or trust, and keep write access to `user/themes/` limited to you.
* Always escape with `e()`. Do not print `$item['view_password_hash']`, `custom_css` or `custom_js` yourself.
* Theme PHP files are never served directly (`user/.htaccess` and the router block them).

## What themes cannot do yet
No `functions.php` or hooks, no regions or blocks (dropped from the project plan), no in-browser theme editor (planned, Phase 6), no theme settings screen, and no child-of-child themes.

## Global display settings (Admin > Site settings)
* **Items per page** (5 to 50) controls the home, tag and search lists; your templates receive `$page` and `$pages` as before.
* **Time zone and date format:** dates are stored in UTC; `format_date()` and `iso_date()` convert for display. Always print dates through them.
* **Footer text:** `partials/footer.php` prints `$site['footer']` (`{year}` is replaced by the current year) or, when empty, a copyright line with the site name. It is escaped; there is no HTML in it.
* **Maintenance mode:** while it is on, visitors get your `templates/error.php` with code 503 and the message from Site settings (escaped); signed-in administrators still see the normal site. Style the `error` template if you want a branded page.

## SEO and sharing tags
`partials/meta.php` prints the search-engine and sharing tags from `$seo`: description, robots, the canonical address, Open Graph (`og:*`) and Twitter card (`twitter:*`) tags and a link to the RSS feed. Rules the CMS applies before your theme sees the data:
* The canonical address is the page's full address on the site URL set in Site settings. Without a site URL there are no canonical, `og:url` or image tags and no feed link.
* Pages marked "do not index" get a robots directive and no canonical or social tags. **Private pages get nothing at all** (no description, canonical or social tags), so link previews reveal nothing.
* Description: the page's meta description, else its excerpt, else the site description. Social image: the page's own image, else the site default image.
* An SEO title entered in the editor replaces the page title in `<title>` (the default layout does this) and in `og:title`.
Overriding `meta.php` is fine; the older `$metaDescription` and `$noindex` variables keep working for themes written before 0.4.2. Sitemap (`/sitemap.xml`), RSS feed (`/feed.xml`) and `/robots.txt` are generated by the CMS and need no theme files.

## Tags and search
Tag links appear on pages and articles through `partials/tags.php`; `/tag/{slug}` is rendered with `templates/tag.php` and lists published, public items of any type, newest first, 10 per page. A tag with no public item answers 404 so private tags never leak. `/search?q=` is rendered with `templates/search.php` (never indexed by search engines); the search box is `partials/search-form.php`, included by `header.php`.

## Per-page CSS and JavaScript (Admin > Code)
Page-specific code is not typed into pages any more. Create a stylesheet or script once in **Admin > Code**, then tick it in the editor of every page or article that needs it. Each is a separate file in `user/assets/` that browsers cache; the link changes automatically when you edit the code. For site-wide styling use your theme's CSS instead. For one template type, use `assets/css/templates/{template}.css`.
Per-page code written before 0.4.0 is converted into code assets automatically when you apply the update.

## Addresses (URLs) of content
Pages, articles and top-level books live at `/slug`. A Book Article lives under its parent: `/parent/child/grandchild`. Every content row passed to a template (`$item`, `$crumbs`, `$children`, `$neighbors`, `$articles`) carries `path` (the address without a leading slash) as well as `slug` (its last segment); `content_url()` uses `path`. Old addresses (after a rename or move) redirect automatically.

## Stability of this API
Template names, partial names, variable names and helper functions listed here are the public theme API. They will only be removed after a deprecation period. New variables may be added; ignore ones you do not know.
