-- 0006_code_assets: reusable CSS and JavaScript files written in the admin and linked to content.
-- The code itself lives in user/assets/{css|js}/{id}-{name}.{css|js}; version_hash changes whenever the code changes.

CREATE TABLE IF NOT EXISTS {p}code_assets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(8) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  file VARCHAR(140) NOT NULL,
  version_hash CHAR(10) NOT NULL,
  size INT UNSIGNED NOT NULL DEFAULT 0,
  load_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code_assets_name (kind, name),
  UNIQUE KEY uq_code_assets_file (file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS {p}content_assets (
  content_id INT UNSIGNED NOT NULL,
  asset_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (content_id, asset_id),
  KEY idx_content_assets_asset (asset_id),
  CONSTRAINT fk_{p}ca_content FOREIGN KEY (content_id) REFERENCES {p}content (id) ON DELETE CASCADE,
  CONSTRAINT fk_{p}ca_asset FOREIGN KEY (asset_id) REFERENCES {p}code_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
