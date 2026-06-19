-- Adds the OAuth scopes for the external key-value endpoint
-- (/api/v1/runs/{name}/external_data).
--
-- Deliberately a SEPARATE resource namespace from `data:read` (which
-- grants read access to participant survey RESPONSES via /results).
-- Reusing data:* would mean a tool allowed to read its own external KV
-- could also dump every participant's response data — and a future
-- "write results" endpoint would collide with the write scope. Keeping
-- external_data:* distinct preserves least privilege in both directions.
--
-- Idempotent — patch 055 deduplicated oauth_scopes and added the unique
-- key, so re-applying must not create duplicates.
INSERT INTO `oauth_scopes` (`scope`, `is_default`) VALUES
  ('external_data:read', 1),
  ('external_data:write', 1)
  ON DUPLICATE KEY UPDATE `scope` = `scope`;
