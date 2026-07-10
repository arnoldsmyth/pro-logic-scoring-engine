# 05 — API Design

REST + JSON. OpenAPI 3.1 spec auto-generated and published at `/openapi.json` + docs UI (Scalar or Swagger UI). Auth: `Authorization: Bearer <api_key>` (hashed at rest). Synchronous scoring (fast); optional webhook per key for clients that prefer async. Idempotency: client `external_id` + `Idempotency-Key` header support.

## Endpoints

```
POST /v2/assessments                      create (registration info) → assessment_id
PUT  /v2/assessments/{id}/tools/{tool}    submit/replace one tool's responses (validated on write)
POST /v2/assessments/{id}/score           {scopes:[...], format:"keys"|"strings", language,
                                           norms:"male"|"female"|"pooled"|<norm_set_id>,
                                           access_code?, audit?:bool}
POST /v2/score                            one-shot convenience (registration + tools + scopes)
GET  /v2/assessments/{id}                 status: tools received/valid, scopes scored
GET  /v2/assessments/{id}/results         ?scope=&format=&language= (re-render any time)
GET  /v2/assessments/{id}/results/audit   audit trace (03-output-catalog.md)
GET  /v2/reference/languages              supported languages + content coverage + norm status
GET  /v2/reference/questions              ?tool=&language=
GET  /v2/reference/translations           ?language= (key→string maps for keys-mode clients)
GET  /v2/reference/scopes                 scope → required tools → output fields (self-documenting)
```

## Behaviors

- Incremental submission: tools can arrive over multiple calls (multi-page questionnaires); score whatever is complete. `score` with unmet scope requirements → 422 listing missing tools per scope.
- Result envelope: `{assessment_id, external_id, scored_at, language, format, norms:{set_id, provisional?}, scopes:{...}}`.
- `strings` format renders from keys + content tables at request time (any supported language, any time — not frozen at scoring).
- Validation errors: array of `{tool, q, rule, expected, got}`.
- Rate limits per key; usage events recorded on every scoring call (07-access-codes-royalties.md).
- Webhooks (optional per key): scored / failed events, HMAC-signed.

## Non-goals

No report/PDF generation — data only. No client-facing file storage. No Firebase/Firestore coupling (that's the calling partner site's own concern).
