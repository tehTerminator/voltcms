# Installation (shared hosting, PHP 8.0+, MySQL)

1. In your hosting panel create an **empty MySQL database** and a user with all privileges on it. Note host, name, user, password.
2. Upload the contents of this folder to your web root (or a subfolder). Keep `.htaccess` (it is a hidden file; enable "show hidden files" in your FTP client / file manager).
3. Open `https://your-domain/install.php` **immediately** and complete the form. Use a table prefix such as `cms_`.
4. On success the installer locks itself (`user/config/installed.lock`) and tries to delete `install.php`. If it could not, delete it yourself.
5. Sign in at `https://your-domain/admin`, then open **Security** and turn on two-factor authentication. Save the recovery codes.
6. Optional: after HTTPS works, uncomment the HTTPS redirect lines in `.htaccess`.

## Requirements the installer checks
PHP >= 8.0 with `pdo_mysql`, `mbstring`, `openssl` (AES-256-GCM), `json`, `fileinfo`, `dom`; writable `user/` subfolders. `zip` and `gd` are recommended (backups, image checks in later phases).

## Folder permissions
Folders 755, files 644 is normal. The installer writes `user/db.php` and `user/config/app.php` with mode 640.

## Nginx-only hosts
`.htaccess` is ignored. Add rules equivalent to: send non-file requests to `index.php`; deny `/core/`, `/user/config/`, `/user/content/`, `/user/backups/`, `/user/cache/`, `/user/logs/`, `/user/db.php`, dotfiles, and PHP execution under `/user/`.

## Move secrets above the web root (optional, recommended where possible)
Phase 1 keeps `user/db.php` inside the web root protected by deny rules and an `exit` guard. If your host lets you place files above `public_html`, a later phase will add a one-line path setting for this.

## Locked out?
Lost both password and recovery codes: restore access by deleting the `totp_enabled` flag in the database (`UPDATE cms_users SET totp_enabled=0, totp_secret_enc=NULL`) via phpMyAdmin, then sign in with your password. A password-reset tool is planned for a later phase.

## Developing locally
`php -S localhost:8000 router.php`, then open `http://localhost:8000/install.php`.

## Upgrading from Phase 1 (0.1.x) to Phase 2 (0.2.x)
1. Back up: export the database in phpMyAdmin (Export, SQL) and download your `user/` folder.
2. Upload the new files over the old ones. **Do not upload or overwrite `user/`** and skip `install.php` (it is locked anyway and the installer already removed it).
   Overwrite: `.htaccess`, `index.php`, `router.php`, everything in `core/`, and the `.md` documents.
3. Visitors now see a short "updating" page. Open `/admin`, sign in, and press **Apply update** on the "Update required" screen.
4. Check the dashboard shows version 0.2.0-phase2, then walk through the checklist in `TESTING.md`.
If the update screen reports an error, nothing is marked as updated; fix the cause (the message says what) and press Apply again. To roll back, restore the old files and your database export.

## Upgrading from Phase 2 (0.2.x) to Phase 3 (0.3.x)
1. Back up: export the database and download `user/` (including any themes you made).
2. Upload the new files over the old ones (`core/` and the `.md` documents). Do not touch `user/`. No `.htaccess` change is needed.
3. Optional clean-up: delete the old flat theme files that moved: `core/themes/base/home.php`, `page.php`, `article.php`, `book.php`, `private.php`, `404.php`, `error.php` (unused now).
4. Open `/admin`, sign in, press **Apply update** (no database changes this time; it only records the new version).
5. Open **Themes**, check the Base theme is active, load your site and view a page, a book article and a private page.
If the pages look unstyled after uploading, wait a few seconds (server code cache) and hard-refresh the browser.

## Upgrading from Phase 3 (0.3.x) to Phase 4A (0.4.0-phase4a)
1. Back up: export the database and download the whole `user/` folder.
2. Upload the new files over the old ones (`core/` and the `.md` documents). Do not touch `user/` or your root `.htaccess`.
3. Open `/admin`, sign in, press **Apply update**. This step runs six database migrations, converts any per-page custom CSS/JS into Code items, and builds the search index. It normally takes a few seconds; on a very large site, Site settings > *Rebuild search index* finishes the indexing in steps.
4. Open **Site settings** and check the site URL (the installer of a new site fills it in; for an existing site, enter it once).
5. Check **Code**: any custom CSS/JS you had on pages now appears there as "Imported: ..." and is linked to its page.
6. Walk through the Phase 4A checklist in `TESTING.md`.
PHP's own upload limits (`upload_max_filesize`, `post_max_size`) apply to the media library; the upload screen shows the effective limit. Rolling back: restore the old files and your database export (new tables are simply unused by the old code).

## Upgrading from Phase 4A (0.4.0) to Phase 4B (0.4.1)
1. Back up: export the database and download the whole `user/` folder.
2. Upload the new files over the old ones (`core/` and the `.md` documents). Do not touch `user/` or your root `.htaccess`.
3. Open `/admin`, sign in, press **Apply update** (two migrations: addresses and thumbnail columns).
4. Open **Media**: press **Generate thumbnails** for the images you uploaded earlier (repeat the press if the page says more remain).
5. Visit a Book Article: it now lives at `/parent/child`. The old flat address redirects automatically. Check your menus or links that point at old child addresses; they keep working through the redirect, but you may want to update them.
Requirements: MySQL 5.7+ or MariaDB 10.2+ (addresses use a long unique key). Thumbnails need the PHP GD extension (most hosts have it; the Media screen tells you if it is missing).

## Upgrading from Phase 4B (0.4.1) to 0.4.1-phase4b1 (autocomplete pickers)
Code only, no database changes. Back up, upload the new files over the old ones (`core/` and the `.md` documents; do not touch `user/` or `.htaccess`), then open `/admin` and press **Apply update** (it only records the new version). If the pickers look unstyled or do not respond, hard-refresh the browser (the old script may be cached) and check `TESTING.md`.

## Upgrading from 0.4.1-phase4b1 to Phase 4C (0.4.2)
Code only, no database changes. Back up, upload the new files over the old ones (`core/` and the `.md` documents; do not touch `user/` or `.htaccess`), open `/admin` and press **Apply update** (it only records the version).
Then: (1) open **Site settings** and make sure the **site URL** is correct (the sitemap, feed, canonical and social tags need it); the same screen lists your sitemap, feed and robots addresses. (2) Submit `/sitemap.xml` in Google Search Console and Bing Webmaster Tools. (3) If you made a child theme with its own `header.php` or `meta.php`, copy the new parts from the base theme (search box, SEO tags) as described in `THEME_GUIDE.md`.

## Upgrading from 0.4.2 (Phase 4C) to 0.5.0 (Phase 5, global settings)
Code only, no database changes. Back up, upload the new files over the old ones (`core/` and the `.md` documents; do not touch `user/` or `.htaccess`), open `/admin` and press **Apply update**. Then open **Site settings** and review the new **Display** card (items per page, time zone, date format, footer text) and the **Maintenance mode** card. Set your time zone (for India: `Asia/Kolkata`) so published dates match your local day. If you made a child theme with its own `footer.php`, copy the new one from the base theme to use the footer text setting.
