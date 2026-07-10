# TAI Scoring API v2 — Data & Architecture Specification

> **SUPERSEDED 2026-07-10 by the `docs/` folder — start at [docs/00-INDEX.md](docs/00-INDEX.md).**
> This file is kept for history only; the docs/ set adds code-type royalties, the Enneagram Map product, the under-the-hood UI requirement, and the validation-report findings.

Status: DRAFT v0.1 — 2026-07-10
Decisions locked: Laravel (PHP 8) API + React control panel · deploy DigitalOcean/GCP with git-triggered builds · OpenAPI 3.1 spec published · usage metered per key from day one, Stripe billing enabled later · **no legacy compatibility required** (PRO-D is also being rebuilt).

---

## 1. What the system does

Scores the PRO-Development assessment. A client submits a candidate's responses to up to 9 instruments ("tools"); the engine produces trait rankings across 9 Career Value Areas (CVAs) plus narrative insights. Legacy required all 9 tools in one monolithic submission; v2 makes submission and scoring **modular by section**.

The 9 CVAs (used everywhere): societal_change, theoretical_discovery, strategic_decisions, human_development, entrepreneurial_challenge, production_efficiency, artistic_creativity, natural_appreciation, motivational_energy.

---

## 2. INPUT catalog — everything that can be submitted

### 2.1 Registration info

| Field | Type | Required | Rules |
|---|---|---|---|
| firstname | string | yes | |
| middlename | string | no | |
| lastname | string | yes | |
| email | string | yes | valid email |
| language | string | yes | ISO 639-1 (legacy: en, fr, pt) |
| gender | char | yes | M / F (legacy-enforced; revisit for v2) |
| dob | date | no | legacy M/D/YYYY; v2: ISO 8601 |
| external_id | string | no | client correlation key, echoed everywhere |
| project/client scoping | — | — | legacy `projectcode`; v2 replaced by API key + optional `group` tag |

### 2.2 The nine tools (response instruments)

Every answer is a single scalar `{q, a}`; no multi-part answers exist.

| Tool | Qs | Answer format | Validation |
|---|---|---|---|
| reflections | 28 | free text | q1–6 roles, q7–12 best, q13–18 worst, q19–22 needs, q23–28 five-year role/why pairs. No range check. |
| personalmotivators | 27 | ranking: exactly 3ד3", 3ד2", rest "0" | count-per-value + total=27 |
| areamissions | 27 | ranking: same 3/2/0 scheme | same |
| abilitiesfilter | 63 | integer 1–6 | range + count=63 |
| personalstyle | 96 | forced choice in 24 groups of 4: one "1" (most), one "-1" (least), two "0" | per-group counts + total=96 |
| personalexpectations | 72 | integer 1–4 | range + count=72 |
| person | 54 | integer 1–6 | range + count=54 (DB: OIPro54Person) |
| role | 54 | integer 1–6 | range + count=54 (OIPro54Role) |
| organization | 54 | integer 1–6 | range + count=54 (OIPro54Organization) |

Total: 475 questions if everything is submitted.

### 2.3 Logical input sections (v2 grouping)

- **SELF** (6 tools): reflections, personalmotivators, areamissions, abilitiesfilter, personalstyle, personalexpectations → 313 questions
- **PRO** (3 tools): person, role, organization → 162 questions. Independently: person / role / organization each submittable alone.
- **REFLECTIONS** is pass-through text — usable standalone.

### 2.4 Legacy request/response mechanics (reference only — being replaced)

Auth = username/password in body; ops register / getreportresults / processrequestqueue / sendreportresults; async callbacks to partner PostURL; `results.format` 1=keys 2=strings; error codes E00–E31. v2 replaces all of this (see §5) but keeps the error taxonomy as a starting point.

---

## 3. OUTPUT catalog — every field scoring can produce

### 3.1 Section: `mcs` — trait rankings (core scores)

