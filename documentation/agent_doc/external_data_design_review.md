# External Data Ingestion — Design Review & Open Decisions

## What's built (feature/external-kv-storage)

| Layer | What's there | Status |
|---|---|---|
| **Schema** | `survey_external_data` (patch 060), `survey_run_ingest_keys` (patch 062), OAuth scopes (patch 061) | Complete |
| **Model** | `ExternalData` (merge / read / flattened export), `RunIngestKey` (generate / resolve / revoke / touch) | Complete |
| **Write API (OAuth)** | `POST/PATCH /api/v1/runs/{name}/external_data` — `ExternalDataResource` (single-ref + batch) | Complete |
| **Read API (OAuth)** | `GET /api/v1/runs/{name}/external_data?source=&ref=&keys=` | Complete |
| **Write API (ingest key)** | `POST /api/ingest/{run}/{key}` or header — `ApiController::ingestAction()` (single-ref + batch) | Complete |
| **Admin UI** | Settings → "Ingest keys" tab; create/revoke keys, one-time display | Complete |
| **Tests** | `ExternalDataTest` (source validation), `RunIngestKeyTest` (prefix, resolve, ref+data validation) — pure unit; `bin/test_external_data_smoke.php` + `bin/test_ingest_key_smoke.php` — live MariaDB | Complete |
| **Docs** | `documentation/external_data_integration.md` (external tool-facing) | Complete |

---

## The matrix, with data flows filled in

### Synchronous (participant is at a Wait/Breakpoint unit right now)

| | can show custom redirect link | can POST to URL | can use our OAuth Auth + API | has API we can query |
|---|---|---|---|---|
| **Current** | External unit redirects, no back-check | External unit redirects, no back-check | External unit + `api_end=1`, participant session ends via API call | External unit + Wait unit polling via API |
| **Proposed** | External unit redirects + **Wait unit** polls KV by `ref` → proceeds when data arrives. External posts via ingest key. | Same as redirect link. External posts via ingest key. | External unit + `api_end=1` + **Wait unit** polls KV. External posts via OAuth. | External unit + Wait polls external API (unchanged). OR: external API posts to our KV, Wait polls our KV (saves external calls). |

### Asynchronous (check later, no participant waiting)

| | can show custom redirect link | can POST to URL | can use our OAuth Auth + API | has API we can query |
|---|---|---|---|---|
| **Current** | No check at all | No check at all | No check at all | Branch/SkipForward with `api_end=0`, later check via scheduled API call — no built-in support |
| **Proposed** | Branch/SkipForward reads KV later (R: `formr::external_data()`) | Same — external posted via ingest key | Branch/SkipForward reads KV via OAuth API call | Branch/SkipForward checks external API (unchanged) OR reads KV that external posted to us |

### Sequence: typical sync ingest-key flow (most common for webhooks)

```
1. Study author creates a calculate item, e.g.:
     external_ref = paste0("p", aes_code)

2. External unit address configured as:
     https://survey.externaltool.com/start?ref={{external_ref}}

3. Participant reaches External unit → formr redirects them
   to the external tool with ref=pABC123

4. External tool finishes → POSTs result to formr:
     POST /api/ingest/my_study/fri_...
     {"ref": "pABC123", "data": {"score_a": 7, "score_b": 3}}

5. Participant returns (or Wait unit expires + cron re-enters) →
   Wait/Branch reads KV by (source, ref) → decides next step

6. Later, Branch/SkipForward with R code:
     external_data(source="scoring_engine", ref=external_ref)$score_b
   → branches based on result
```

### Sequence: async (check days later, participant long gone)

```
1. Same ingest flows write to KV asynchronously (webhooks, cron jobs, etc.)

2. Days later, a SkipForward unit's R condition is evaluated:
     !is.null(external_data("scoring_engine", external_ref)$score_a)

3. R calls the formr package → API read (external_data:read scope)
   → Branch/SkipForward follows the appropriate path
```

---

## Decisions

### 1. Survey logic reads data back via the formr R package (Option C)

