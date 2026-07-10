# PRO-D Black Box — Codebase Knowledge (session-tracked learning)

> **Canonical build spec: `docs/00-INDEX.md`** (12 section docs, written for a build agent). This file = exploration knowledge/history. Git repo created + build started 2026-07-10; phases 1–3 done (see repo README + beads).
> Technical Overviews reviewed 2026-07-10: PRO-D's 9 CVAs are explicitly Enneagram-derived (2019 report contains the full CVA↔type mapping + wheel). Validation evidence n=8,560 (2004–18) but has serious gaps (see docs/11 §6) → Validation Report 3.0 recommended. Enneagram Map product concept recorded in docs/11 §2 (no wings, top-3, M/C/S three-lens). Royalty codes: training/bizdev/derivative (derivative = no royalty) in docs/07. UI must self-document mechanics (docs/08).

Last updated: 2026-07-10. Purpose: rebuild the TAI scoring backend (a.taiinc.com/TAIWebService/TAIWS.svc) in a modern stack. Arnold owns the calling website (PRO-D, PHP/CodeIgniter + Firebase) and now has the server side.

## System map (legacy, ~2013–2016 era, .NET Framework + SQL Server)

Pipeline: partner site POSTs JSON `register` → TAIWS.svc (WCF REST, **source NOT in repo**) validates + inserts `Session` + `WebServiceRequestQueue` row → scheduled console EXEs do the work → results POSTed back to partner callback URL.

