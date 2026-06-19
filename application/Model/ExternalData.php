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
     * Validate the ref + data of a write, shared by the OAuth resource
     * (ExternalDataResource) and the non-OAuth ingest endpoint
     * (ApiController::ingestAction) so both enforce identical rules.
     *
     * @return string|null Error message, or null if valid.
     */
    public static function validateRefAndData($ref, $data)
    {
        if (!is_string($ref) || trim($ref) === '' || strlen($ref) > 191) {
            return "A non-empty 'ref' (max 191 chars) is required.";
        }
        // data must be a JSON object, not a scalar or list — the payload
        // is a key->value document and JSON_MERGE_PATCH expects an object.
        if (!is_array($data) || (count($data) > 0 && array_keys($data) === range(0, count($data) - 1))) {
            return "'data' must be a JSON object of key/value pairs.";
        }
        return null;
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

    /**
     * Flattened export: one row per (source, ref), top-level payload
     * keys spread into their own columns. Nested objects and arrays are
     * JSON-encoded as strings so tabular formats (CSV/XLSX) stay flat.
     *
     * Collects all distinct top-level payload keys across every row for
     * the run so column order is consistent (source, ref, …keys…, updated_at).
     *
     * @param int         $run_id
     * @param string|null $source_name  Filter to one namespace.
     * @return array{columns: string[], rows: array[]}
     */
    public static function getForRunFlattened($run_id, $source_name = null)
    {
        $rows = self::getForRun($run_id, $source_name);

        if (empty($rows)) {
            return array('columns' => array('source', 'ref', 'updated_at'), 'rows' => array());
        }

        $allKeys = array();
        foreach ($rows as $row) {
            if (is_array($row['payload'])) {
                foreach ($row['payload'] as $k => $v) {
                    $allKeys[$k] = true;
                }
            }
        }
        $payloadKeys = array_keys($allKeys);
        sort($payloadKeys, SORT_NATURAL | SORT_FLAG_CASE);

        // Sanitize payload keys for use as column headers in tabular exports:
        // replace non-alphanumeric chars with underscore, prefix with _ if numeric.
        $safeColumns = array();
        foreach ($payloadKeys as $key) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
            if (ctype_digit($safe[0] ?? '')) {
                $safe = '_' . $safe;
            }
            $safeColumns[$key] = $safe;
        }

        $columns = array_merge(array('source', 'ref'), array_values($safeColumns), array('updated_at'));

        $flat = array();
        foreach ($rows as $row) {
            $out = array('source' => $row['source'], 'ref' => $row['ref']);
            foreach ($payloadKeys as $key) {
                $val = isset($row['payload'][$key]) ? $row['payload'][$key] : null;
                $colName = $safeColumns[$key];
                $out[$colName] = is_array($val) ? json_encode($val, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : $val;
            }
            $out['updated_at'] = $row['updated_at'];
            $flat[] = $out;
        }

        return array('columns' => $columns, 'rows' => $flat);
    }
}
