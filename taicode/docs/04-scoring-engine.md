# 04 — Scoring Engine

## Design principle (non-negotiable)

Port the engine as a **data-driven rule interpreter**, exactly like the legacy design — NOT hardcoded PRO-D logic. A "product" (PRO-D, MCS Development, Enneagram Map, future instruments) is package data + content rows, zero engine code. See 11-products-roadmap.md for why this matters commercially.

## Pipeline (4 gated stages, from legacy sp_Score)

`Tools → Package → Profile → Insight`. Each stage runs its rule list in sequence; each rule names an operation (primitive) + parameters + sources; each stage writes scale values consumed by the next; a stage failing validation halts the cascade.

Rule data model (migrate as-is from `restore-db/extracted/`): `{Tool,Package,Profile,Insight}Rule`, `*RuleType` (names the primitive), `*RuleParams`, `*RuleSource` (SrcType decode: TV=raw tool input via TROutKey, TO=ToolRuleKey, PO=PackageRuleKey, PR=ProfileRuleKey, IN=InsightRuleKey — rule keys, NOT *Out keys), `*Out` tables.

## Primitives to implement (~15 used by PROD, plain PHP)

Aggregation: sum, sumResponseValue, average. Arithmetic: add/subtract/multiply/divide by constant or by another value. Conversion: convertResponse (recode via ResponseWeightDetails), convertPZSD (**gender-split norm lookup** — see below), calibratePZSD, convertPXI, pxiScore, pxiSpecificType, convertCCTMatrix, convertSCTMatrix, convertPXISCTMatrix, convertToolFramework, convertToArchetypeID. Selection: rankScaleOutput, top-3 selection, tie-break, boosts. Insight stage: key-code generation + content persistence only (no math).

Full op-frequency inventory per stage: CLAUDE-NOTES.md. Legacy proc bodies (reference implementations): `original-source/databasescript20260225.sql` (UTF-16LE).

## Dependency matrix (CONFIRMED from rule data — drives modular scoring)

| Dimension | missions | motivators | abilities | person | expectations | style | role | org |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| M | ● | ● | | | | | | |
| C | | | ● | | | | | |
| S | | | | ● | ● | ● | | |
| P (composite of M+C+S) | ● | ● | ● | ● | ● | ● | | |
| R | | | | | | | ● | |
| O | | | | | | | | ● |

Critical subtleties: S is anchored on the `person` tool (so full `mcs` needs six self tools); P is computed FROM the M/C/S dimensions (archetype-convert → sum → percentage → average), not from the person tool alone; R and O are single-tool standalone; reflections are stored verbatim, never scored.

## Scoring scopes (requestable à la carte)

| Scope | Input needed |
|---|---|
| mcs.m | missions + motivators (54 Qs) |
| mcs.c | abilities (63 Qs) |
| mcs.s | person + expectations + style (222 Qs) |
| mcs | six self tools |
| pro.role / pro.org | role / organization (54 Qs each) |
| pro.person | six self tools |
| insights | superset of requested dimensions |
| reflections | reflections only |
| full | all 9 tools |

## Gender in scoring (verified — do not regress this)

`convertPZSD` looks up `PZSDConversionMatrix` by (gender, scale, raw) — M/F tables differ in 106 of 260 mappings (mean Δ0.025, max 0.21, scale 168 largest). Gender therefore affects S → mcs, P, insights. The norm lookup takes a **norm group** parameter (male/female/pooled/versioned set) — see 06-norms.md. Legacy DISC matrices are also gender-split but retired (v3.5 uses PZSD).

## Correctness contract

The engine MUST reproduce legacy outputs exactly for all 68 golden masters when run with male/female norms (10-verification.md). Implement primitives against the legacy proc bodies, not against assumptions.
