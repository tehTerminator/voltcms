-- 0002_content: pages, articles, book articles and revisions (Phase 2).
-- Bodies live in user/content/posts/{id}.html; this table holds metadata only.

CREATE TABLE IF NOT EXISTS {p}content (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(16) NOT NULL,
  parent_id INT UNSIGNED NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  visibility VARCHAR(16) NOT NULL DEFAULT 'public',
  view_password_hash VARCHAR(255) NULL,
  excerpt TEXT NULL,
  meta_description VARCHAR(320) NULL,
  noindex TINYINT(1) NOT NULL DEFAULT 0,
  custom_css MEDIUMTEXT NULL,
  custom_js MEDIUMTEXT NULL,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_content_slug (slug),
  KEY idx_content_public (deleted_at, type, status, published_at),
  KEY idx_content_parent (parent_id, position),
  KEY idx_content_updated (deleted_at, updated_at),
  CONSTRAINT fk_{p}content_parent FOREIGN KEY (parent_id) REFERENCES {p}content (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}content_revisions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  content_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  note VARCHAR(40) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_revisions_content (content_id, id),
  CONSTRAINT fk_{p}revisions_content FOREIGN KEY (content_id) REFERENCES {p}content (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
