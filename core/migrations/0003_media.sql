-- 0003_media: uploaded files. Files live in user/uploads/YYYY/MM/{random}.{ext}; this table holds their metadata.

CREATE TABLE IF NOT EXISTS {p}media (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  path VARCHAR(190) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL,
  size INT UNSIGNED NOT NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  alt_text VARCHAR(255) NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_path (path),
  KEY idx_media_created (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
