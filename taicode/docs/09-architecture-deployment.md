# 09 — Architecture & Deployment

## Stack (decided)

- **Laravel 11 (PHP 8.3) API + MySQL 8.** Postgres equally viable; keep DB-agnostic (no vendor-specific SQL; percentiles for norm derivation computed in PHP analytics jobs).
- **React SPA control panel** served as a static build.
- Scoring engine: plain PHP services (rule interpreter + primitives), no queue needed for scoring (synchronous); Laravel queues for webhooks/analytics jobs.
- JSON payloads (requests, results-keys, audit traces) in MySQL JSON columns alongside relational data. No Firestore/Firebase in this API; no object storage needed in v1.

## Hosting (either; keep 12-factor/containerized so both work)

- **DigitalOcean App Platform** + Managed MySQL — git push → build → deploy. Simplest.
- **Google Cloud Run** + Cloud SQL (MySQL) — if consolidating on Google. ("Firebase hosting" for Laravel = Cloud Run in practice; Firebase products stay a PRO-D-frontend concern.)

## Environments & CI

- `staging` + `production`, each auto-deploying from its branch. PRs → staging; tagged releases → production.
- CI (GitHub Actions): lint, unit tests, **golden-master suite** (10-verification.md) must pass before deploy.
- Secrets in platform config only. NOTE: all legacy credentials (DB, SendGrid, webservice users incl. values now sitting in `restore-db/extracted/`) are compromised-by-git — rotate/retire everything at cutover.

## Security baseline

Hashed API keys; parameterized queries only (legacy was injection-riddled — do not port string-concatenated SQL patterns); per-key rate limiting; audit log on panel actions; PII minimization (names/emails only where needed); goldens + extracts never enter the repo (`.gitignore`: `restore-db/extracted/`, `restore-db/goldens/`).

## Repo status

**No git repository exists yet (2026-07-10). Do not start building until it does.** Proposed layout: monorepo `api/` (Laravel), `panel/` (React), `docs/` (this folder), `migration/` (seed builders reading `restore-db/extracted/`).
