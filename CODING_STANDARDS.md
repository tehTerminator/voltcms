# Coding standards and naming conventions

Small on purpose. These describe how the existing code is written; new code follows them so the project stays consistent.
Where this file and the code disagree, fix whichever is wrong in the same change.

## Language and portability
- PHP 8.0 compatible. Do not use 8.1+ features (`readonly`, enums, `never`, first-class callable syntax, `new` in initialisers, `array_is_list`).
- No Composer, no build step, no framework. Third-party code is limited to Bootstrap from a CDN, pinned to an exact version with a Subresource Integrity hash.
- Everything must work on ordinary shared hosting: no shell access, no cron, no extensions beyond the installer's checklist.

## Files and structure
- Every PHP file starts with `declare(strict_types=1);` (after `<?php`). Templates and views are the only exception.
- One class per file, file name = class name, namespace `Cms`. The autoloader looks in `core/engine/` then `core/admin/`.
- `core/engine/` code may run on public requests. `core/admin/` code is admin-only and must never be required from public code (the preview calls `PublicApp`, not the other way round).
- Classes are `final` unless something genuinely extends them.
- No closing `?>` in files that contain only PHP. UTF-8, no BOM, LF line endings, no tabs.
- Site data lives in `user/`, program code in `core/`. Code in `core/` never writes to `core/`.

## Naming
| Thing | Convention | Examples |
|---|---|---|
| Classes | PascalCase | `ContentWriter`, `RateLimiter` |
| Methods, properties, variables | camelCase | `latestPublicArticles()`, `$userCache` |
| Class constants | UPPER_SNAKE_CASE | `MAX_BODY`, `PENDING_TTL` |
| Global helper functions (templates) | snake_case, short | `e()`, `url()`, `partial()`, `get_theme_url()` |
| Database tables / columns | snake_case, prefix via `{p}` | `{p}content_revisions`, `published_at` |
| Settings keys, config keys | snake_case | `site_name`, `active_theme`, `installed_version` |
| Migrations | `NNNN_name.sql`, 4 digits, lowercase, forward-only | `0002_content.sql` |
| Admin views | snake_case file under `core/admin/views/` | `content_form.php` |
| Theme templates, partials | lowercase with hyphens | `book-nav.php`, `page-about.php` |
| Theme folders | `a-z 0-9 -`, max 39 | `my-site` |
| URLs | lowercase, hyphenated | `/admin/content/12/revisions` |
| Tests | describe the behaviour, in plain words | `'purge refused while a trashed child exists'` |

## Formatting (PSR-12 flavoured)
- 4 spaces. Opening brace of classes and methods on its own line; control structures (`if`, `foreach`, `switch`) keep it on the same line.
- Single-quoted strings unless interpolation or an escape is needed. Short array syntax. Trailing comma in multi-line arrays and argument lists.
- Type every parameter, property and return value (`?string`, `array<string,mixed>` in docblocks where the shape matters).
- Aim for lines of 120 characters or fewer. Long SQL, header strings and view markup may exceed this; do not reflow them only for length.
- Comments explain *why* or state limits and assumptions, not what the next line does. Every class has a docblock stating its responsibility and any deliberate limits.

## Architecture rules
- **One place for each concern:** configuration `Config`; database `Database`; routing `Router`; auth `Auth`; file writes `Files`; logging `Logger`; errors `Env`; HTML safety `Sanitizer` and `e()`. Do not duplicate these.
- **Dependency injection only where it helps tests:** pass `Database`, `ContentStore`, etc. into constructors. There is no service container. `App::db()` is the one shared connection for request code.
- **Content URLs** are built only with `content_url($row)` / `Url::content($path)`; the address is `content.path`, never `slug` alone.
- **Controllers** (`AdminApp`, `ContentAdmin`, `ThemeAdmin`, `PublicApp`) decide what to show; classes like `ContentWriter` and `ContentRepository` do the work. Admin controllers return `0` when handled, or `404`/`405` for `AdminApp` to report. Always `return` after `Response::redirect()`.
- **No abstractions for one use.** Add a class when two places need the same logic, not before.

