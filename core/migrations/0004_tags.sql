-- 0004_tags: tags and the content-tag relation.

CREATE TABLE IF NOT EXISTS {p}tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tags_name (name),
  UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}content_tags (
  content_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (content_id, tag_id),
  KEY idx_content_tags_tag (tag_id, content_id),
  CONSTRAINT fk_{p}ct_content FOREIGN KEY (content_id) REFERENCES {p}content (id) ON DELETE CASCADE,
  CONSTRAINT fk_{p}ct_tag FOREIGN KEY (tag_id) REFERENCES {p}tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
