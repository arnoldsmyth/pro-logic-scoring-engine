# 08 — Control Panel (React SPA)

Admin UI on the Laravel API. Auth: Laravel Sanctum (separate from API bearer keys). Roles: admin, viewer (extendable).

## Views

1. **Dashboard** — calls/day, scores by scope, error rates, latency, assessment volumes.
2. **Clients & API keys** — create/rotate/revoke keys, per-key scopes + rate limits + default access code, test vs live keys, webhook config.
3. **Access codes & royalties** — per 07-access-codes-royalties.md (issue/bulk/revoke, usage by code type, royalty statements, derivative usage shown but excluded from totals).
4. **Assessments** — search by external_id/email/date; per-assessment status (tools received, scopes scored, results, audit trace).
5. **Norms & analytics** — active/candidate/retired norm sets with provenance; sample-size accumulation per language; drift charts; candidate impact reports; promote/retire actions (06-norms.md).
6. **Content** — browse rule sets, translations, question text per language (read-only in v1).

## "Under the hood" documentation (first-class requirement)

The UI must explain what the system is doing, not just show numbers:

- Every view carries an explainer panel describing the mechanics behind it (e.g. the norms view explains what a norm set is, how raw→normed conversion works, why gender/pooled variants exist).
- A **pipeline page** documents the 4-stage scoring cascade with the dependency matrix (04-scoring-engine.md), rendered from the live rule data — not hand-maintained text.
- Per-assessment **audit trace view**: rules fired in order, intermediate scale values per stage, norm set used, content keys resolved — the UI walkthrough of `GET .../results/audit`.
- Scope reference page rendered from `GET /v2/reference/scopes` (which tools → which outputs, with question counts).

Rationale: this system's IP was a black box for a decade. The panel is also the living documentation that prevents that from happening again.
