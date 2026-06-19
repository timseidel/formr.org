<?php

/**
 * Run ingestion key (survey_run_ingest_keys).
 *
 * A static, per-run, write-only credential for external tools that can't
 * do the OAuth2 client_credentials grant. Presented either in the URL
 * (POST /api/ingest/<run>/<key>) or as an X-Api-Key / Authorization:
 * Bearer header; resolved by ApiController::ingestAction(), which then
 * writes through ExternalData::mergePayload().
 *
 * Only the SHA-256 hash is stored (mirrors HashedTokenOAuth2StoragePdo);
 * the plaintext key is shown once at creation and never recoverable.
 * Each key is pinned to one run and one `source_name`, so a leaked key
 * can only write that one namespace of that one run — never read, never
 * touch participant survey responses, never another study.
 *
 * Sibling of RunSecret (survey_run_secrets).
 */
class RunIngestKey extends Model
{
    public $id = null;
    public $run_id = null;
    public $label = null;
    public $key_hash = null;
    public $key_prefix = null;
    public $source_name = null;
    public $created = null;
    public $last_used_at = null;
    public $revoked = null;

    protected $table = 'survey_run_ingest_keys';

    /** Identifiable, secret-scanner-friendly prefix on every issued key. */
    const KEY_PREFIX = 'fri_';

    /** How many leading chars of the key to keep for UI identification. */
    const PREFIX_DISPLAY_LEN = 12;

    private static function hashKey($plaintextKey)
    {
        return hash('sha256', (string) $plaintextKey);
    }

    /**
     * Mint a new key for a run, pinned to one source namespace.
     *
     * Returns the PLAINTEXT key exactly once — only its hash is stored,
     * so it cannot be recovered later. Returns null if the source name
     * is invalid.
     *
     * @return array|null ['key' => plaintext, 'id' => int, 'prefix' => string]
     */
    public static function generate($run_id, $label, $source_name)
    {
        if (!ExternalData::isValidSource($source_name)) {
            return null;
        }

        $label = trim((string) $label);
        if ($label === '') {
            $label = 'Untitled key';
        }
        $label = mb_substr($label, 0, 100);

        // 24 random bytes -> 32 base64url chars, plus the fri_ prefix.
        $key = self::KEY_PREFIX . rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $prefix = substr($key, 0, self::PREFIX_DISPLAY_LEN);

        $db = DB::getInstance();
        $id = $db->insert('survey_run_ingest_keys', array(
            'run_id' => (int) $run_id,
            'label' => $label,
            'key_hash' => self::hashKey($key),
            'key_prefix' => $prefix,
            'source_name' => $source_name,
            'created' => mysql_now(),
        ));

        return array('key' => $key, 'id' => (int) $id, 'prefix' => $prefix);
    }

    /**
     * Resolve a presented plaintext key to its active row.
     *
     * Looks up by SHA-256 hash and ignores revoked keys. Returns the row
     * (assoc array) or null. Deliberately returns null for both unknown
     * and revoked keys so the caller can answer 401 without revealing
     * which.
     *
     * @return array|null
     */
    public static function resolve($plaintextKey)
    {
        if (!is_string($plaintextKey) || $plaintextKey === '') {
            return null;
        }
        $db = DB::getInstance();
        $row = $db->findRow(
            'survey_run_ingest_keys',
            array('key_hash' => self::hashKey($plaintextKey), 'revoked' => 0),
            array('id', 'run_id', 'label', 'key_prefix', 'source_name', 'created', 'last_used_at', 'revoked')
        );
        return $row ?: null;
    }

    /** Keys for a run, newest first, for the admin UI (no secret material). */
    public static function listForRun($run_id)
    {
        $db = DB::getInstance();
        return $db->select(array('id', 'label', 'key_prefix', 'source_name', 'created', 'last_used_at', 'revoked'))
            ->from('survey_run_ingest_keys')
            ->where(array('run_id' => (int) $run_id))
            ->order('created', 'desc')
            ->fetchAll();
    }

    /** Revoke a key. Scoped to the run so one run can't revoke another's. */
    public static function revoke($id, $run_id)
    {
        $db = DB::getInstance();
        return $db->update(
            'survey_run_ingest_keys',
            array('revoked' => 1),
            array('id' => (int) $id, 'run_id' => (int) $run_id)
        );
    }

    public static function touchLastUsed($id)
    {
        $db = DB::getInstance();
        return $db->update('survey_run_ingest_keys', array('last_used_at' => mysql_now()), array('id' => (int) $id));
    }
}
