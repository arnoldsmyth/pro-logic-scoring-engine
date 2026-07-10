# 11 — Products & Roadmap

The engine is product-agnostic (04-scoring-engine.md); products are package data + content. Current and candidate products:

## 1. PRO-D (v2 launch product)

The live product (legacy "PRO Development 3.5"). All scopes per 04. Full multilingual content en/fr/pt.

## 2. Enneagram Map (derivative product — active concept)

**Positioning:** a new take on the Enneagram. No wings — deliberately (owner judgment: wings are flawed). Any of the 9 types can appear in a person's top three. Where classic Enneagram reads motivation only, this product maps **motivation (M) + competency (C) + style/behavior (S)** per type — a richer, three-lens type profile.

**The mapping already exists in TAI's own documentation** (2019 Technical Report/Validation Report — each CVA is explicitly assigned an Enneagram type, with an Enneagram wheel + "need" table):

| # | Enneagram type | CVA | Action phrase |
|---|---|---|---|
| 1 | Reformer | societal_change | Influencing Opinions |
| 2 | Helper | human_development | Helping People |
| 3 | Achiever | strategic_decisions | Managing Plans |
| 4 | Individualist | artistic_creativity | Designing Innovations |
| 5 | Investigator | theoretical_discovery | Answering Questions |
| 6 | Loyalist | production_efficiency | Maintaining Order |
| 7 | Enthusiast | motivational_energy | Energizing Others |
| 8 | Challenger | entrepreneurial_challenge | Taking Risks |
| 9 | Peacemaker | natural_appreciation | Keeping Balance |

**Build shape:** likely a new package over existing tools (six self tools → M/C/S per type) + Enneagram-framed content tables + a type-numbering view layer. CAUTION: internal CVA indices (CVA1..9 in legacy tables) are NOT Enneagram numbers — always map through the table above. Two of the doc's "need" labels are non-canonical (Loyalist→"need for perfection", Reformer→"need to reform"; canonically 1=perfection/right, 6=security/loyalty) — reconcile wording before marketing as Enneagram-accurate.

**Royalty:** derivative code type → no royalty (07).

**IP note:** "Enneagram" as a concept is public domain; specific instruments (RHETI) and Enneagram Institute type descriptions are proprietary — write original content.

## 3. MCS Development (TM) (dormant, revival = config)

PROD minus reflections + minus Org/Person/Role ACT appendices; 73 of 79 outputs, 8 tools. Complete legacy kit (rules, 4-language templates, charts, C# classes); used on 7 projects. In v2: a scope preset / package. Decision pending.

## 4. General Satisfaction Survey (dormant, trivial)

7-question feedback form (en/fr/pt), no scoring/report ever existed. In v2: simple survey endpoint + net-new dashboard if wanted.

## 5. Not carrying forward

Role Report (already absorbed into PROD 3.5; standalone version = packaging decision on existing data). Human Synergistics scales (orphaned labels; full build + third-party licensing — decommissioned).

## 6. Validation Report 3.0 (recommended project)

Existing evidence (2010 overview + 2019 Monahan/Fazio report, n=8,560, data 2004–2018) has real gaps: alpha for only 4 of 7 scales (Mission/PXI/Personal Concept missing; ipsative Mission tools need non-alpha methods); "CFA via varimax" contradiction, no loadings/fit indices; weak scales (Observing, Producing < .70); inter-CVA correlations up to .735 undercutting 9-factor claim; no test-retest; criterion evidence anecdotal; no gender invariance/DIF testing despite gender-split norms; zero non-English validation; norming methodology never documented. The v2 analytics layer (06-norms.md) collects exactly the data a new report needs; comparators should modernize (Big Five/HEXACO/O*NET). Full digest of the four source documents: agent report referenced in CLAUDE-NOTES; source docs in `Technical Overviews/`.
