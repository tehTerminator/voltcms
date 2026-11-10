# Testing

## Unit + database tests (no framework)
```
php tests/run.php                                   # unit tests only
CMS_TEST_DB='host|port|dbname|user|pass' php tests/run.php   # also runs DB tests (empty throwaway DB, prefix t_)
```
Covers: TOTP (RFC 6238 vectors, drift, replay), AES-GCM round trip and tamper detection, path normalisation and traversal,
router, migration splitting/idempotence, settings upsert, rate limiter.

## HTTP end-to-end (`tests/e2e_http.py`)
Installs into a scratch copy and exercises installer, access blocking, CSRF, sign-in, session regeneration, 2FA setup/login,
recovery codes, replay, password change, lockout, logging. See the header of the file for setup.

## Last results (2026-10-31, PHP 8.3.6 with GD, MariaDB 10.11, PHP built-in server, Node with jsdom)
- Unit + database (`tests/run.php`): 472 passed, 0 failed (adds time zones and date formats incl. daylight saving, global settings validation, footer text, zone-aware theme dates).
- Phase 5 HTTP end-to-end (`tests/e2e_phase5.sh`, from the 4C release): 2 + 51 passed, 0 failed.
- Earlier suites still pass: 4C end-to-end 2 + 70, 4B 4 + 96, 4A 4 + 92, upgrade from Phase 1 3 + 89 + 69, Phase 1 regression 68, autocomplete browser tests 38.

## Not covered yet
Real browsers (the autocomplete script is tested in a simulated browser only; the unsaved-changes warning and confirm dialogs are not tested at all), visual review, real PHP 8.0, Apache/.htaccess behaviour (the dev router mirrors the rules but is not Apache), MySQL (not MariaDB), subfolder installs end to end,
HTTPS cookie `Secure` flag, concurrent logins.

## Manual checklist after uploading to your host
- [ ] `install.php` runs; afterwards it is gone or shows "Installer locked".
- [ ] `https://site/user/db.php`, `/user/config/app.php`, `/core/engine/Config.php`, `/user/logs/app.log` all return 403/404 (not a blank 200 or source).
- [ ] Sign-in works; wrong password shows the generic message; 6 failures show the lockout message.
- [ ] 2FA enrol, sign out, sign in with code, sign in with a recovery code.
- [ ] Session cookie shows HttpOnly, SameSite=Lax and (on HTTPS) Secure in browser dev tools.

## Manual checklist for Phase 2 (on your host)
- [ ] Upgrade steps in `INSTALLATION.md` work; site shows "updating" until you apply; afterwards the dashboard shows 0.2.0-phase2.
- [ ] Create an article (draft). It is 404 at its URL but Preview shows it. Publish it: visible at `/slug` and on the home page.
- [ ] Paste HTML with `<script>` and `onclick=`; after saving, view source of the page: both are gone, Bootstrap classes remain.
- [ ] Hindi title: slug is created in Hindi and the page opens (also when copied into another browser).
- [ ] Create a Book Article with two children: breadcrumb, "In this section" list, previous/next links, move up/down order.
- [ ] Private page: logged-out browser asks for the password; wrong password rejected; right password shows the page; page is absent from the home list.
- [ ] Edit an article twice; Revisions lists earlier versions; Restore works.
- [ ] Trash an item, restore it; trash and permanently delete another; confirm a folder appears in `user/backups/deleted/`.
- [ ] Integrity check shows "every record has its body file" on a healthy site.
- [ ] Editing a form then clicking a link away asks "leave site?"; "Move to trash" asks to confirm (needs JavaScript; admin.js loads).
- [ ] `https://site/user/content/posts/1.html` is blocked (403/404).

## Manual checklist for Phase 3 (on your host)
- [ ] Upgrade steps in `INSTALLATION.md` work; dashboard shows 0.3.0-phase3 and the theme name.
- [ ] Public pages look the same as before the upgrade; view source shows `/assets/base/css/theme.css?v=...` and the page is styled (the asset route works on your host).
- [ ] Browser dev tools: `theme.css` returns 200 once, then comes from cache (or 304) on reload.
- [ ] Themes: create a child theme `my-site`; it appears with an Activate button; activate it; the front page still looks right.
- [ ] Add `user/themes/my-site/partials/footer.php` with your own text; reload a page: new footer, everything else unchanged.
- [ ] Add `assets/css/templates/article.css` with an obvious rule; it applies on an article and not on a page.
- [ ] Put a deliberate syntax error into your child `templates/article.php`: the article page still opens (Base template), `user/logs/app.log` has a "Theme render failed" line, and the Themes screen shows the syntax error. Fix it and reload.
- [ ] Rename or delete the child theme's folder: the site keeps working with Base and the Themes screen warns.
- [ ] `https://site/user/themes/my-site/templates/article.php` is not downloadable (403/404).
- [ ] Switch back to Base from the Themes screen.

