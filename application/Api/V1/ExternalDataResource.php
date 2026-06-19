<?php

/**
 * /api/v1/runs/{name}/external_data
 *
 * External key-value ingestion + retrieval. External tools push
 * state-independent JSON (scores, CRM flags, webhook results) keyed by a
 * `source` namespace and an author-chosen `ref`; survey logic reads it
 * back to make branching decisions.
 *
 *   GET  ?source=&ref=&keys=   data:read   list rows for the run
 *   POST  {ref, source, data}  data:write  atomic create-or-merge
 *   PATCH {ref, source, data}  data:write  (alias of POST — partial merge)
 *
 * Run-level authorization (the calling client may only touch runs on its
 * oauth_client_runs allowlist) is enforced by getRunByName(). The `ref`
 * is a routing key, not a credential — see ExternalData.
 */
class ExternalDataResource extends BaseResource
{
    private $run;

    public function handle($runName = null)
    {
        $method = $this->getRequestMethod();

        // Scope first, so a token lacking the right grant gets a clean
        // 403 regardless of whether the run exists (mirrors SessionResource).
        if ($method === 'GET') {
            $this->checkScope('data:read');
        } elseif ($method === 'POST' || $method === 'PATCH') {
            $this->checkScope('data:write');
        } else {
            return $this->error(405, 'Method not allowed');
        }

        $this->run = $this->getRunByName($runName);
        if (!$this->run) {
            return $this;
        }

        if ($method === 'GET') {
            return $this->readData();
        }

        return $this->writeData();
    }

    private function readData()
    {
        $source = $this->request->getParam('source');
        $ref = $this->request->getParam('ref');

        if ($source !== null && $source !== '' && !ExternalData::isValidSource($source)) {
            return $this->error(400, "Invalid 'source' filter.");
        }

        $keysParam = $this->request->getParam('keys');
        $keys = null;
        if ($keysParam !== null && $keysParam !== '') {
            $keys = array_values(array_filter(array_map('trim', explode(',', $keysParam)), 'strlen'));
        }

        $rows = ExternalData::getForRun($this->run->id, $source, $ref, $keys);
        return $this->response(200, 'External data', $rows);
    }

    private function writeData()
    {
        $body = $this->getJsonBody();

        $source = isset($body['source']) ? $body['source'] : null;
        $ref = isset($body['ref']) ? $body['ref'] : null;
        $data = isset($body['data']) ? $body['data'] : null;

        if (!ExternalData::isValidSource((string) $source)) {
            return $this->error(400, "A valid 'source' (1-50 chars, letters/digits/._-) is required.");
        }
        if (!is_string($ref) || trim($ref) === '' || strlen($ref) > 191) {
            return $this->error(400, "A non-empty 'ref' (max 191 chars) is required.");
        }
        // data must be a JSON object, not a scalar or list — the payload
        // is a key->value document and JSON_MERGE_PATCH expects an object.
        if (!is_array($data) || (count($data) > 0 && array_keys($data) === range(0, count($data) - 1))) {
            return $this->error(400, "'data' must be a JSON object of key/value pairs.");
        }

        $merged = ExternalData::mergePayload($this->run->id, $source, trim($ref), $data);
        if ($merged === null && $data !== array()) {
            return $this->error(500, 'Failed to store external data.');
        }

        return $this->response(200, 'External data stored', array(
            'source' => $source,
            'ref' => trim($ref),
            'payload' => $merged,
        ));
    }
}
