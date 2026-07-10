# 06 — Norms: versioned data + continuous improvement

Norms are **versioned data, never code**. Rationale + gender findings: 04-scoring-engine.md.

## Data model

`norm_sets`: id, scale coverage, population filter (language, region, gender, date range), status `candidate → active → retired`, provenance (n, composition, source date range, created_from analytics run). **Every scored result records the norm_set id used** — reproducible forever; nothing silently rescores.

## Launch sets

- `male-legacy`, `female-legacy`: migrated verbatim from `PZSDConversionMatrix` (golden-master fidelity).
- `pooled-v1`: derived empirically from the ~16.7k historical sessions in the .bak — pull PZSD raw scale scores (scales 168–171) from ToolScaleValue tagged gender/language, compute pooled raw→cumulative-proportion tables (mirror the 0..1 shape of the legacy tables), smooth sparse tails. Validate by rescoring history under pooled vs gendered norms; publish the % whose top-3 codes change as the documented trade-off. NOT built by averaging the M/F tables.

Pooled norms serve clients that cannot collect gender (also note: US "within-group norming" prohibition in employment selection contexts — CRA 1991 — makes pooled the safer corporate default; not legal advice).

## Continuous evaluation & new languages

- Analytics job accumulates anonymized raw scale distributions per language/population from incoming responses; control panel shows sample sizes + drift vs active norm sets.
- New-language pipeline: launch with `provisional` norms (results flagged) → sample reaches threshold (≥300–400 per scale) → auto-build `candidate` set → side-by-side impact report (% results that would change) → human promotes. Retired sets remain queryable.
- Same pipeline periodically re-evaluates mature norms (the en norms are decades old — drift likely; recalibration is a deliberate, versioned, documented event).
- Provenance caveat recorded on every set: respondents are self-selected assessment clients, not a general-population sample.

## Open policy decisions

- Non-binary/unspecified gender: pooled norms are the mechanical answer; needs product/TAI sign-off (it changes scores vs gendered norms).
- Promotion policy: who approves candidate→active, and the exact sample threshold.