**Decision: Option C.** The formr R package gets a new `external_data(run, source, ref)` function. Study authors call it from any Branch/SkipForward condition, the same way they already use `formr::results()` and other OpenCPU-backed R functions.

Why this option:

- **Works everywhere R runs in formr** — Branch, SkipForward, SkipBackward, Pause conditions, and feedback plots all evaluate through OpenCPU already. No new unit type or control flow needed.
- **No new RunUnit surface area** — avoids building/testing/maintaining a dedicated `ExternalCheck` unit.
- **Consistent mental model** — external data is just another data source you reference in R, like survey results. Study authors already write R conditions; this adds one more function.
- **Async by default** — Branch/SkipForward evaluated by cron can read KV data that was written hours or days ago. No participant needs to be waiting at a specific unit.

The synchronous/blocking case ("participant must wait until external data arrives") is not a separate problem — it's solved by the same R function. A **SkipBackward** unit whose condition references `external_data()` will block the session from advancing until the data is present. This is exactly how SkipBackward already works with R conditions: the participant loops back until the condition evaluates to true. No Wait unit extension needed.

Trade-offs acknowledged:

- **OpenCPU round-trip latency** on every branch evaluation that calls `external_data()`. Acceptable — the same latency already applies to all R conditions in formr.
- **R package release cycle** — the formr R package needs a new release before study authors can use this. The API endpoint is available immediately; the R function wraps it.
- **Auth context** — when OpenCPU calls back into formr to read external data, it needs `external_data:read` scope. See item 4 in the build-next list below.

### 2. The `ref` is generated by the study author and passed to the external tool

It is NOT the participant's session code — that must never leave formr. Concrete pattern:

```
# In the survey spreadsheet:
# Item type: calculate
# Name: external_ref
# Value: paste0("p", aes_code)

# In the External unit address field:
https://survey.externaltool.com/start?ref={{external_ref}}
```

The template substitution `{{external_ref}}` already works in the External unit's address field (it uses `do_run_shortcodes`). Study authors need documentation showing this pattern.

No shortcode blocking is needed — External unit addresses are constructed by the user using R and `paste0()`. The user decides what goes into the URL. Keep it simple.

### 3. Both the API and ingest keys are needed — they serve different trust models

| | Ingest key | OAuth API |
|---|---|---|
| Auth | Static secret in URL/header | Token rotation (client_credentials) |
| Blast radius | Write one source on one run | Per OAuth scope allowlist |
| **Read access** | **None** | `external_data:read` scope |
| Use case | Webhooks, lab instruments, SMS gateways | Scoring engines, CRMs doing OAuth |
| Rotation | Manual (create new, revoke old) | Automated (token expiry) |

The **additional reason** is OpenCPU integration. The formr R package needs to **read** data back for branching logic. Ingest keys can't read. If we go with option C (R function), the read path goes through the OAuth API or a direct DB call from within OpenCPU.

---

## Security & threat model

### Ingest key leakage

If an ingest key (`fri_...`) is leaked:

- Attacker can **write arbitrary KV data** under one `(run, source)` namespace
- They **cannot read** anything (no read scope on ingest keys)
- They **cannot touch** other sources, other runs, or participant survey responses
- They **could influence branching logic** if the study reads from that source — this is by design (the tool is trusted to write correct data), but a leaked key means an untrusted actor writes data the study trusts

Mitigation: rotate keys (create new → deploy to external tool → revoke old). Ingest key shown once, SHA-256 stored, can't be recovered.

### The `ref` is not a secret

By design, the `ref` appears in redirect URLs that participants can see. It's a routing key, not a credential. You need either an ingest key or an OAuth token to write. Assigning predictable refs (like sequential IDs) is fine — the external tool validates the data it writes, not the ref.

### Rate limiting

Intentionally **not implemented in-app** — the `Cache` object is per-request (non-persistent). Enforce rate limits on `/api/ingest` at the reverse proxy (Traefik rate-limit middleware, nginx `limit_req`, etc.). Document this for ops.

