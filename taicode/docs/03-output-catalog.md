# 03 — Output Catalog

All fields scoring can produce, grouped into the four output sections. Source of truth for exact shapes: `restore-db/goldens/*/results_keys.json` and `results_strings.json` (real production outputs).

## The 9 Career Value Areas (CVAs)

Used across all sections: societal_change, theoretical_discovery, strategic_decisions, human_development, entrepreneurial_challenge, production_efficiency, artistic_creativity, natural_appreciation, motivational_energy. (Each maps to an Enneagram type — see 11-products-roadmap.md.)

## Section `mcs` — trait rankings

Per CVA: `m` (Mission/motivation rank), `c` (Competency rank), `s` (Style rank), each 1–9 (1 = strongest... verify polarity against goldens). Plus top-3 code names and per-code values (legacy Mcode/Ccode/Scode, MCT/CCT/SCT columns).

## Section `pro` — person/role/organization alignment

Per CVA: `p`, `r`, `o` ranks 1–9 + top-3 names/values per perspective + perspective summary texts (per_perspective, rol_perspective, org_perspective).

## Section `insights` (legacy `etc`) — narrative

Two reference types: archetype details and insight details. Deliverable as keys or resolved strings (see formats below).

| Group | Fields |
|---|---|
| Theme | central_theme, career_values_imp |
| Trait implications | mct/cct/sct_trait_impl |
| Lead anchors | lead_anchor_1..3 |
| Key traits | s_keytrait_1..3 (+desc) |
| Cautions | s_caution_1..3 (+desc) |
| Roles | role_1..12, roledesc_1..3 |
| Job functions | job_function_1..3 (+desc a/b/c) |
| Development | educkey_1..3, develsugg_1..3 |
| Culture | culturalpref_1..3 |
| Mentor / Protégé | mnt_/prot_ pos/neg/conc_1..3 |
| Metaphor | combo_metaphor (+desc) |
| Career pointers | industry_field_1..5, college_major_1..5, role_position_1..5, organization_type_1..5 |

## Section `reflections` — echo

RoleAtWork_1..6, KeyDevNeed_1..4, FiveYearRole_1..6, Best_Qual_1..6, Least_Qual_1..6 — verbatim from input, no computation.

## Formats

- `keys`: stable keys (archetype_detail_key / insight_detail_key) — client translates via `GET /v2/reference/translations?language=`.
- `strings`: server-resolved text in the requested language (en/fr/pt fully populated: 7,308 InsightDetail + 360 ArchetypeDetail + 663 LookupDefinition strings each).

Legacy stored both per session (SessionResults.JSONResultsKeys/Strings); v2 renders `strings` on demand from the keys + content tables (don't store twice).

## Audit trace (new in v2 — explainability requirement)

Every scored result MUST be able to return an optional audit trace: rules fired in order, intermediate scale values per stage, norm set + version used, content keys resolved. This backs the control panel's "under the hood" documentation (08-control-panel.md) and debugging against golden masters.