## Manual checklist for Phase 4A (on your host)
- [ ] Upgrade steps in `INSTALLATION.md` work. The dashboard shows 0.4.0-phase4a.
- [ ] Code: pages that had custom CSS/JS look the same; the code now appears as "Imported: ..." items; view source shows `<link>`/`<script src>` instead of inline blocks.
- [ ] Site settings: site URL and language saved; the dashboard URL notice disappears.
- [ ] Media: upload a JPG and a PNG; both show up; open one, copy the HTML snippet, paste it into a page, save, view the page (image shows).
- [ ] Media: try uploading a `.php` file renamed to `.jpg` (refused) and a file larger than your host's limit (clear message).
- [ ] Media: set an image as the site logo; it appears in the public header. Deleting it is refused until you unset it.
- [ ] Code: create a stylesheet, link it from a page; the page uses it and other pages do not. Edit the code, reload: new styles appear (the `?v=` changes). Restore an earlier version.
- [ ] Tags: add "law, notes" to an article; Tags screen shows them; rename one; delete one.
- [ ] Editor: SEO title and social image can be set and are shown again after saving.
- [ ] Settings: *Rebuild search index* completes with a success message.
- [ ] Integrity check page says everything is consistent.

## Manual checklist for Phase 4B (on your host)
- [ ] Upgrade steps in `INSTALLATION.md` work. The dashboard shows 0.4.1-phase4b.
- [ ] A top-level book, its child and its grandchild open at `/book`, `/book/child`, `/book/child/grandchild`.
- [ ] The old flat address of an existing child page (`/child`) redirects to the new address (check the address bar changes).
- [ ] Breadcrumbs, the "In this section" list and previous/next links use the new addresses.
- [ ] Create two books and give each a child called "Introduction": both work (`/book-a/introduction`, `/book-b/introduction`).
- [ ] Rename a parent's slug: its children's addresses change and the old addresses still redirect.
- [ ] Move a child under another book: the new address works, the old one redirects.
- [ ] Hindi parent and child titles give a working Hindi address.
- [ ] A private child page asks for the password at its full address and returns there after unlocking.
- [ ] Media: press "Generate thumbnails"; the grid becomes visibly faster and shows small images; upload a new photo and see its thumbnail appear at once; a phone photo is not sideways.
- [ ] Open one thumbnail address directly: it is a small image (a few KB, not the original).

## Manual checklist for the autocomplete pickers (0.4.1-phase4b1, on your host)
- [ ] Edit a Book Article: the Parent field is a search box, not a drop-down. Click it: recently changed books appear. Type part of a title or of an address: the list narrows after a short pause.
- [ ] Arrow keys move through the list, Enter chooses, Escape closes, Tab leaves. Enter never saves the page while the list is open.
- [ ] Choose a parent, save, reopen: the parent's name is shown. Press the x to clear it (top level), save, reopen: empty.
- [ ] The page you are editing and its own children are not offered as parents.
- [ ] Social preview image (editor) and logo / default social image (Site settings): searching shows small image previews; the chosen image shows its preview next to the box.
- [ ] Leaving a page with a changed picker asks "leave site?" (unsaved-changes warning).
- [ ] With the network disconnected (or the dev-tools network blocked), typing shows "Could not load results" instead of freezing.
- [ ] A page with `<` or quotes in its title shows that title as plain text in the list.

## Manual checklist for Phase 4C (on your host)
- [ ] Site settings: the site URL is right; the new "Addresses for search engines and readers" card shows three links that open.
- [ ] `/robots.txt` shows `Disallow: /admin/`, `Disallow: /search` and a `Sitemap:` line with your domain.
- [ ] `/sitemap.xml` opens as XML; it lists your public pages and articles (child pages with their full `/parent/child` addresses) and does not list drafts, private pages or pages marked "do not index".
- [ ] `/feed.xml` opens; add it to a feed reader: your newest articles appear with their excerpts and tags.
- [ ] View source of an article: `<link rel="canonical">`, `og:title`, `og:description`, `og:image`, `twitter:card` are present and correct. Paste an article link into a Facebook/WhatsApp/Twitter debugger or message: the preview shows the title, description and image.
- [ ] Set an SEO title on an article: the browser tab and the preview use it.
- [ ] A page marked "do not index" has `<meta name="robots" content="noindex, nofollow">` and no social tags; a private page (after entering its password) has no social tags and no tag links.
- [ ] Tags: open a tag link from an article; the tag page lists its items and paginates; a tag used only on drafts or private pages gives "not found".
- [ ] Search: use the search box in the header; try an English word, a Hindi word and a nonsense word; private and draft content never appears.
- [ ] Google Search Console (or Bing Webmaster Tools): submit the sitemap and check it is accepted.

## Manual checklist for Phase 5 (on your host)
- [ ] Site settings: the new **Display** and **Maintenance mode** cards are there.
- [ ] Set the time zone to `Asia/Kolkata`: an article published late in the evening shows the correct local date (and the admin content list too).
- [ ] Change the date format; article pages and lists follow it.
- [ ] Set items per page to 5: the home page, a tag page and a search show 5 items and a pager.
- [ ] Footer text `(c) {year} Your Name`: the footer shows the current year; leave it empty for the default line.
- [ ] Turn maintenance mode on, then open the site in a private/incognito window: you see the "back soon" page. In your normal signed-in window the site still opens. The dashboard shows a banner. Turn it off and check the site returns.
- [ ] Try entering `abc` as items per page or `Mars/Base` as the time zone: a clear message appears and nothing is lost from the form.