---

## Data lifecycle & GDPR

| Concern | Current state | Note |
|---|---|---|
| **TTL / auto-expiry** | None — rows persist until the run is deleted | Could add an optional `expires_at` column later; for now, researcher manually exports + requests deletion |
| **Run deletion** | `ON DELETE CASCADE` from `survey_runs` — deleting a run wipes all external data | Correct; no orphaned rows |
| **Data export** | `getForRunFlattened()` produces CSV/XLSX/JSON for researcher download | This is also the mechanism for GDPR data portability / deletion requests |
| **Individual ref deletion** | Send `null` as the full payload (or per-key `null` via JSON Merge Patch) to clear data for one ref | Document this pattern for researchers |

**Decision needed**: Should we add an optional `expires_at` column to `survey_external_data`? Or defer? For ESM studies with frequent writes, unbounded accumulation is a real concern.

---

## Technical notes for co-developers

### Minimum MariaDB version

`JSON_MERGE_PATCH` is available from MariaDB 10.2+. The `CHECK (JSON_VALID(...))` constraint is 10.2+ as well. This matches the existing requirement from patch 047 (`survey_unit_sessions.state_log`). No new constraint.

### Source name validation

`source_name` is `varchar(50)`, pattern `^[A-Za-z0-9_.-]{1,50}$`. Dotted namespaces like `scoring.engine.v2` are valid. Unicode and spaces are rejected.

### Router wiring

The ingest route `POST /api/ingest/{run}/{key}` is handled by `ApiController::ingestAction()` — it does **not** go through the v1 OAuth dispatch (`dispatchV1/doAction`). Verify `setup.php`'s route table passes `/api/ingest/...` to `ApiController` correctly, and that there's no conflict with existing `/api/v1/` routes. The branch adds this to the existing `ApiController` class but the routing entry needs an explicit check.

### `payload` empty-array edge case

`ExternalData::mergePayload()` forces an empty PHP array to `{}` in JSON. This is intentional — `JSON_MERGE_PATCH(target, '[]')` would **replace the entire document** per RFC 7386. An empty `{}` merge is a no-op, which is the correct semantics. Tests cover this but it's non-obvious; document it for anyone extending the code.

### The `ref` length budget

`external_ref` is `varchar(191)`. This matches the URL-friendly budget in other formr tables. If the external tool generates its own refs, the 191-char limit is generous. If the study author generates refs via a `calculate` item, typical values like `pABC123` are well within budget.

### Per-key rate limiting (deferred)

`RunIngestKey` has no `rate_limit` column. The comment in `ApiController::ingestAction()` explicitly delegates this to the reverse proxy. If per-key rate limiting is needed later, add a column and check it in `resolve()` — but for now, a global rate limit on `/api/ingest` at Traefik/nginx is sufficient.

---

## What to build next (priority order)

1. **formr R package: `external_data()` function** — wraps the `GET /api/v1/runs/{name}/external_data` endpoint. Study authors call it from Branch/SkipForward conditions. Signature: `external_data(run_name, source = NULL, ref = NULL, keys = NULL)` → returns a data frame or named list. This is the piece that makes the stored data actually usable in study logic.
2. **OpenCPU → formr auth bridge** — when the R function calls back into formr to read external data, it needs `external_data:read` scope. Options: (a) service-to-service OAuth token configured in formr settings, (b) internal DB call bypassing the API since OpenCPU and formr share the same DB, (c) per-run API key. Option (b) is simplest and most performant — the R function runs inside OpenCPU which has DB access, so it can call `ExternalData::getForRun()` directly via a dedicated endpoint that authenticates as the OpenCPU service rather than as an external OAuth client. Requires a design decision.
3. **`ref` generation documentation** — show the `calculate` + `{{external_ref}}` pattern. Consider blocking `{{session}}` / `{{login_code}}` in External addresses.
4. **Optional: `expires_at` column** on `survey_external_data` for data hygiene in longitudinal studies.