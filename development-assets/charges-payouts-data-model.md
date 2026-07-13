# Charges & Payouts — System Articulation

This describes how the system behaves conceptually — not a schema or
implementation design. The goal is a shared, precise vocabulary so a
reporting layer can be built against consistent definitions.

## Terminology

| Term | Definition |
|---|---|
| **Access Code** | Issued to a client; controls which product/data scope they can access |
| **Order Type** | `training` \| `complimentary` \| `lead` \| `sale` (open to more later) |
| **Charge** | The billable event record for one code usage — what the client owes, if anything |
| **Payout** | Money going out to a stakeholder, tied back to a Charge |
| **Payout Category** | Top-level bucket: `royalty` \| `fee` \| `residual` |
| **Payout Type** | Specific line item: `pro_d_royalty`, `derivative_royalty`, `tech_fee`, `language_fee`, `residual_margin`, etc. |
| **External Order ID** | The client system's unique identifier for a customer's submission/session — shared across multiple code usages by the same customer |

## Order Types Don't Restrict Charges/Payouts

Every order type — `training`, `complimentary`, `lead`, `sale` — can carry a
charge and payouts. There's no rule that says "this type is free by
definition." Instead:

- Each order type currently *resolves to* zero charge/payout, except `sale`.
- `training` and `complimentary` are zero today because that's the business
  reality right now, not because the mechanism forbids them from ever having
  a value.
- `lead` is the same — a taster/subset given away, currently free, but the
  door is open to a nominal charge on a lead in the future without needing to
  invent a new order type to support it.
- `sale` is the only type that currently generates a real (non-zero) charge,
  which in turn triggers a payout split (royalty, tech fee, language fee,
  residual, etc.) depending on whether the product is PRO-D or Derivative.

## Lead → Sale Conversion

**The flow:**

1. A customer submits an assessment using a **lead** access code — a subset
   of the full product, given as a free taster. This is logged as a charge
   with `order_type = lead`, currently $0, tied to that customer's
   external_order_id.
2. Later, the same customer submits again under the **same external_order_id**,
   this time through a **sale** access code — for **any** product/scope, not
   necessarily related to what the lead exposed them to. This generates a
   real charge and its associated payouts.
3. **Conversion is not a separate event that gets recorded.** It's inferred
   at reporting time: an external_order_id that has both a `lead` charge and
   a later `sale` charge on it represents a converted lead. An
   external_order_id with only a `lead` charge and nothing after it is an
   unconverted lead.

**What ties a lead to its sale:** only the shared external_order_id — nothing
else. The lead's product scope and the sale's product scope don't need any
relationship to each other. A customer could lead-in on Product X and
purchase Product Y entirely, and that still counts as a conversion.

**Cardinality:** confirmed there will only ever be **one lead per
external_order_id** — so there's no ambiguity about which lead gets credit
for a conversion. One lead, at most one sale, per external_order_id.

## Reporting Implications

Because charge/payout values aren't gated by order type, and conversion is a
derived relationship rather than a stored one, the reporting layer needs to
be able to:

- Group charges by `external_order_id` to detect lead → sale conversion
- Report totals by order type, independent of whether the current values
  happen to be zero (so a future non-zero `lead` or `training` charge
  doesn't require new report logic)
- Break out PRO-D vs. Derivative separately, since each has its own royalty
  and fee rules
- Report by sales channel, language, client, and access code, in addition to
  order type, PRO-D vs. Derivative, and conversion rate

## Confirmed

- **No cross-relationship leads/sales:** if a customer's lead and eventual
  sale ever don't share an external_order_id (e.g., sold to via a different
  client relationship), that is **not** treated as a conversion — it's
  simply a new, unrelated order.

## Suggested Additional Reports

Beyond the confirmed dimensions, worth considering:

- **Payout aging/status** — accrued vs. paid vs. void per stakeholder
- **Stakeholder statements** — total owed per stakeholder over a period,
  broken out by payout type (royalty vs. fee vs. residual)
- **Time-to-convert** — elapsed time between a lead charge and its sale
  charge, to compare channels/codes on conversion speed
- **Zero-value order volume** — usage volume by order type even while
  training/complimentary/lead remain $0, as a baseline
- **Language mix by channel or client** — since language drives a specific
  fee, useful for forecasting payouts

## Open / Still-to-Confirm

- [ ] None at this time
