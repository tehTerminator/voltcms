# Database schema (Phase 1)

Engine InnoDB, utf8mb4 / utf8mb4_unicode_ci. `{p}` is the table prefix (e.g. `cms_`). All timestamps are UTC.

| Table | Columns | Notes |
|---|---|---|
| `{p}schema_migrations` | `version` PK, `applied_at` | one row per applied migration file |
| `{p}users` | `id`, `username` (unique), `email`, `password_hash`, `totp_secret_enc`, `totp_enabled`, `totp_last_step`, `created_at`, `last_login_at` | one administrator; secret is AES-256-GCM; `totp_last_step` blocks replay |
| `{p}recovery_codes` | `id`, `user_id`, `code_hash`, `used_at` | keyed HMAC-SHA256 hashes, single use |
| `{p}login_attempts` | `id`, `ip_hash`, `username_hash`, `attempted_at` | keyed hashes only; purged opportunistically after 24 h |
| `{p}settings` | `setting_key` PK, `setting_value`, `updated_at` | `site_name`, `installed_at` so far |

(Content, tags, media and search tables were added in later phases; see below.)
Phase 2 will also need a per-page visitor-password hash on `content`.

## Migration rules
Forward-only files in `core/migrations/NNNN_name.sql`; `CREATE TABLE IF NOT EXISTS`; `;` only at statement ends; use `{p}`.

## Phase 2 tables (migration 0002_content)

| Table | Columns | Indexes / keys |
|---|---|---|
| `{p}content` | `id`, `type` (page/article/book), `parent_id`, `position`, `slug`, `title`, `status` (draft/published), `visibility` (public/private), `view_password_hash`, `excerpt`, `meta_description`, `noindex`, `custom_css`, `custom_js`, `published_at`, `created_at`, `updated_at`, `deleted_at` | PK `id`; UNIQUE `slug`; `(deleted_at, type, status, published_at)` for public lists; `(parent_id, position)` for books; `(deleted_at, updated_at)` for the admin list; FK `parent_id` -> `content.id` ON DELETE RESTRICT |
| `{p}content_revisions` | `id`, `content_id`, `title`, `note`, `created_by`, `created_at` | PK `id`; `(content_id, id)`; FK `content_id` -> `content.id` ON DELETE CASCADE |

Bodies are files, not columns. Revision bodies are files named after `content_revisions.id`.
`type`, `status` and `visibility` are plain strings validated in PHP so new types need no table change.
Trashed rows keep their slug (unique). `deleted_at` marks trash. Purging deletes the row and archives its files.
(Tags, media and the search index were added in Phase 4; blocks and regions were dropped from the plan.)

## Settings keys in use (table `{p}settings`)
`site_name`, `installed_at` (Phase 1), `active_theme` (Phase 3; `base` or the folder name of a child theme). `site_lang` is read if present (default `en`); a settings screen arrives in a later phase.
Phase 3 added no tables or columns.

## Phase 4A tables (migrations 0003 to 0008)

| Table | Columns | Notes |
|---|---|---|
| `{p}media` | `id`, `path` (unique, `YYYY/MM/random.ext` under `user/uploads/`), `original_name`, `mime`, `size`, `width`, `height`, `alt_text`, `uploaded_by`, `created_at` | image and PDF uploads; type detected from content |
| `{p}tags` | `id`, `name` (unique), `slug` (unique), `created_at` | |
| `{p}content_tags` | `content_id`, `tag_id` (composite PK) | FKs cascade from both sides |
| `{p}code_assets` | `id`, `kind` (css/js), `name` (unique per kind), `description`, `file` (unique), `version_hash`, `size`, `load_order`, `created_at`, `updated_at` | code lives in `user/assets/{css,js}/{file}`; `version_hash` changes with the code |
| `{p}content_assets` | `content_id`, `asset_id` (composite PK) | which content loads which asset; cascades |
| `{p}content_redirects` | `old_slug` (PK), `content_id`, `created_at` | recorded on every slug change; honoured publicly from 4B |
| `{p}search_index` | `content_id` (PK), `title`, `tags_text`, `body_text`, `updated_at`; FULLTEXT `(title, tags_text, body_text)` when the server allows | plain text copy of each non-trashed item for search |

Columns added to `{p}content` (migration 0005): `seo_title VARCHAR(160)`, `og_image_media_id INT UNSIGNED`.
Deprecated, kept as a safety copy and no longer read or written: `{p}content.custom_css`, `{p}content.custom_js` (converted into code assets by migration 0007).
Settings keys added: `site_url`, `site_description`, `site_logo_media_id`, `site_social_media_id`, `search_mode` (`fulltext` or `like`), `search_index_complete`, `search_index_after`.
Migrations 0005, 0007 and 0008 are PHP files (conditional ALTERs, data conversion, optional FULLTEXT index).

## Phase 4B changes (migrations 0009 and 0010)
- `{p}content.path VARCHAR(500) NOT NULL`, **unique** (`uq_content_path`): the full address (`book/part-one/article-1`; just the slug for pages, articles and top-level books). `slug` stays the last segment and is no longer unique by itself (`idx_content_slug` is a plain index): the same slug may exist under different parents. Needs an InnoDB row format that allows 2000-byte keys (MySQL 5.7+, MariaDB 10.2+).
- `{p}content_redirects.old_slug` became `old_path VARCHAR(500)`: any address that used to work (renamed slug, moved page, a child page's old flat address) and the content it now belongs to. Created automatically when an address changes; a live address always wins over a redirect; rows cascade with their content.
- `{p}media` gained `thumb_path`, `thumb_width`, `thumb_height`, `thumb_status` (NULL = not attempted, `ok`, `small`, `skipped`, `failed`). Thumbnails live in `user/uploads/YYYY/MM/thumbs/`.
- Settings key added: `thumbs_after` (resume point of "Generate thumbnails").
