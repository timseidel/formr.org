<?php

/**
 * External key-value storage (survey_external_data).
 *
 * External tools (scoring engines, CRMs, webhooks) push state-independent
 * JSON into a study asynchronously via the v1 API
 * (/api/v1/runs/{name}/external-data). Rows are scoped to a run and
 * namespaced by `source_name`; the `external_ref` is a reference the
 * survey author generates (e.g. a `calculate` item value) and hands to
 * the external tool — formr does not mint it. The ref is a routing key,
 * not a credential: run-scoped OAuth (oauth_client_runs / external_data:write) is
 * what gates access.
 *
 * Partial updates are applied atomically at the DB level with
 * JSON_MERGE_PATCH (RFC 7386 — a null value deletes that key), so the
 * payload is never read into PHP, mutated, and written back (which would
 * reintroduce a lost-update race between concurrent external writers).
 *
 * Sibling of RunSecret (survey_run_secrets, patch 059): a run-scoped
 * keyed table exposed through static helpers.
 */
class ExternalData extends Model
{
    public $id = null;
    public $run_id = null;
    public $source_name = null;
    public $external_ref = null;
    public $payload = null;
    public $created = null;
    public $updated_at = null;

    protected $table = 'survey_external_data';

    /**
     * Namespaces are author/tool-facing identifiers, stored verbatim and
     * echoed in API responses, so keep them to a conservative slug set.
     * Bounded to the column width (varchar(50)).
     */
    const SOURCE_PATTERN = '/^[A-Za-z0-9_.-]{1,50}$/';

    public static function isValidSource($name)
    {
        return is_string($name) && preg_match(self::SOURCE_PATTERN, $name) === 1;
    }

    /**
     * Atomic create-or-merge of a partial JSON document for one
     * (run, source, ref) cell.
     *
     * Create-on-write: the external tool may push before formr ever
     * reads, so an absent row is inserted. On an existing row the partial
     * is deep-merged via JSON_MERGE_PATCH against the current payload
     * (NULL treated as {}), all inside a single statement so concurrent
     * writers can't clobber each other's keys.
     *
     * $partial is bound as one JSON string parameter — there is no JSON
     * path expression to interpolate, so author-supplied keys cannot
     * inject SQL or a path.
     *
     * @return array|null The full merged payload after the write, decoded.
     */
    public static function mergePayload($run_id, $source_name, $external_ref, array $partial)
    {
        $db = DB::getInstance();
        $now = mysql_now();

        // An empty PHP array json_encodes to "[]" (a JSON array), and
        // JSON_MERGE_PATCH(target, '[]') would REPLACE the whole document
        // per RFC 7386 rather than be a no-op merge. Force the object form
        // so an empty patch leaves the stored payload untouched.
        if ($partial === array()) {
            $json = '{}';
        } else {
            $json = json_encode($partial, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($json === false) {
            return null;
        }

        $db->exec(
            "INSERT INTO `survey_external_data`
                (`run_id`, `source_name`, `external_ref`, `payload`, `created`)
             VALUES (:run_id, :source_name, :external_ref, :payload, :created)
             ON DUPLICATE KEY UPDATE
                `payload` = JSON_MERGE_PATCH(COALESCE(`payload`, '{}'), VALUES(`payload`))",
            array(
                'run_id' => $run_id,
                'source_name' => $source_name,
                'external_ref' => $external_ref,
                'payload' => $json,
                'created' => $now,
            )
        );

        $rows = self::getForRun($run_id, $source_name, $external_ref);
        return isset($rows[0]) ? $rows[0]['payload'] : null;
    }

    /**
     * Read rows for a run, optionally filtered by source and/or ref.
     *
     * The whole payload is decoded in PHP (the codebase deliberately
     * avoids JSON_EXTRACT in queries — MariaDB optimizer issue noted at
     * Services/PushNotificationService.php). When $keys is given, each
     * decoded payload is projected to just those keys.
     *
     * @param int         $run_id
     * @param string|null $source_name  Filter to one namespace.
     * @param string|null $external_ref Filter to one reference.
     * @param array|null  $keys         Project payloads to these keys.
     * @return array List of ['source','ref','payload','updated_at'].
     */
    public static function getForRun($run_id, $source_name = null, $external_ref = null, ?array $keys = null)
    {
        $db = DB::getInstance();

        $where = array('run_id' => $run_id);
        if ($source_name !== null && $source_name !== '') {
            $where['source_name'] = $source_name;
        }
        if ($external_ref !== null && $external_ref !== '') {
            $where['external_ref'] = $external_ref;
        }

        $rows = $db->select('source_name, external_ref, payload, updated_at')
            ->from('survey_external_data')
            ->where($where)
            ->order('source_name')
            ->order('external_ref')
            ->fetchAll();

        $out = array();
        foreach ($rows as $row) {
            $payload = $row['payload'] === null ? null : json_decode($row['payload'], true);
            if (is_array($payload) && $keys) {
                $payload = array_intersect_key($payload, array_flip($keys));
            }
            $out[] = array(
                'source' => $row['source_name'],
                'ref' => $row['external_ref'],
                'payload' => $payload,
                'updated_at' => $row['updated_at'],
            );
        }

        return $out;
    }
}
