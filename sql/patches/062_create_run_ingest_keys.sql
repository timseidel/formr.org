-- Run ingestion keys (v0.27.0) — static, per-run, write-only credentials
-- that let external tools push to survey_external_data WITHOUT the OAuth2
-- client_credentials grant. For webhook senders (Zapier, Qualtrics, lab
-- instruments, SMS gateways) that can only POST to one static URL,
-- optionally with one static header.
--
-- The key is presented either embedded in the URL
-- (POST /api/ingest/<run>/<key>) or as an X-Api-Key / Authorization:
-- Bearer header. It is a routing+auth token, NOT the participant session
-- code. Only the SHA-256 hash is stored (like oauth_access_tokens);
-- `key_prefix` is the leading chars kept for UI identification. Each key
-- is pinned to one run and one `source_name` namespace and is write-only
-- (reads stay on OAuth external_data:read).
--
-- Atlas down() is the inverse: DROP TABLE survey_run_ingest_keys.
CREATE TABLE `survey_run_ingest_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `label` varchar(100) NOT NULL COMMENT 'Human name, e.g. "Qualtrics webhook"',
  `key_hash` char(64) NOT NULL COMMENT 'SHA-256 of the issued key',
  `key_prefix` varchar(16) NOT NULL COMMENT 'Leading chars, shown in UI for identification',
  `source_name` varchar(50) NOT NULL COMMENT 'Pinned namespace this key may write',
  `created` datetime NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_hash` (`key_hash`),
  KEY `run_id` (`run_id`),
  CONSTRAINT `srik_run_fk` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
