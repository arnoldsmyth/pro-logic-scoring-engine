# 07 — Access Codes, Royalties, Billing

Royalty is **per report/result, not always due**. Access is managed by a **code system**: a code grants permission to specific data scopes and carries a fee from $0 up. The API returns data only; "report" for royalty purposes = a scored result delivered under a code.

## Code types (business-critical distinction)

| Code type | Purpose | Royalty |
|---|---|---|
| `training` | training/certification uses | per fee schedule on the code |
| `bizdev` | business development / demos / partner enablement | per fee schedule (typically 0) |
| `derivative` | derivative products (e.g. Enneagram Map, MCS-Dev-based offerings) | **no royalty due** |

Every usage event records the code type so royalty reporting can split these correctly. Code type is immutable after issue (issue a new code to change treatment).

## Data model

- `access_codes`: code (unguessable), type (training|bizdev|derivative), allowed scopes, fee amount + currency, max_uses / expires_at, issued_to (client/org), notes, active flag, created_by.
- `usage_events`: api_key, access_code, code_type, scopes scored, assessment_id, timestamp, fee_due (computed at event time from the code — fees on the code may change later without rewriting history).
- Scoring request carries `access_code`; API enforces requested scopes ⊆ code's allowed scopes (403 with explanation otherwise). Keys may also have a default code.

## Billing (designed now, switched on later)

Usage events are the metering layer. When Stripe is enabled: map billable clients → Stripe customers, roll up non-derivative `fee_due` events into metered usage / invoices (Laravel Cashier). Royalty statements (per client, per code type, per period) must be producible from `usage_events` alone — no Stripe dependency for reporting.

## Control panel requirements

Issue / bulk-generate / revoke codes; filter usage by code type; royalty statement export (CSV/PDF); derivative-code usage visible but excluded from royalty totals.
