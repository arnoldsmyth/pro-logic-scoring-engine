# 07 — Access Codes, Charges & Payouts

> Canonical articulation (agreed 2026-07-12): `development-assets/charges-payouts-data-model.md`. This doc summarizes the implemented model.

Access is managed by a **code system**: a code identifies which catalog product (ProductCatalog, 04) it scores and grants permission to specific data scopes. A code is never itself a product's brand name — it's an opaque identifier that maps to a catalog entry, with a free-text internal `name` for reporting. A single product can be issued under more than one code (different terms per partner).

## Order types (reporting dimension, never a gate)

| Order type | Today |
|---|---|
| `training` | training/certification uses — currently $0 |
| `complimentary` | demos / partner enablement — currently $0 |
| `lead` | free taster subset given as advertising lead-in — currently $0 |
| `sale` | paid orders — the only type currently generating a real charge |

Every order type can carry a charge and payouts — the current $0 values on training/complimentary/lead are business reality, not mechanism. Order type is immutable once a code has scored anything (it drives conversion reporting).

## Charges (the billable-event ledger)

Every code usage logs a **Charge**: what the client owes for that usage (possibly $0), tied to the code's order type, product, and the caller's `external_order_id` (the client system's identifier for a customer's order, shared across that customer's code usages).

**Royalty is due exactly once per order, structurally:** the first usage per (order, order_type) charges the code's configured amount; every repeat usage (updates/rescoring) logs a **$0 charge referencing the original** (`original_charge_id`) — the trail always shows why a usage cost nothing. Orders without an external_order_id dedup per assessment.

**Lead → sale conversion is never stored.** Reporting infers it: an external_order_id with both a `lead` charge and a later `sale` charge is a converted lead. The lead's scope and the sale's scope need no relationship. One lead, at most one sale, per external_order_id. Cross-key/cross-order relationships are never treated as conversions.

## Payouts (the stakeholder ledger)

Each real (non-zero) charge splits into **Payouts** — money going out, always tied back to its charge, categorized:

- **Category**: `royalty` | `fee` | `residual`
- **Payout type**: `pro_d_royalty`, `derivative_royalty`, `tech_fee`, `language_fee`, `residual_margin`, …
- **Status**: `accrued` → `paid` (or `void`) — supports payout-aging reports.

A code's **payout schedule** (`payout_terms`) defines the split: per line — recipient (→ `payees` FK once prolog-opw.1 lands), category, payout type, kind (`flat` | `percent_of_charge`), amount, currency, optional **language** (a language-scoped line, e.g. a translator fee, only fires when the scoring event's language matches). Exactly one active **residual** line per code absorbs `charge − sum(other lines)` so the schedule always balances to the charge — all money is paid out to someone; when conditional lines don't fire, the residual grows. A negative residual is recorded as-is (visible misconfiguration, never hidden).

Schedule lines are ended, never deleted; a line that has accrued a real payout locks against editing (end + re-add instead). $0 charges produce no payout rows.

## Data model summary

- `access_codes`: code (unguessable), name, order_type, **charge_amount/charge_currency**, product_code, allowed scopes, max_uses/expires_at, issued_to (→ client_id per opw.1), notes, active, created_by.
- `payout_terms`: access_code_id, recipient (→ payee_id per opw.1), category, payout_type, kind, amount, currency, language, active, effective windows.
- `charges`: usage_event_id, access_code, api_key, assessment, external_order_id, order_type, product_code, amount, currency, original_charge_id, timestamp.
- `payouts`: charge_id, payout_term_id, recipient, category, payout_type, amount, currency, language, status, timestamp.
- `usage_events` stays the raw access log; `fees_due` mirrors the payout lines as a snapshot. **Statements report from `charges` + `payouts`, never recomputed.**
- Scoring request carries `access_code`; API enforces requested scopes ⊆ code's allowed scopes (403 with explanation otherwise). Keys may also have a default code.

## Billing (designed now, switched on later)

Charges/payouts are the metering layer. Decided 2026-07-11: billing runs through **Stripe**, collected via bank transfer, on a **monthly usage-metered** basis, with the client managing billing in their own account (self-service portal — Stripe-hosted vs. custom still open, see 12-open-questions.md). Map `clients` → Stripe customers, roll up charges into metered usage/invoices (Laravel Cashier). Statements (per client, per order type, per payee, per code, per period) must be producible from the `charges`/`payouts` ledgers alone — no Stripe dependency for reporting.

## Control panel requirements

Issue / bulk-generate / revoke codes (list → dedicated issue page → per-code detail page); configure charge + payout schedule per code (add/edit-while-unused/end lines, one residual); statement reporting by order type, recipient, and code with CSV export; lead→sale conversion rate; $0 and repeat charges visible with their back-references. Locking: order_type/scopes freeze after first use; accrued payout lines freeze forever. Codes/keys issued against a `client` record once prolog-opw.1 lands.
