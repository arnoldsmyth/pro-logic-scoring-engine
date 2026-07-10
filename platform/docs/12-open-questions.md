# 12 — Open Questions & Decisions Log

## Open (needs Arnold decision)

1. **Non-binary/unspecified gender policy** — pooled norms are the mechanical answer; changes scores vs gendered norms; needs product sign-off (06-norms.md).
2. **Norm promotion policy** — approval owner + exact sample threshold (06-norms.md).
3. **MCS Development in v2?** — revival is a config exercise; business decision (11-products-roadmap.md §3).
4. **Enneagram Map content plan** — who writes the type content; reconcile the two non-canonical "need" labels (11 §2).
5. **Validation Report 3.0** — commission when? Analytics layer will feed it (11 §6).
6. **Fee schedules** per code type and royalty statement cadence (07).
7. **Git hosting + repo creation** — nothing can be built until this exists (09).

## Decided (with dates)

- 2026-07-10: Laravel 11 + MySQL 8 + React panel; DO App Platform or Cloud Run (either; stay portable). No Firebase products inside the scoring API.
- 2026-07-10: No legacy compatibility (PRO-D also rebuilt). Data-only API, no report generation. Sync scoring + optional webhooks.
- 2026-07-10: Engine ports as data-driven rule interpreter; products = data + content.
- 2026-07-10: Scoring scopes à la carte per dependency matrix (04). Gender required for S/P scopes; norms parameterized (male/female/pooled/versioned).
- 2026-07-10: v1 languages en/fr/pt; results as keys or server-resolved strings; es/ar = post-launch content projects (cultural adaptation + norm caveats).
- 2026-07-10: Royalty = per report via access codes typed training/bizdev/derivative; derivative pays none; metering from day one, Stripe later.
- 2026-07-10: Enneagram Map = derivative product, no wings, top-3 of 9, three-lens (M/C/S) type profile.
- 2026-07-10: Control panel must document under-the-hood mechanics (explainer panels, live pipeline page, audit trace view) (08).
