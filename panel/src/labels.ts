// Display labels for raw identifiers in SELECTOR controls only (prolog-27y).
// Data-analysis views (Pipeline, Norms badges, etc.) deliberately show the
// raw identifiers — they're the dataset being inspected. API values never
// change; these are option/label text only.

export const TOOL_LABELS: Record<string, string> = {
  reflections: 'Reflections',
  personalmotivators: 'Personal Motivators',
  areamissions: 'Area Missions',
  abilitiesfilter: 'Abilities Filter',
  personalstyle: 'Personal Style',
  personalexpectations: 'Personal Expectations',
  person: 'Person',
  role: 'Role',
  organization: 'Organization',
}

// Order type is a reporting dimension, never a gate
// (charges-payouts-data-model.md): every usage logs a charge; training/
// complimentary/lead simply resolve to $0 today.
export const ORDER_TYPE_LABELS: Record<string, string> = {
  training: 'Training',
  complimentary: 'Complimentary',
  lead: 'Lead',
  sale: 'Sale',
}

export const PAYOUT_CATEGORY_LABELS: Record<string, string> = {
  royalty: 'Royalty',
  fee: 'Fee',
  residual: 'Residual (balance catch-all)',
}

export const PAYOUT_TYPE_OPTIONS: Record<string, string> = {
  pro_d_royalty: 'PRO-D royalty',
  derivative_royalty: 'Derivative royalty',
  tech_fee: 'Tech fee',
  language_fee: 'Language fee',
  residual_margin: 'Residual margin',
}

export const TERM_KIND_LABELS: Record<string, string> = {
  flat: 'Flat amount',
  percent_of_charge: '% of charge',
}

export const SCOPE_LABELS: Record<string, string> = {
  'mcs.m': 'Mission (M)',
  'mcs.c': 'Competency (C)',
  'mcs.s': 'Style (S)',
  mcs: 'Full MCS',
  'pro.person': 'Person Alignment (P)',
  'pro.role': 'Role Alignment (R)',
  'pro.org': 'Organization Alignment (O)',
  insights: 'Insights (narrative)',
  reflections: 'Reflections (echo)',
  full: 'Full (everything)',
}

export const toolLabel = (raw: string) => TOOL_LABELS[raw] ?? raw
export const scopeLabel = (raw: string) => SCOPE_LABELS[raw] ?? raw
