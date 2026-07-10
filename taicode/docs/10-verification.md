# 10 — Verification: Golden Masters

## The contract

The v2 engine must reproduce legacy outputs **exactly** for every golden master, using `male-legacy`/`female-legacy` norms. Any mismatch is a v2 bug until proven a legacy bug (document exceptions explicitly).

## The set (extracted 2026-07-10, complete)

`restore-db/goldens/` — **68 sessions, 34F/34M**, spanning en, fr, pt-BR client languages (all were submitted to TAI as `language:"en"`; true client language in `_client_languages.csv`; PRO-D order ↔ TAI session map in `_orders.csv`).

Per session `<SessionKey>/`:
- `request.json` — the actual production register payload (input)
- `register_response.json` — the ack TAI returned
- `results_keys.json`, `results_strings.json` — expected outputs, both formats (bare `{mcs, pro, etc}` body; envelope was added at send time)
- `outstrings.csv` — granular per-field output rows (436 rows on recent sessions; purged on older ones — use for debugging *where* a mismatch arises)

Edge case included: session 18125 (order 13557) has queue status −1 (error) yet complete results — a real retry case.

**PRIVACY: requests contain real names/emails. `goldens/` and `extracted/` never enter git.** (See 09.)

## Test harness (build this first, before the engine)

1. Seed test DB from `restore-db/extracted/` CSVs (rules, matrices, content, norms).
2. For each golden: parse request → run engine per scope → compare against `results_keys.json` deep-equal (keys format first; strings format second — it additionally exercises content lookup).
3. Report per-session, per-scope, per-field diffs; on mismatch dump the audit trace beside the legacy `outstrings.csv` for the same session.
4. Runs in CI on every commit; deploy blocks on 68/68.

## Extending the set

- `3-goldens.sh [n]` — n extra random sessions per run (accumulates, de-dupes).
- `4-goldens-byorder.sh <order ids…>` — fetch by PRO-D order id (fr list baked in); `5-goldens-pt.sh` — pt list baked in.
- Known gaps: fr/pt *output text* goldens don't exist (API path was always en) — verify strings-format rendering as a translation-lookup unit test against the content tables instead. PT orders ≥15204 postdate the .bak snapshot; orders 15058/14613/14547 never reached TAI.
