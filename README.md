# Pro Scoring Platform v2

A product-agnostic assessment scoring API: a data-driven rule interpreter
(missions/motivators/abilities/style/expectations/person/role/organization
tools → trait rankings + narrative insights) reproducing a legacy scoring
engine exactly, then extended behind access codes so each licensed product
is catalog data, not hardcoded engine identity.

**The build spec lives in [platform/docs/00-INDEX.md](platform/docs/00-INDEX.md)**
(12 section docs — read before changing anything). Work is tracked in
[beads](https://github.com/steveyegge/beads) (`bd ready` for next available
work) with one epic per build-order phase.

## Layout

| Path | Contents |
|---|---|
| `api/` | Laravel 12 API — scoring engine, golden harness, legacy config importer |
| `panel/` | React control panel (phase 7, placeholder) |
| `platform/docs/` | The build spec (source of truth) |
| `platform/restore-db/` | Legacy DB replica + extraction scripts; its outputs stay out of git |
| `platform/original-source/` | Legacy vendor source + 7.3GB backup (untracked, historical reference only) |

> Deviation from docs/09: the spec says Laravel 11, but 11.x reached
> end-of-security-life (March 2026) with open advisories that Composer now
> blocks — the app is Laravel 12, same architecture.

## Never commit

`platform/restore-db/extracted/` (live legacy credentials),
`platform/restore-db/goldens/` (real PII), `platform/original-source/`
(proprietary legacy vendor source + backup). All are gitignored — keep it
that way.

## Getting started

```bash
cd api
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate

# Load legacy rule/matrix/norm/content config (needs platform/restore-db/extracted/)
php artisan legacy:import

# The correctness gate: replay 68 legacy sessions against the engine
php artisan goldens:verify

php artisan test          # unit + feature suites
```

`legacy:import` and `goldens:verify` read `platform/restore-db/extracted/` and
`platform/restore-db/goldens/` by default; override with `LEGACY_EXTRACTED_PATH`
/ `GOLDENS_PATH`. Regenerate either with the scripts in `platform/restore-db/`
(requires Docker + the 7.3GB `.bak`).

### MySQL 8 (production parity)

SQLite is the zero-setup dev default; MySQL 8 is the production target
(docs/09). `api/compose.yaml` provides a matching local instance — the full
golden gate passes identically on both:

```bash
cd api
docker compose up -d
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=scoring_engine DB_USERNAME=scoring DB_PASSWORD='ScoringLocal!2026'
php artisan migrate --force && php artisan legacy:import
php artisan goldens:verify   # 68/68 on MySQL 8
```

## Products & access codes

The engine never hardcodes a single product. `ProductCatalog`
(`api/app/Scoring/Engine/ProductCatalog.php`) maps an opaque access code to a
version bundle (which legacy rule set to score against); `docs/07` designs
the full `access_codes` model, where each code can carry more than one
independent royalty term (e.g. a base fee plus a separate revenue share).
The currently live product is cataloged under code `VC18` — its external
legacy name (an assessment product formerly called "Professional
Development") is kept only as a human-readable label; nothing in the engine
branches on it.

## Build order (docs/00-INDEX)

1. Repo + CI skeleton ✅
2. Legacy config import ✅ (`legacy:import`)
3. Golden-master harness ✅ (`goldens:verify`)
4. Engine primitives + rule interpreter ✅ — **68/68 goldens pass, both result formats**
5. API layer + access codes ← next
6. Norm analytics
7. Control panel
8. pooled-v1 norms + docs polish