## Database
- Only through `Database`, only prepared statements, with the `{p}` table-prefix placeholder. Never build SQL from request data. `LIMIT`/`OFFSET` values are cast to `int` before being interpolated. `LIKE` searches escape `!`, `%` and `_` (`ESCAPE '!'`).
- Store timestamps as UTC generated in PHP (`gmdate('Y-m-d H:i:s')`), never `NOW()`. Show them only through `format_date()` / `iso_date()` (site time zone and format); machine formats (RSS, sitemap) stay UTC.
- Migrations: `CREATE TABLE IF NOT EXISTS`, `{p}` prefix, a `;` only at the end of a statement, never edit a migration that has shipped (add a new one), update `DATABASE_SCHEMA.md`. Use a `.php` migration for conditional ALTERs and data conversion; keep the real logic in a testable class. `SHOW` statements cannot take bound parameters.
- Files that a web server (not PHP) must serve are written with mode 0644 (`Files::writeAtomic($p, $d, 0644)`); everything else keeps the default 0640.
- Multi-step writes that must succeed together use `Database::transaction()`. A transaction that also writes a file writes the file last.

## Security
- Escape everything printed with `e()`. The sanitised content body is the only value printed raw.
- Every POST is CSRF-checked centrally; every admin route is authenticated centrally. New admin routes get this by being routed through `AdminApp`.
- No inline `<script>` or inline event handlers; admin pages and public pages ship a Content Security Policy that blocks them. Inline CSS is limited to the nonce-carrying per-item block.
- Secrets (database password, app key, TOTP seeds, passwords) are never logged, echoed, or put in URLs. Passwords are hashed, TOTP seeds encrypted, recovery codes and IPs stored as keyed hashes.
- Validate and normalise user-supplied names and paths before touching the file system (`Theme::isSafeRelative`, slug and theme-name patterns). Write replacement files with `Files::writeAtomic()`.
- Never trust proxy headers for the client IP.
- Destructive operations are recoverable first: trash before delete, revision before overwrite, archive before purge.

## Admin interface components
- A choice among many records (pages, media, tags to come) uses `AutocompleteField` and a `LookupService` method, not a `<select>`; `<select>` is for short fixed lists (status, type, visibility).
- Browser scripts live in `core/admin/assets/`, have no dependencies, never use `innerHTML` for data, and are covered by a behaviour test in `tests/js/` (jsdom).
- The server remains the authority: every id a picker submits is validated again on save.

## Errors and logging
- Engine code throws exceptions; controllers turn them into a flash message or error page. Public pages never show internal messages (`display_errors` is off; `debug` in `user/config/app.php` is for development).
- Log failures and security events with `Logger::error()` / `Logger::security()` / `Logger::info()`, with small context arrays that contain no secrets.
- A failure in optional output (a theme, a partial) degrades to a simpler page instead of an error page.

## Views and themes
- Views and templates are plain PHP: loops, conditions and `<?= e(...) ?>` only. Business logic belongs in classes.
- Document the variables a template receives in a comment at its top.
- The theme API (template, partial and variable names, helper functions) is public. Changing it needs a deprecation note in `THEME_GUIDE.md` and `CHANGELOG.md`.

## Tests
- `php tests/run.php` runs without a framework (`check('what should be true', $bool)`). Tests must not write inside the project folder; use temp directories and clean up.
- Database tests use a throwaway database via `CMS_TEST_DB`. HTTP tests (`tests/e2e_*.py`) run against a scratch copy.
- Every bug fix and every new rule gets a test that fails without it.

## Definition of done for a change
Code follows this file; tests added and the full suite passes; migration and `DATABASE_SCHEMA.md` updated if the schema moved; `THEME_GUIDE.md` updated if the theme API moved; `CHANGELOG.md` and `PROJECT_STATUS.md` updated (including the files changed).
