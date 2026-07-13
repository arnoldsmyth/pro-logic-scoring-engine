# 12 — Open Questions & Decisions Log

## Open (needs Arnold decision)

1. **Enneagram Map content plan** — who writes the type content; reconcile the two non-canonical "need" labels (11 §2).
2. **Validation Report 3.0** — commission when? Analytics layer will feed it (11 §6).

## Decided (with dates)

- 2026-07-10: Laravel 11 + MySQL 8 + React panel; DO App Platform or Cloud Run (either; stay portable). No Firebase products inside the scoring API.
- 2026-07-10: No legacy compatibility (PRO-D also rebuilt). Data-only API, no report generation. Sync scoring + optional webhooks.
- 2026-07-10: Engine ports as data-driven rule interpreter; products = data + content.
- 2026-07-10: Scoring scopes à la carte per dependency matrix (04). Gender required for S/P scopes; norms parameterized (male/female/pooled/versioned).
- 2026-07-10: v1 languages en/fr/pt; results as keys or server-resolved strings; es/ar = post-launch content projects (cultural adaptation + norm caveats).
- 2026-07-10: Royalty = per report via access codes typed training/bizdev/derivative; derivative pays none; metering from day one, Stripe later.
- 2026-07-10: Enneagram Map = derivative product, no wings, top-3 of 9, three-lens (M/C/S) type profile.
- 2026-07-10: Control panel must document under-the-hood mechanics (explainer panels, live pipeline page, audit trace view) (08).
- 2026-07-11: MCS Development carries forward into v2 as a scope preset; registered as a product/access-code preset as-needed (no dedicated upfront build — engine is data-driven, per 04) (11 §3).
- 2026-07-12: Charges & payouts model (canonical: development-assets/charges-payouts-data-model.md) — order types training|complimentary|lead|sale are reporting dimensions, never gates; every code usage logs a Charge ($0 is business reality for all but sale today); payouts (royalty|fee|residual, residual = balance catch-all always paid to a real stakeholder) split real charges and always sum to them; repeat usage of an order logs a $0 charge referencing the original (royalty due once per order, structurally); lead→sale conversion inferred at reporting time from shared external_order_id, never stored (07). Supersedes the flat_on_conversion term kind and the type-as-label/terms-driven framing from 2026-07-11.
- 2026-07-11: Norm promotion policy — candidate-set threshold = ≥400/scale; promotion candidate→active is always manual sign-off by Arnold on the impact report, never automated (06).
- 2026-07-11: Non-binary/unspecified gender policy — use pooled norms (06 §Launch sets); confirms pooled-v1 as the answer for gender-unspecified respondents.
- 2026-07-11: Git hosting + repo creation — resolved; repo exists at github.com/arnoldsmyth/pro-logic-scoring-engine (09).
- 2026-07-11: Standalone Role Report carries forward as a v2 product/scope preset; same treatment as MCS Development — `pro.role` scope already exists (04), so this is a config/access-code registration, not an engine build (11 §5).
- 2026-07-11: Access-code `type` is a descriptive label only; royalty-due-or-not is driven purely by whether a code has active `royalty_terms` (07).
- 2026-07-11: Access codes get a free-text `name` field set at issue time, for royalty-statement reporting; not derived from product+client (07).
- 2026-07-11: Royalty recipients modeled as a separate `payees` table from paying `clients` (optional nullable link between them when the same party is both) (07).
- 2026-07-11: Billing runs through Stripe, bank-transfer collection, monthly usage-metered, client self-service account (exact cadence/portal mechanics still open — see below) (07).
- 2026-07-11: Panel gets a third `norms-reviewer` role (not a full permissions system) gating norm-set promote/retire separately from general admin (08).
- 2026-07-11: Retake/same-person handling — detect via caller-supplied `external_id`, falling back to exact email match; no answer/session reuse across retakes (each submission independent); "track variance" means a panel timeline view of a person's linked assessments with score deltas (08).
- 2026-07-11: "Fee schedules per code type" and "royalty statement cadence" (originally item 3 above) superseded rather than answered as originally framed: there's no blanket fee schedule per type since type is now a descriptive label only (07, see above) — fees are negotiated per code/payee on `royalty_terms`; cadence is answered by the Stripe billing decision above (monthly, usage-metered). Tracked under beads epics prolog-4iu and prolog-opw, not as a standalone decision.
