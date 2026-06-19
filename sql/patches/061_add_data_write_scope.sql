-- Adds the `data:write` OAuth scope used by the external key-value
-- ingestion endpoint (POST/PATCH /api/v1/runs/{name}/external-data).
-- Kept separate from `data:read` so a push-only external tool can be
-- granted write without read. Idempotent — patch 055 deduplicated the
-- oauth_scopes table and re-applying this must not create a duplicate.
INSERT INTO `oauth_scopes` (`scope`, `is_default`) VALUES ('data:write', 1)
  ON DUPLICATE KEY UPDATE `scope` = `scope`;
