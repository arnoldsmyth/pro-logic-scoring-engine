# Pro Scoring Platform v2 — Master Index

**Status: BUILD IN PROGRESS. Phases 1–7 done: Laravel skeleton + CI, migration seeds, golden harness, engine (68/68 goldens both formats), v2 API + access codes, norm analytics + versioned sets, React control panel. The charges & payouts model (order types, charge/payout ledgers, lead→sale conversion — see 07 + `development-assets/charges-payouts-data-model.md`) and normalized clients/payees are also live. Remaining: Phase 8 (pooled-v1), deployment + legacy cutover epics, Stripe billing. Work tracked in beads (`bd ready`).**

This doc set is written so a build agent can implement the system without re-deriving anything. Read in order for full context; each doc stands alone for its area.

| Doc | Contents |
|---|---|
| [01-legacy-system.md](01-legacy-system.md) | What we're replacing; local replica + extraction scripts; zero-compat statement |
| [02-input-catalog.md](02-input-catalog.md) | Registration fields; the 9 tools (counts, formats, validation); question text serving |
| [03-output-catalog.md](03-output-catalog.md) | mcs / pro / insights / reflections field catalog; keys vs strings; audit trace |
| [04-scoring-engine.md](04-scoring-engine.md) | Rule-interpreter design; ~15 primitives; dependency matrix; scopes; gender-split norms |
| [05-api-design.md](05-api-design.md) | Endpoints, behaviors, OpenAPI, errors, webhooks, non-goals |
| [06-norms.md](06-norms.md) | Versioned norm sets; pooled-v1 derivation; new-language pipeline; drift analytics |
| [07-access-codes-royalties.md](07-access-codes-royalties.md) | Order types; charges & payouts ledgers; payout schedules + residual; conversion; Stripe-later |
| [08-control-panel.md](08-control-panel.md) | Panel views; **under-the-hood documentation requirement**; audit trace UI |
| [09-architecture-deployment.md](09-architecture-deployment.md) | Laravel 11 + MySQL 8 + React; DO/Cloud Run; CI; security; repo layout |
| [10-verification.md](10-verification.md) | 68 golden masters; test harness (build FIRST); privacy rules |
| [11-products-roadmap.md](11-products-roadmap.md) | Launch product, Enneagram Map (CVA↔type mapping), MCS-Dev, GSS, Validation Report 3.0 |
| [12-open-questions.md](12-open-questions.md) | Open decisions + dated decisions log |

## Build order (when the repo exists)

1. Repo + CI skeleton (09) → 2. Migration seeds from `restore-db/extracted/` → 3. **Golden-master harness (10)** → 4. Engine primitives + interpreter (04) until 68/68 pass → 5. API layer (05) + access codes (07) → 6. Norm analytics (06) → 7. Control panel (08) → 8. pooled-v1 norms + docs polish.

## Companion material (not in docs/)

- `../CLAUDE-NOTES.md` — session-by-session exploration knowledge (how we learned all this).
- `../restore-db/` — replica scripts, `extracted/` config CSVs, `goldens/` test cases (**both stay out of git — PII + live credentials**).
- `../original-source/` — legacy source, DB script, 7.3GB .bak, API instructions.
- `../Technical Overviews/` — 2010/2019 validation documents (basis for 11 §6).
- `../SPEC-SUPERSEDED-SCORING-API.md` — superseded by this folder; kept for history.
