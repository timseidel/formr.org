# Integrating an external tool with formr (External Data API)

This guide is for developers of an **external service** — a scoring
engine, CRM, SMS gateway, webhook receiver, etc. — that needs to push
data into a formr study or read it back, asynchronously, without a
participant being logged in.

formr gives you one simple primitive: a per-study **key–value store**,
namespaced by your tool and keyed by a reference the researcher hands
you. You `POST` JSON into it; the study's logic reads it back to make
branching decisions.

---

## Concepts in 30 seconds

- **Run** — a study. Your access is scoped to specific runs.
- **`source`** — your namespace inside a run (e.g. `scoring_engine`).
  You choose it; keep it stable.
- **`ref`** — a reference string the **researcher** generates inside
  their study (e.g. a per-participant code) and passes to you (in a
  redirect URL, a webhook config, etc.). You store data *against* this
  ref and read it back by the same ref.
  - The `ref` is **not a secret and not a login.** It's just a routing
    key. It is *never* a participant's formr session/login code — you
    will never see those.
- **`payload`** — a JSON object of your key/value pairs for one
  `(run, source, ref)` cell.

You only ever need the credentials the researcher gives you and the
`ref` values they pass you. You cannot reach any run you weren't
granted.

> **Can't do OAuth?** If your platform can only POST to a fixed URL
> (most webhook senders — Zapier, Qualtrics, Google Forms, SMS
> gateways, lab instruments), skip sections 1–2 and use an **ingestion
> key** instead — see *"No-OAuth option"* at the end. Everything else
> (the merge semantics, field rules, errors) is identical.

---

## 1. Get credentials

The **researcher** creates an OAuth2 client for you in their formr
account and grants it:

- scope `external_data:write` — to push data,
- scope `external_data:read` — to read data back (optional, only if you
  need it),
- an allowlist of the specific run(s) you may touch.

(These are dedicated to the external KV store — distinct from `data:*`,
which governs participant survey responses — so your client only ever
gets exactly the access it needs.)

They give you a **client ID** and **client secret**. Treat the secret
like a password.

## 2. Exchange them for an access token

OAuth2 **client_credentials** grant. Send the client ID and secret as
**HTTP Basic auth** (preferred — keeps the secret out of the request
body and proxy logs):

```bash
curl -s -X POST https://<formr-host>/api/oauth/access_token \
  -u "$CLIENT_ID:$CLIENT_SECRET" \
  -d grant_type=client_credentials
```

```json
{ "access_token": "ac767192…", "expires_in": 3600, "token_type": "Bearer", "scope": "external_data:write external_data:read" }
```

Passing them as body params (`-d client_id=… -d client_secret=…`) also
works, but Basic auth is the OAuth2-recommended form. Note this only
applies to the **client credentials at this endpoint** — the *issued*
access token below must always travel in the `Authorization` header
(formr rejects it in the body or query string).

Cache the token until it expires; re-request when it does. Send it as a
**header** on every call (never in the URL or body):

```
Authorization: Bearer <access_token>
```

## 3. Push data

### Single ref

```
POST  /api/v1/runs/{run_name}/external_data
PATCH /api/v1/runs/{run_name}/external_data   (identical behaviour)
```

Body:

```json
{
  "source": "scoring_engine",
  "ref": "participant-abc123",
  "data": { "score_a": 1, "score_b": 2 }
}
```

```bash
curl -s -X POST https://<formr-host>/api/v1/runs/my_study/external_data \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"source":"scoring_engine","ref":"participant-abc123","data":{"score_a":1,"score_b":2}}'
```

Response (`200`) echoes the **full stored payload** after your write:

```json
{ "source": "scoring_engine", "ref": "participant-abc123", "payload": { "score_a": 1, "score_b": 2 } }
```

### Batch (multiple refs in one request)

If you need to write data for several participants in one call, send an
`entries` array instead of a top-level `ref`/`data` pair. All entries
share the same `source`; each has its own `ref` and `data`:

```json
{
  "source": "scoring_engine",
  "entries": [
    { "ref": "participant-abc123", "data": { "score_a": 1, "score_b": 2 } },
    { "ref": "participant-def456", "data": { "score_a": 3, "score_b": 4 } }
  ]
}
```

```bash
curl -s -X POST https://<formr-host>/api/v1/runs/my_study/external_data \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"source":"scoring_engine","entries":[{"ref":"participant-abc123","data":{"score_a":1,"score_b":2}},{"ref":"participant-def456","data":{"score_a":3,"score_b":4}}]}'
```

Response (`200`) returns per-entry results in the same order:

```json
{
  "source": "scoring_engine",
  "entries": [
    { "ref": "participant-abc123", "payload": { "score_a": 1, "score_b": 2 } },
    { "ref": "participant-def456", "payload": { "score_a": 3, "score_b": 4 } }
  ]
}
```

**Batch rules:**

- **All-or-nothing.** If *any* entry fails validation, the entire request
  is rejected with a `400` and nothing is written. The error message
  includes the entry index (e.g. `entries[2]: A non-empty 'ref' ...`).
- **Max 100 entries** per batch request.
- Each entry follows the same merge semantics as a single-ref write.
- `source` is shared across all entries (one request = one source).

### Merge semantics (important)

Writes are a **partial merge** ([RFC 7386 JSON Merge Patch][rfc]), applied
atomically in the database:

