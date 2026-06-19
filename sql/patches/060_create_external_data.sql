-- External key-value storage (v0.27.0) — lets external tools (scoring
-- engines, CRMs, webhooks) push state-independent JSON into a study
-- asynchronously, with no active participant session and without
-- touching the live run flow.
--
-- Rows are scoped to a run and namespaced by `source_name`. The
-- `external_ref` is a free-text reference the SURVEY AUTHOR generates
-- (e.g. a `calculate` item value); formr does not mint or own it. The
-- ref is a routing key, not a credential — run-scoped OAuth
-- (oauth_client_runs / data:write) is what actually gates access.
--
-- `payload` is a JSON document stored as LONGTEXT (MariaDB's JSON type),
-- guarded by JSON_VALID like survey_unit_sessions.state_log (patch 047).
-- Partial updates are applied atomically via JSON_MERGE_PATCH at the DB
-- level (see ExternalData::mergePayload), so the row is never pulled
-- into PHP to be re-serialized.
--
-- Atlas down() is the inverse: DROP TABLE survey_external_data.
CREATE TABLE `survey_external_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int(10) unsigned NOT NULL,
  `source_name` varchar(50) NOT NULL COMMENT 'Namespace, e.g. scoring_engine',
  `external_ref` varchar(191) NOT NULL COMMENT 'Author-chosen reference / code',
  `payload` longtext DEFAULT NULL CHECK (`payload` IS NULL OR JSON_VALID(`payload`)),
  `created` datetime NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `run_source_ref` (`run_id`, `source_name`, `external_ref`),
  KEY `run_source` (`run_id`, `source_name`),
  CONSTRAINT `sed_run_fk` FOREIGN KEY (`run_id`) REFERENCES `survey_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