Per CVA (×9): `m` (Motivator rank), `c` (Competency rank), `s` (Style rank), each 1–9. Legacy also exposes top-3 code names (Mcode/Ccode/Scode 1–3) and per-code values (MCT/CCT/SCT #1–9).

### 3.2 Section: `pro` — person/role/organization alignment

Per CVA (×9): `p`, `r`, `o` ranks (1–9), plus top-3 names/values per perspective (Pcode/Rcode/Ocode, PerACT/RolACT/OrgACT). Includes perspective summaries: per_perspective, rol_perspective, org_perspective.

### 3.3 Section: `insights` (legacy `etc`) — narrative fields

Two reference types: **archetype** details and **insight** details; deliverable as keys (for client-side translation) or resolved strings, in any supported language.

| Group | Fields |
|---|---|
| Theme | central_theme, career_values_imp |
| Trait implications | mct_trait_impl, cct_trait_impl, sct_trait_impl |
| Lead anchors | lead_anchor_1..3 |
| Key traits | s_keytrait_1..3 (+ desc) |
| Cautions | s_caution_1..3 (+ desc) |
| Roles | role_1..12, roledesc_1..3 |
| Job functions | job_function_1..3 (+ desc a/b/c) |
| Development | educkey_1..3, develsugg_1..3 |
| Culture | culturalpref_1..3 |
| Mentor | mnt_pos/neg/conc_1..3 |
| Protégé | prot_pos/neg/conc_1..3 |
| Metaphor | combo_metaphor (+ desc) |
| Career pointers | industry_field_1..5, college_major_1..5, role_position_1..5, organization_type_1..5 |

### 3.4 Section: `reflections` — echoed narrative

RoleAtWork_1..6, KeyDevNeed_1..4, FiveYearRole_1..6, Best_Qual_1..6, Least_Qual_1..6 (straight from validated reflections input; no computation).

### 3.5 Formats

`keys` (stable numeric/string keys + separate translation endpoint) and `strings` (resolved text in requested language). v2 default: both available on any result, chosen by query param.

---

## 4. Dependency map → modular scoring design

**CONFIRMED 2026-07-10 from restored rule data** (PROD = PackageKey 4 / PackageVersion 27 / ProfileVersion 19 / VersionControl 18 "PRO Development 3.5"; analysis of ToolRule/PackageRule/ProfileRule/InsightRule + *RuleSource CSVs in `restore-db/extracted/`).

Tool → dimension matrix:

| Dimension | missions | motivators | abilities | person | expectations | style | role | org |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| M (MCT) | ● | ● | | | | | | |
| C (CCT) | | | ● | | | | | |
| S (SCT) | | | | ● | ● | ● | | |
| P (Person) | ● | ● | ● | ● | ● | ● | | |
| R (Role) | | | | | | | ● | |
| O (Org) | | | | | | | | ● |

Key findings (corrections to earlier assumptions):

- **S (Styles) is anchored on OI-54-Person** — so `mcs` needs all SIX scored self tools including `person` (not five).
- **P is a composite** of the M+C+S dimensions (archetype-convert → sum → percentage → average). It cannot be computed from the `person` tool alone.
- **R and O are truly standalone** — each computed from its single 54-question tool only.
- **Reflections are never scored** — stored verbatim (sp_StoreProDReflections) and echoed to output.
- **Gender IS used in scoring** (correction 2026-07-10, verified in proc code + matrix data): `sp_ConvertPZSD` joins `PZSDConversionMatrix` on Gender; 106 of 260 M/F raw→normed mappings differ. Gender-split norms affect the Personal Style (PZSD) normalization → S dimension → MCS, P, and downstream insights. `gender` stays REQUIRED for any scope touching S/P. (Legacy DISC norms were also gender-split.) DOB remains unused in scoring. The earlier "gender-blind" note was wrong — the join lives in dynamic SQL, invisible to rule-data scans.
- Insight outputs inherit their dimension's tool set exactly (MCT insights ← missions+motivators; CCT insights ← abilities; SCT insights ← person+expectations+style; Person-level insights ← all six).
- Whole PROD engine uses only ~15 math primitives (sum, average, divide/subtract/multiply, sp_ConvertResponse/PZSD/PXI/CCT/SCT/PXISCT/ToolFramework, rank, convert-to-archetype); the Insight layer is key-code generation + text persistence, no math.
- DISC was retired in v3.5 (replaced by PZSD); sp_ConvertDISC/CalibrateDISC unused.

v2 scoring scopes (requestable à la carte):

| Scope | Input needed | Outputs returned |
|---|---|---|
| `mcs.m` | missions + motivators (54 Qs) | M ranks + MCT insights |
| `mcs.c` | abilities (63 Qs) | C ranks + CCT insights |
| `mcs.s` | person + expectations + style (222 Qs) | S ranks + SCT insights |
| `mcs` | all six self tools (313 Qs w/o reflections) | §3.1 + M/C/S insights |
| `pro.role` | role (54 Qs) | R ranks + role insights |
| `pro.org` | organization (54 Qs) | O ranks + org insights |
| `pro.person` | all six self tools | P ranks + person insights |
| `insights` | superset of requested dimensions | §3.3 |
| `reflections` | reflections (28 free-text) | §3.4 echo |
| `full` | all 9 tools | everything |

---

## 5. v2 API design

### 5.1 Principles

REST + JSON, OpenAPI 3.1 spec auto-published at `/openapi.json` + docs UI. Auth: `Authorization: Bearer <api_key>`. Synchronous scoring by default (the math is milliseconds once out of cursor-driven T-SQL); optional webhook for clients who want async. Idempotency via client `external_id` + `Idempotency-Key` header.

### 5.2 Endpoints (draft)

```
POST /v2/assessments                 create assessment (registration info; returns assessment_id)
PUT  /v2/assessments/{id}/tools/{tool}   submit/replace one tool's responses (validated on write)
POST /v2/assessments/{id}/score      body: {scopes:["mcs","pro",...], format:"keys"|"strings", language,
                                            norms:"male"|"female"|"pooled"|<norm_set_id>}   # default: per-key policy
                                      → 200 with results, or 422 listing missing tools per scope
POST /v2/score                       one-shot: registration + tools + scopes in one call (convenience)
GET  /v2/assessments/{id}            status: which tools received/valid, which scopes scored
GET  /v2/assessments/{id}/results?scope=&format=&language=   retrieve stored results
GET  /v2/reference/questions?tool=&language=    question text per tool/language
GET  /v2/reference/translations?language=       key→string maps (for format=keys consumers)
```

Incremental model: client can submit tools over time (multi-page questionnaire) and score whatever is complete. Validation errors are structured: `{tool, q, rule, expected, got}` — replaces E30 blob.

### 5.3 Result envelope

```json
{ "assessment_id":"...", "external_id":"...", "scored_at":"...",
  "language":"en", "format":"keys",
  "scopes": { "mcs": {...9 CVAs...}, "pro": {...}, "insights": {...}, "reflections": {...} } }
```

---

## 6. Control panel (React SPA on the Laravel API)

- **Access management:** clients/orgs, API keys (create/rotate/revoke, per-key scopes + rate limits, test vs live keys).
- **Stats:** calls per key/day, scores by scope, error rates, latency; assessment volumes; exportable.
- **Billing-ready metering:** every scoring call writes a usage event (key, scope(s), timestamp, billable units). Stripe later = map keys→customers, push metered usage, Cashier for invoicing. No schema rework needed.
- **Content admin (phase 2):** view rule/norm tables, translations, question text versions.

## 7. Architecture & deployment

- Laravel 11 API + MySQL 8 (Arnold's preference/familiarity; Postgres equally viable — nothing in the design is DB-specific, keep it swappable). Rule/norm/translation data migrated from the .bak; scoring engine as plain PHP services — port the ~30 primitives + dispatch DAG, drop cursors/dynamic SQL. Percentile computation for norm derivation done in PHP analytics jobs, not SQL.
- React panel served as static build; Laravel Sanctum for panel auth, hashed bearer keys for API.
- Deploy: DigitalOcean App Platform (git push → build → deploy; managed Postgres) — simplest fit. GCP Cloud Run + Cloud SQL equally viable if preferred later; keep app 12-factor/containerized so either works.
- Environments: staging + production, both auto-deploying from branches. Secrets in platform config, never in git (legacy had plaintext creds everywhere — rotate/retire all of them).

## 8. Verification plan (golden master)

1. ~~Restore TMS202602260457.bak~~ DONE (Docker container `tai-sql`).
2. ~~Dump rule/norm/matrix/translation data~~ DONE (`restore-db/extracted/`) → migration seeds.
3. Pick N real scored sessions + synthetic edge cases; replay through legacy `sp_Score`; capture SessionOutString/JSONResults.
4. CI test suite asserts v2 engine reproduces identical outputs per scope (using `male`/`female` norms — pooled norms have no legacy equivalent and are validated separately, §8.2).

### 8.1 Pooled-norm derivation (migration step)

1. From the .bak: pull all valid PZSD raw scale scores (ToolScaleValue for scales 168–171) across the 16.7k historical sessions, tagged with language and gender.
2. Compute the empirical raw→cumulative-proportion distribution per scale over the pooled sample (mirroring how the M/F tables map raw→0..1). Smooth sparse tails.
3. Store as norm set `pooled-v1` with full provenance (source sample size, date range, composition by gender/language).
4. Validate: rescore historical sessions under pooled vs. original gendered norms; report % whose top-3 M/C/S codes change (expected: small — mean M/F table delta is 0.025). Publish that number to clients as the documented trade-off.

### 8.2 Norm lifecycle — continuous evaluation & new languages

Norms are **versioned data, never code**:

- `norm_sets`: id, scale coverage, population filter (language, region, gender, date range), status `candidate → active → retired`, provenance (n, composition), created_from (analytics run id).
- **Every scored result records the norm_set id used** — scores stay reproducible forever, and clients are never silently rescored.
- **Analytics job** (panel-visible): continuously accumulates anonymized raw scale distributions per language/population from incoming responses; dashboards show sample size and distribution drift vs. each active norm set.
- **New-language pipeline:** launch language with `provisional` norms (pooled or nearest population, results flagged `norms:"provisional"`) → collect sample → when n reaches threshold (rule of thumb ≥300–400 per scale for stable percentiles) auto-build a `candidate` norm set → side-by-side impact report (what % of results would change) → human promotes to `active`. Old set retires but remains queryable.
- Same pipeline periodically re-evaluates mature norms (e.g. en norms are decades old — drift is likely). Recalibration is a deliberate, versioned, documented event, not silent drift.
- Caveat tracked in provenance: API respondents are a self-selected sample (people whose employers bought assessments), not a general-population sample. Fine for consistency; worth noting in any psychometric claims.

## 9. Languages (decided 2026-07-10)

- **v1 carries en / fr / pt** — all three have 100% symmetric content (questions, insight prose, archetype text, lookups: 7,308 InsightDetail rows each). Turkish has a Language row but zero content (drop or leave dormant).
- **German exists only on the PRO-D side** (their questionnaire translation); the TAI DB has none. German clients today get keys back and translate client-side. To add `de` results text: translate the content tables (7,308 insight strings + 360 archetype + 663 lookups), no code changes.
- **Results in-language is a first-class option**: legacy format 2 already resolves full strings server-side; format 1 returns keys. v2 keeps both — `format=keys|strings` per request — plus `GET /v2/reference/languages` (list + coverage) and `GET /v2/reference/translations?language=` so keys-mode clients can translate themselves.
- **Spanish/Arabic:** engine-wise, adding a language is pure data (new LanguageKey + translated content rows). The real work is content: professional translation + cultural adaptation of items (work-culture concepts don't map 1:1), and ideally norm revalidation — the PZSD norms are gender-split and derived from the original (presumably North American) population; translated items can shift response distributions. Arabic additionally needs RTL handling in whatever renders reports (client-side concern; API is JSON either way). Recommend treating es/ar as a content project after launch.

## 10. Royalty / access-code model (decided 2026-07-10)

API returns **data only — no report building**. Royalty is **per report, not always due**, managed by a **code system**: a code grants permission to specific data sections and carries a fee from $0 up. v2 design:

- `access_codes`: code → allowed scopes (§4), fee amount+currency, max uses / expiry, issued-to, active flag. (Modernizes legacy SingleUseCodes/projectcode/PricePer.)
- Scoring request may carry `access_code`; the API enforces scope ⊆ code's permissions and records a usage event `{code, scopes, fee_due}`.
- Fee=0 codes = internal/demo/prepaid; fee>0 events accumulate → royalty statements → Stripe metered billing when switched on.
- Control panel manages codes (issue, bulk-generate, revoke, usage view).

## 11. Undocumented capabilities found in the data ("hidden gems")

- **MCS Development (TM)** — a complete second product: Package 15 / PackageVersion 28 / Profile 9 "MAP Development" / InsightScore "MAPD" / VersionControl 19, with its own Word templates (MCSTemplate.doc, MCSDevRpt.dot). Appears to be the self-assessment (MCS) without the PRO instruments — a ready-made "lite" product.
- **General Satisfaction Survey (TM)** — third package (Package 14, VC 14, Tool 43), a separate survey product.
- **Role Report** — InsightScoreVersion 3.3C/3.4 notes: "Role Report output added (like 3.3c, Chase version)" — a role-centric report variant built for a client, dormant in 3.5.
- **Unsurfaced output types** — SessionOutStringType has 299 types vs ~230 columns in the export; some computed outputs were never exposed.
- **Extra scales** — ToolScale includes "Human Synergistics Scales", "Org Style", "Capture" — traces of integrations/experiments beyond PROD.
- **Legacy PROD 3.2–3.4** rule sets (DISC-based) remain intact — useful only for historical rescoring.
- **UCipher Executive** Word template in Templates/ (not in ReportTemplate table) — another bespoke report variant.

## 12. Remaining open questions

1. ~~Scope boundaries~~ RESOLVED (§4). 2. ~~Gender~~ RESOLVED — required, gender-split PZSD norms (§4). 3. ~~Other callers~~ RESOLVED — only Navitend + PRO-D; extracted CSVs contain live credentials — rotate at cutover, keep extracts private. 4. ~~Languages~~ RESOLVED (§9). 5. ~~Royalty model~~ RESOLVED (§10).
6. Non-binary/unspecified gender: norms only exist for M/F. Policy decision needed (require M/F for S/P scopes vs. offer an averaged norm — the latter changes scores and needs TAI sign-off).
7. Should MCS Development and GSS packages be carried into v2 as additional products?