- Keys you send are **added or overwritten**.
- Keys you **don't** send are **left untouched** — so you can update
  just `score_b` later without resending `score_a`.
- A key set to **`null` is deleted**.
- Concurrent writers updating different keys won't clobber each other.

```jsonc
// existing: {"score_a": 1, "score_b": 2}
// you send: {"score_a": null, "score_c": 3}
// result:   {"score_b": 2, "score_c": 3}
```

If a `(source, ref)` cell doesn't exist yet, the first write creates it
— you don't need to pre-register anything.

### Field rules

| Field    | Required | Rules |
|----------|----------|-------|
| `source` | yes | 1–50 chars, `[A-Za-z0-9_.-]` only |
| `ref`    | yes | non-empty, ≤ 191 chars (use what the researcher gave you) |
| `data`   | yes | a JSON **object** (not a list or scalar); may be `{}` (no-op) |

## 4. Read data back (optional)

```
GET /api/v1/runs/{run_name}/external_data?source=…&ref=…&keys=…
```

All query params optional: `source` and `ref` filter; `keys` is a
comma-separated projection of payload keys. Returns an array:

```bash
curl -s "https://<formr-host>/api/v1/runs/my_study/external_data?source=scoring_engine&ref=participant-abc123" \
  -H "Authorization: Bearer $TOKEN"
```

```json
[ { "source": "scoring_engine", "ref": "participant-abc123",
    "payload": { "score_b": 2, "score_c": 3 },
    "updated_at": "2026-06-19 13:39:46" } ]
```

(The study itself usually reads this internally — you only need `GET`
if your tool wants to fetch what it, or others, previously stored.)

## 5. Errors

| Code | Meaning |
|------|---------|
| `400` | Bad body — invalid `source`, empty `ref`, or `data` not an object |
| `401` | Missing / expired / invalid token |
| `403` | Token lacks the required scope, **or** your client isn't allowlisted for this run |
| `404` | Run doesn't exist (or you can't see it) |

Error bodies carry a human-readable `message`.

---

## End-to-end example flow

1. A participant reaches a point in the study where the researcher wants
   your tool involved. The study generates a `ref` for them and sends it
   to you — e.g. by redirecting the participant to
   `https://your-tool.example.com/start?ref=participant-abc123`.
2. Your tool does its work and `POST`s results back to
   `/api/v1/runs/my_study/external_data` with that `source` + `ref`.
3. Later in the study, formr reads the stored payload (by the same
   `ref`) and branches on it — e.g. `external$score_b > 2`.

You never handle participant logins or sessions; you only ever deal with
your credentials and the `ref` values the study hands you.

---

## No-OAuth option: ingestion keys (capability URLs)

If your platform can't do the OAuth token exchange — it can only POST to
a single static URL, maybe with one static header — the researcher can
issue you an **ingestion key** instead. It's a single static secret, no
token step.

**What the researcher does:** in the run's settings → **Ingest keys**,
they create a key pinned to one `source` (e.g. `scoring_engine`). The
full key (`fri_…`) is shown **once** at creation; they copy it to you.
They can revoke it any time.

**What the key can do:** **write only**, to that **one source** of that
**one run**. It can't read anything and can't reach another study — so a
leaked key has a small blast radius. (Reading still requires OAuth.)

**Send it one of two ways:**

Key in the URL (simplest — works with any webhook sender):
```bash
curl -s -X POST https://<formr-host>/api/ingest/my_study/fri_YOUR_KEY \
  -H "Content-Type: application/json" \
  -d '{"ref":"participant-abc123","data":{"score_a":1,"score_b":2}}'
```

Or key in a header (preferred if you can set one — keeps it out of URLs/logs):
```bash
curl -s -X POST https://<formr-host>/api/ingest/my_study \
  -H "X-Api-Key: fri_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"ref":"participant-abc123","data":{"score_a":1,"score_b":2}}'
```
(`Authorization: Bearer fri_YOUR_KEY` works too.)

**Body is just `{ref, data}`** — no `source`, because the key is already
pinned to one. Everything else is identical to the OAuth endpoint: the
same partial-merge semantics (omitted keys survive, `null` deletes), the
same `ref`/`data` rules, idempotent retries. Response echoes the merged
payload.

**Batch ingest** works the same way — send an `entries` array instead of
`ref`/`data`, up to 100 entries per request:

```bash
curl -s -X POST https://<formr-host>/api/ingest/my_study/fri_YOUR_KEY \
  -H "Content-Type: application/json" \
  -d '{"entries":[{"ref":"p1","data":{"score":7}},{"ref":"p2","data":{"score":3}}]}'
```

Response (`200`):

```json
{
  "source": "scoring_engine",
  "entries": [
    { "ref": "p1", "payload": { "score": 7 } },
    { "ref": "p2", "payload": { "score": 3 } }
  ]
}
```

All-or-nothing: if any entry is invalid the entire batch is rejected.

**Errors:** `400` bad body; `401` missing/unknown/revoked key; `404` the
key is valid but the `<run>` in the URL isn't the key's run; `405`
non-POST.

**Operational notes:** treat the key as a secret. If it rides in the URL
it can land in server/proxy logs — prefer the header form, and rotate by
creating a new key and revoking the old. There's no built-in per-key
rate limit yet; put one on `/api/ingest` at your reverse proxy if you
need it.

[rfc]: https://datatracker.ietf.org/doc/html/rfc7386
