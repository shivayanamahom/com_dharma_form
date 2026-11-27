CREATE TABLE IF NOT EXISTS `#__dharmaform_request_limits` (
  `scope_hash` char(64) NOT NULL,
  `window_start` bigint unsigned NOT NULL,
  `hits` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`scope_hash`, `window_start`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
