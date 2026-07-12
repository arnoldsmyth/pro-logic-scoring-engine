# 07 — Access Codes, Royalties, Billing

Royalty is **per report/result, not always due**. Access is managed by a **code system**: a code identifies which catalog product (ProductCatalog, 04) it scores and grants permission to specific data scopes. The API returns data only; "report" for royalty purposes = a scored result delivered under a code.

A code is never itself a product's brand name — it's an opaque identifier that maps to a catalog entry. A single product can also be issued under more than one code (e.g. different terms per partner).

## Code types (descriptive label, not a royalty mechanism)

| Code type | Purpose |
|---|---|
| `training` | training/certification uses |
| `bizdev` | business development / demos / partner enablement |
| `derivative` | derivative products (e.g. Enneagram Map, MCS-Dev-based offerings) |

**Decided 2026-07-11:** `type` is a descriptive/internal label only — it does not drive royalty behavior. Whether a code owes royalty is determined purely by whether it has active `royalty_terms` rows; a code with zero active terms owes nothing, regardless of its type. (Previously `derivative` was special-cased as "no royalty due" — in practice every issued code carries terms or doesn't, so the type field was never the actual mechanism.) Every usage event still records the code type so reporting can slice by it. Code type is immutable after issue (issue a new code to change the label).

## Data model

- `access_codes`: code (unguessable), **name** (free-text, internal-only display label set at issue time — e.g. "Acme Corp – Q3 training batch"; distinct from `code`, which stays unguessable and is never a brand name), type (training|bizdev|derivative, label only per above), product_code (ProductCatalog entry it scores), allowed scopes, max_uses / expires_at, **client_id** (FK to `clients`, replacing free-text `issued_to`), notes, active flag, created_by. **No single fee field** — see `royalty_terms` below.
- `clients`: normalized entity for anyone who pays (holds API keys and/or access codes) — name, billing contact/email, notes, active flag, later a `stripe_customer_id`. Replaces the free-text `api_keys.name` (labeled "Client name" in the panel) and `access_codes.issued_to`.
- `payees`: normalized entity for anyone who gets paid via a royalty term (content owners, translators, distribution partners) — kept **separate from `clients`**, since a payee is often not a billed client at all. A payee MAY optionally link to a `client` record (nullable `client_id` on `payees`) when the same real-world party is both a paying client and a royalty recipient — that link is optional, not assumed.
- `royalty_terms`: access_code_id, **payee_id** (FK to `payees`, replacing free-text `recipient`), kind (flat_per_report | percentage_of_price | tiered | subscription), amount, currency, active flag, effective_from/until. **One code has MANY royalty terms** — e.g. a base per-report fee to the content owner plus a separate revenue share to a distribution partner, evaluated independently and both recorded on the same usage event.
- `usage_events`: api_key, access_code, code_type, scopes scored, assessment_id, timestamp, `fees_due` (list, one row per matching active royalty term at event time — computed from the code's terms at that moment; terms may change later without rewriting history).
- Scoring request carries `access_code`; API enforces requested scopes ⊆ code's allowed scopes (403 with explanation otherwise). Keys may also have a default code.

## Billing (designed now, switched on later)

Usage events are the metering layer. Decided 2026-07-11: billing runs through **Stripe**, collected via bank transfer, on a **monthly usage-metered** basis, with the client managing billing in their own account (self-service portal — Stripe-hosted vs. custom, and exact cadence/payment-method configuration still open, see 12-open-questions.md). Map `clients` → Stripe customers (via `stripe_customer_id`), roll up non-derivative `fees_due` line items into metered usage / invoices (Laravel Cashier) — a code with multiple royalty terms produces multiple billable lines per event, addressed to their respective payees. Royalty statements (per client, per code type, per payee, per period) must be producible from `usage_events` alone — no Stripe dependency for reporting.

## Control panel requirements

Issue / bulk-generate / revoke codes; manage a code's royalty terms (add/end without deleting history); filter usage by code type or payee; royalty statement export (CSV/PDF) split by payee; derivative-code usage visible but excluded from royalty totals. Codes/keys are issued against a `client` record (typeahead/select), not free-typed.