Components in `original-source/`:
1. **TAIWebServiceCommandConsole** — cron "poker"; POSTs `processrequestqueue` / `sendreportresults` to the service. No business logic.
2. **CMSPrintSessions** — core engine. `ScoreSessions()` calls `sp_Score`; `PrintSessions()` builds report. Web-service sessions get **JSON results only** (no Word doc); portal sessions get Word COM + macro-driven mail merge + WinZip. Writes a ~230-column CSV `tmsexprt.txt` as the Word handoff.
3. **PRODEmailing** — emails report .zip to project leaders (LLBLGen Pro ORM, assembly not in repo). Explicitly excludes API/web-service sessions.
4. **databasescript20260225.sql** — full TMS DB: ~152 tables, 171 procs, 16 views. **All scoring IP lives here.**
5. **TMS202602260457.bak** — 7.3GB SQL Server backup (full data incl. rule/matrix config rows — needed for port).
6. **Templates/** — Word .dot templates (macros embedded; the "real" report layout). Repo README says fetch latest from server.
7. **Instructions for API/** — JSON message docs + `TAI_PROD_API_SCORING_INTERACTIONS.md` (excellent doc of website↔TAI interactions, written from the PRO-D side).

## Scoring engine (the crown jewels)

- `sp_Score` orchestrates: `sp_ScoreTools → sp_ScorePackage → sp_ScoreProfile → sp_ScoreInsight`, gated by `*Status.Passed`, stamps `Session.ScoreDate`.
- It is a **data-driven interpreter**: rules stored as rows in `*Rule/*RuleType/*RuleParams/*RuleSource` tables; drivers cursor over rules and dynamically EXEC ~30 tiny primitive procs (`sp_Sum`, `sp_Average`, `sp_MultiplyByValue`, `sp_MatrixProduct`, `sp_ConvertDISC/PZSD/PXI/CCT`, `sp_RankScaleID`, `sp_GetTop3`, boosts, tie-breaks...).
- Math is elementary: recode/weight responses → aggregate to scale scores → arithmetic transforms → matrix/lookup conversions (norms precomputed as data — no live stats) → rank/select → `SessionResults` + `SessionOutString` (report prose).
- Model: 9 mission areas × M/C/S (motivator/competency/style) and P/R/O (person/role/org).
- Portability: HIGH. Port = migrate rule/matrix config rows + reimplement primitives + dispatch loop in app code.

## API contract (JSON over HTTP, root .../TAIWS.svc/)

- Ops: `register`, `getreportresults`, `processrequestqueue`, `sendreportresults`. All carry `credentials{username,password}`, `transaction`, `transactionid`.
- `register`: registrationinfo (name/email/projectcode/language/gender/dob...), assessment `{type:"prod", tools:[{tool, responses:[{q,a}]}]}` — tools: reflections, personalmotivators, areamissions, abilitiesfilter, personalstyle, personalexpectations, person, role, organization. `externalid` = PRO-D assessment_link_code `{order_id}::{token}`.
- Async: immediate ack (responsecode 0/1), then callback #1 `registerresponse` (session_id), callback #2 `reportresults` (wsReport: MCS + PRO per 9 mission areas) → PRO-D `Webhooks::pro_d_callback()`, then PRO-D builds PDF from Firebase reportResults.
- Queue = `WebServiceRequestQueue` table (Status: -1 err, 0 new, 1 validating, 2 validated, 3 scoring, 4 scored, 5 posting, 6 posted); `WebServiceUsers.PostURL` = partner callback.
- PRO-D order statuses: 4 sent → 5 processing → 6 completed / 13 scoring error.

## Gaps / risks for rebuild

- **TAIWS.svc source missing** — must infer from DTOs (WSClasses), queue schema, and the PRO-D-side md doc (which is thorough).
- Word/Excel/WinZip COM path only matters for non-API sessions — PRO-D already renders its own PDF from JSON, so the rebuild can drop Word entirely for the API path.
- Legacy has pervasive SQL injection, plaintext secrets in configs (rotate everything before/at cutover), mojibake in FR/PT email bodies, latent bugs (`getExternalID` register/getreportresults branch bug).
- LLBLGen assembly missing (PRODEmailing won't build) — irrelevant to rebuild.
- Full DB backup (.bak) is the source of truth for rule/matrix/norm data — restore into SQL Server (Docker mssql works) to extract config + test fixtures.

## Rebuild decisions (2026-07-10)

- **No legacy compatibility needed** — PRO-D is also being rebuilt.
- Stack: **Laravel (PHP 8) API + React control panel**, Postgres. Deploy DO App Platform (or GCP Cloud Run), git-push auto-deploy, OpenAPI 3.1 published.
- Control panel: API key management, per-key stats/rate limits. **Usage metered per key from v1; Stripe royalty billing designed-for but built later.**
- Scoring becomes **modular by scope**: mcs (5 scored SELF tools) / pro.person|role|org (each standalone) / insights (SELF+PRO) / reflections (standalone echo). Legacy required all 9 tools; engine is structurally package-based so partial packages are legitimate.
- Sync scoring by default (async existed only because of Word/batch EXEs); optional webhooks.
- Verification: golden-master replay vs legacy sp_Score after restoring .bak.
- Full spec: **SPEC-TAI-SCORING-API.md** (input catalog: 9 tools, 475 Qs, formats/validation; output catalog: mcs/pro/insights/reflections field lists; dependency map; endpoints).

## Input/output quick facts

- Tools & counts: reflections 28 (free text), personalmotivators 27 + areamissions 27 (rank 3/2/0: exactly 3×"3", 3×"2"), abilitiesfilter 63 (1–6), personalstyle 96 (24 groups of 4: one 1/most, one -1/least), personalexpectations 72 (1–4), person/role/organization 54 each (1–6; DB names OIPro54*). All answers single scalars.
- Outputs: mcs {m,c,s} + pro {p,r,o} ranks 1–9 across 9 CVAs; `etc` narrative (central theme, lead anchors, key traits, cautions, roles, job functions, mentor/protégé, majors, industries...); reflections echoed. Formats: 1=keys, 2=strings; stored in SessionResults.JSONResultsKeys/Strings; enumerated by SessionOutStringType.
- Legacy PROD = PackageKey 4 / PackageVersion 27. Error codes E00–E31 (E10 required field, E2x project code, E30 tool validation).
- **Restore kit:** `restore-db/` — 1-restore.sh (Docker SQL Server 2022 amd64/Rosetta, container `tai-sql`, sa password `TaiLocal!2026`, volume tai-sql-data) + 2-extract.sh (dumps rule/matrix/config tables to `restore-db/extracted/*.csv`, pipe-delimited, + _table_rowcounts.csv + recent scored sessions list). Arnold runs on his Mac; Claude's sandbox can't (no Docker, 3GB RAM).
- **Restored TMS db runs at legacy compatibility level (~80, SQL 2000 era)** — modern T-SQL (STRING_AGG, maybe TRY_CONVERT) fails inside it; run modern queries from `master` with fully-qualified `TMS.dbo.*` names. Do NOT raise the compat level — could alter sp_Score behavior and invalidate golden masters. Session table has no LanguageKey in SessionDetail.
- **CONFIRMED from extracted rule data (2026-07-10):** M ← missions+motivators; C ← abilities; S ← person+expectations+style (S anchored on OI-54-Person!); P = composite of M+C+S dimensions (NOT the person tool alone); R ← role only; O ← org only. Reflections stored verbatim, never scored. Gender/DOB unused in scoring. DISC retired in v3.5 (PZSD replaces it). ~15 math primitives total. Insight layer = key-codes + text storage only. PROD chain: VersionControl 18 → PackageVersion 27, ProfileVersion 19, InsightScoreVersion 21 → InsightOut 23. Active ToolVersions: 49 PZSD, 13 PXI, 25/26/27 OI-54 O/P/R, 28 Abilities vB, 29 Reflections, 48 Motivators vB2, 47 Missions.
- **WebServiceUsers (only 2 active callers):** Navitend (phantom endpoint) + PRO-D bluesage (app.pro-d.com webhook). No 3rd-party compat needed. Extracted CSVs contain live credentials — rotate at cutover, keep extracts out of public repos.
- **Extraction learnings:** extracted CSVs in restore-db/extracted/ (pipe-delimited). SrcType decode: TV=raw tool input, TO=ToolRuleKey, PO=PackageRuleKey, PR=ProfileRuleKey, IN=InsightRuleKey (rule keys, NOT *Out keys). Container sqlcmd v18.6: -W/-y and -h/-y mutually exclusive; BSD grep caps {n} at 255.
- **GENDER CORRECTION (2026-07-10):** gender IS used in scoring — sp_ConvertPZSD joins PZSDConversionMatrix on Gender (dynamic SQL, invisible to rule-data scans; 106/260 M/F mappings differ). Affects S → MCS/P/insights. Gender required for S/P scopes. Lesson: always grep proc bodies for dynamic-SQL joins, not just rule data.
- **Languages:** en/fr/pt 100% symmetric content (incl. results prose — format 2 returns resolved strings). Turkish = empty slot. German exists only on PRO-D side (client translates keys). Adding a language = data-only (translate ~8.3k content rows) + cultural/norm caveats for es/ar.
- **Royalty model:** per report, not always due; access-code system (code → permitted scopes + fee 0..n). v2: access_codes table + usage events → Stripe later.
- **Hidden gems:** MCS Development (TM) complete second package (PV28/VC19, MAPD insights, own templates); General Satisfaction Survey package; Role Report variant (3.3C/3.4, dormant in 3.5); 299 SessionOutStringTypes vs ~230 exported; Human Synergistics/Org Style/Capture scales; PROD 3.2–3.4 DISC-based rule sets intact.
- **Norms design (2026-07-10):** norm sets = versioned data entities (candidate→active→retired, provenance, per-result norm_set id recorded). `norms` param on score call: male|female|pooled|<id>. Pooled-v1 derived empirically from 16.7k historical sessions (NOT by averaging M/F tables); impact quantified by rescoring history. New-language pipeline: provisional norms → collect sample (≥300–400/scale) → candidate → impact report → human promotes. M/F PZSD table deltas: mean 0.025, max 0.21 (scale 168 worst). Within-group norming banned in US employment *selection* (CRA 1991) — pooled default is the safer corporate posture.
- **Dormant products profiled (2026-07-10):** (1) MCS Development = PROD minus Reflections, minus Org/Person/Role ACT appendices; 73 of 79 outputs, 8 tools, complete 4-language templates + charts + C# classes; used on 7 projects mid-life; revive = config/QA only — best candidate. (2) GSS = 7-question feedback form (en/fr/pt), NO scoring/outputs/report ever; used on 13 projects incl. near end-of-life; v2 equivalent = simple survey endpoint + net-new dashboard. (3) Role Report (3.3C "Chase"/3.4) = already absorbed into PROD 3.5 (strict superset); only Chase-specific outputs dormant; standalone role report = packaging decision. (4) Human Synergistics = orphaned scale labels (12-style circumplex) with NO tool/questions/rules/procs; full build + 3rd-party licensing to revive — treat as decommissioned.
- **Golden master set COMPLETE (2026-07-10): 68 sessions, 34F/34M**, en + fr + pt-BR client languages (all submitted to TAI as "en"; real client language recorded in goldens/_client_languages.csv, order↔session map in goldens/_orders.csv). Scripts: 3-goldens.sh (diverse auto-pick, arg = extra random count), 4-goldens-byorder.sh (fr orders baked in, or pass order ids), 5-goldens-pt.sh (pt orders). Edge case: order 13557/session 18125 queue Status -1 but fully scored. PT orders ≥15204 postdate the .bak snapshot (backup 2026-02-25); 15058/14613/14547 never reached TAI. Old sessions have outstrings purged (JSON results survive — that's the API contract).
- **Golden masters:** restore-db/3-goldens.sh extracts ~30 diverse sessions (recent + F + non-en + random) as request.json (actual register payload) + register_response.json + results_keys/strings.json + outstrings.csv per session, indexed in goldens/_index.csv. PRIVACY: request JSONs contain real names/emails — goldens/ stays local, never in public repo.
- **Hosting decision:** DO App Platform or Google Cloud Run + Cloud SQL MySQL — API stays self-contained (Laravel + MySQL only; no Firebase storage/Firestore in the scoring API; Firebase remains a PRO-D-frontend concern).
- **Product extensibility (2026-07-10):** v2 must port the engine as a DATA-DRIVEN rule interpreter, not hardcoded PROD logic — new products (e.g. Enneagram: 9 type scales → rank → wing → content; fits existing 9-area/archetype/content model, needs ~1 new primitive for adjacent-wing selection) become package data + content, zero code. Enneagram caution: concept is public domain but instruments (RHETI) and Enneagram Institute descriptions are proprietary — need original items + validation study.
- **Open items:** carry MCS-Dev into v2 as a product/scope preset?; norm threshold + promotion policy sign-off. (Both filed as beads issues.)
- **Build start (2026-07-10):** Laravel **12** not 11 (11.x EOL'd security support 2026-03 with open advisories Composer blocks — docs/09 deviation noted in README). `api/` app: `legacy:import` (76 config tables, 53,518 rows, schema inferred from CSVs, legacy names verbatim; WebServiceUsers excluded — live creds) + `goldens:verify` (68 sessions found; per-field diff vs results_keys.json; SKIP until engine exists). `_table_rowcounts.csv` uses sys.partitions.rows = APPROXIMATE — ToolValidValue actual 16722 vs manifest 16717; extract file is truth. ToolValidValue is a validation LOG table (session ack strings + GUIDs), not scoring config. Beads issue tree: phases 1–8 epics chained + 2 decision issues (prefix pro-d-scoring-engine).
