# 02 — Input Catalog

Everything a client can submit. Verified against sample register messages, the JSON message docx specs, and the validation rule data.

## Registration info

| Field | Type | Required | Rules |
|---|---|---|---|
| firstname | string | yes | |
| middlename | string | no | |
| lastname | string | yes | |
| email | string | yes | valid email |
| language | string | yes | ISO 639-1; v1: en, fr, pt |
| gender | char | required for scopes touching S/P dimensions | M / F (norms are gender-split — see 06-norms.md; policy for non-binary pending) |
| dob | date | no | ISO 8601 in v2 (legacy M/D/YYYY). NOT used in scoring |
| external_id | string | no | client correlation key, echoed everywhere |

Legacy `projectcode` is replaced by API key + access code (07-access-codes-royalties.md). Legacy `rebaterequired`/`saleprice` are dropped (billing moves server-side).

## The nine tools

Every answer is a single scalar `{q, a}`, questions numbered from "1". Totals: 475 questions.

| Tool | Qs | Answer format | Validation |
|---|---|---|---|
| reflections | 28 | free text | q1–6 roles, q7–12 best, q13–18 worst, q19–22 needs, q23–28 five-year role/why pairs. Never scored — echoed to output |
| personalmotivators | 27 | ranking: exactly 3×"3", 3×"2", rest "0" | per-value counts + total 27 |
| areamissions | 27 | same 3/2/0 ranking | same |
| abilitiesfilter | 63 | integer 1–6 | range + count 63 |
| personalstyle | 96 | 24 groups of 4: one "1" (most), one "-1" (least), two "0" | per-group counts + total 96 |
| personalexpectations | 72 | integer 1–4 | range + count 72 |
| person | 54 | integer 1–6 | range + count 54 (legacy OIPro54Person) |
| role | 54 | integer 1–6 | range + count 54 |
| organization | 54 | integer 1–6 | range + count 54 |

Validation errors must be structured per item: `{tool, q, rule, expected, got}`. Legacy error taxonomy (E00–E31) is the starting point: E01 auth, E10 required field, E30 tool validation, E31 tool required.

## Question text

Question text per tool/language lives in the migrated `Question`/`QuestionTROut` data (570 rows × 3 languages) and is served via `GET /v2/reference/questions?tool=&language=` so clients can render questionnaires without hardcoding.

## Sample payloads

Real register requests (actual production payloads): `restore-db/goldens/<SessionKey>/request.json` (68 sessions). Legacy JSON shape documented in `original-source/Instructions for API/`.
