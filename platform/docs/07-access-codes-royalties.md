# 07 — Access Codes, Royalties, Billing

Royalty is **per report/result, not always due**. Access is managed by a **code system**: a code identifies which catalog product (ProductCatalog, 04) it scores and grants permission to specific data scopes. The API returns data only; "report" for royalty purposes = a scored result delivered under a code.

A code is never itself a product's brand name — it's an opaque identifier that maps to a catalog entry. A single product can also be issued under more than one code (e.g. different terms per partner).

## Code types (business-critical distinction)

| Code type | Purpose | Royalty |
|---|---|---|
| `training` | training/certification uses | per royalty terms on the code |
| `bizdev` | business development / demos / partner enablement | per royalty terms (typically none) |
| `derivative` | derivative products (e.g. Enneagram Map, MCS-Dev-based offerings) | **no royalty due** |

Every usage event records the code type so royalty reporting can split these correctly. Code type is immutable after issue (issue a new code to change treatment).

## Data model

- `access_codes`: code (unguessable), type (training|bizdev|derivative), product_code (ProductCatalog entry it scores), allowed scopes, max_uses / expires_at, issued_to (client/org), notes, active flag, created_by. **No single fee field** — see `royalty_terms` below.
- `royalty_terms`: access_code_id, recipient (who's owed — a code can pay more than one party), kind (flat_per_report | percentage_of_price | tiered | subscription), amount, currency, active flag, effective_from/until. **One code has MANY royalty terms** — e.g. a base per-report fee to the content owner plus a separate revenue share to a distribution partner, evaluated independently and both recorded on the same usage event.
- `usage_events`: api_key, access_code, code_type, scopes scored, assessment_id, timestamp, `fees_due` (list, one row per matching active royalty term at event time — computed from the code's terms at that moment; terms may change later without rewriting history).
- Scoring request carries `access_code`; API enforces requested scopes ⊆ code's allowed scopes (403 with explanation otherwise). Keys may also have a default code.

## Billing (designed now, switched on later)

Usage events are the metering layer. When Stripe is enabled: map billable clients → Stripe customers, roll up non-derivative `fees_due` line items into metered usage / invoices (Laravel Cashier) — a code with multiple royalty terms produces multiple billable lines per event, addressed to their respective recipients. Royalty statements (per client, per code type, per recipient, per period) must be producible from `usage_events` alone — no Stripe dependency for reporting.

## Control panel requirements

Issue / bulk-generate / revoke codes; manage a code's royalty terms (add/end without deleting history); filter usage by code type or recipient; royalty statement export (CSV/PDF) split by recipient; derivative-code usage visible but excluded from royalty totals.
