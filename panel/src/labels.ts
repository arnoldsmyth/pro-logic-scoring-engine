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

// Type is a descriptive label only (decided 2026-07-11) — royalty behavior
// comes from a code's royalty_terms, not its type.
export const CODE_TYPE_LABELS: Record<string, string> = {
  training: 'Training',
  bizdev: 'Biz Dev',
  derivative: 'Derivative',
}

export const TERM_KIND_LABELS: Record<string, string> = {
  flat_per_report: 'Flat per report',
  percentage_of_price: 'Percentage of price',
  tiered: 'Tiered',
  subscription: 'Subscription',
  flat_on_conversion: 'On conversion (once per person)',
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
export const codeTypeLabel = (raw: string) => CODE_TYPE_LABELS[raw] ?? raw
export const scopeLabel = (raw: string) => SCOPE_LABELS[raw] ?? raw
