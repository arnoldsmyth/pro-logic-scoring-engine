# 01 — Legacy System (what we're replacing)

The legacy stack (2013–2016 .NET Framework + SQL Server "TMS" database) scores TAI's PRO-Development assessment. Full exploration notes: `../CLAUDE-NOTES.md`.

## Architecture

Partner site POSTs JSON `register` to `http://a.taiinc.com/TAIWebService/TAIWS.svc` (WCF REST — source NOT in repo; behavior reconstructed from client DTOs, queue schema, and `original-source/Instructions for API/website accessing the api/TAI_PROD_API_SCORING_INTERACTIONS.md`). The service validates and queues into `WebServiceRequestQueue`. Scheduled console EXEs then: score via stored procedures (`CMSPrintSessions` → `sp_Score`), and POST results back to the partner's callback URL. All scoring IP lives in the database (~152 tables, 171 procs) as a **data-driven rule interpreter** — rules are rows, executed by ~15 math primitives.

## Key facts for the rebuild

- Async design existed only because scoring ran as batch EXEs + Word COM report generation. Scoring itself is milliseconds — v2 scores synchronously.
- Word/Excel/WinZip report path is dead weight: API clients only ever received JSON. v2 returns data only.
- Only 2 callers ever existed: Navitend (defunct phantom endpoint) and PRO-D (being rebuilt). **Zero backward-compatibility obligations.**
- Legacy DB compat level 100 — never raise it (golden-master fidelity). Modern T-SQL must run from `master` with qualified names.
- Legacy has pervasive SQL injection, plaintext credentials (incl. in `restore-db/extracted/WebServiceUsers.csv` — rotate at cutover; keep extracts private), mojibake in fr/pt emails.
- Live product config: VersionControl 18 = PackageVersion 27 + ProfileVersion 19 + InsightScoreVersion 21 → InsightOut 23 ("PRO Development 3.5", PZSD replaced DISC).

## Local replica (for data extraction + golden masters)

- `restore-db/1-restore.sh` — SQL Server 2022 in Docker (container `tai-sql`, sa/`TaiLocal!2026`), restores the 7.3GB `TMS202602260457.bak`.
- `restore-db/2-extract.sh` — dumps 79 config tables to `restore-db/extracted/*.csv` (pipe-delimited).
- `restore-db/3-goldens.sh`, `4-goldens-byorder.sh`, `5-goldens-pt.sh` — golden-master extraction (see `10-verification.md`).
