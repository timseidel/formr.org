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

[rfc]: https://datatracker.ietf.org/doc/html/rfc7386
