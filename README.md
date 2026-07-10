# TAI Scoring Platform v2

Rebuild of the TAI PRO-Development scoring backend (legacy: .NET + SQL Server
behind `a.taiinc.com/TAIWebService/TAIWS.svc`) as a modern API.

**The build spec lives in [taicode/docs/00-INDEX.md](taicode/docs/00-INDEX.md)**
(12 section docs — read before changing anything). Work is tracked in
[beads](https://github.com/steveyegge/beads) (`bd ready` for next available
work) with one epic per build-order phase.

## Layout

| Path | Contents |
|---|---|
| `api/` | Laravel 12 API — scoring engine, golden harness, legacy config importer |
| `panel/` | React control panel (phase 7, placeholder) |
| `taicode/docs/` | The build spec (source of truth) |
| `taicode/restore-db/` | Legacy DB replica + extraction scripts; its outputs stay out of git |
| `taicode/original-source/` | Legacy source + 7.3GB backup (untracked) |

> Deviation from docs/09: the spec says Laravel 11, but 11.x reached
> end-of-security-life (March 2026) with open advisories that Composer now
> blocks — the app is Laravel 12, same architecture.

## Never commit

`taicode/restore-db/extracted/` (live legacy credentials),
`taicode/restore-db/goldens/` (real PII), `taicode/original-source/`
(proprietary legacy source + backup). All are gitignored — keep it that way.

## Getting started

```bash
cd api
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate

# Load legacy rule/matrix/norm/content config (needs taicode/restore-db/extracted/)
php artisan legacy:import

# The correctness gate: replay 68 legacy sessions against the engine
php artisan goldens:verify

php artisan test          # unit + feature suites
```

`legacy:import` and `goldens:verify` read `taicode/restore-db/extracted/` and
`taicode/restore-db/goldens/` by default; override with `LEGACY_EXTRACTED_PATH`
/ `GOLDENS_PATH`. Regenerate either with the scripts in `taicode/restore-db/`
(requires Docker + the 7.3GB `.bak`).

## Build order (docs/00-INDEX)

1. Repo + CI skeleton ✅
2. Legacy config import ✅ (`legacy:import`)
3. Golden-master harness ✅ (`goldens:verify`)
4. Engine primitives + rule interpreter ✅ — **68/68 goldens pass, both result formats**
5. API layer + access codes ← next
6. Norm analytics
7. Control panel
8. pooled-v1 norms + docs polish
