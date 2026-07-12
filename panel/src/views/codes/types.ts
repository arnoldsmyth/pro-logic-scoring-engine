export type Term = {
  id: number
  recipient: string
  kind: string
  amount: string
  currency: string
  language: string | null
  active: boolean
  locked?: boolean
}

export type CodeSummary = {
  code: string
  name: string | null
  type: 'training' | 'bizdev' | 'derivative'
  product_code: string
  allowed_scopes: string[]
  max_uses: number | null
  uses_count: number
  expires_at: string | null
  issued_to: string | null
  notes: string | null
  active: boolean
  usage_events: number
  scope_and_type_locked: boolean
  royalty_terms_count: number
  active_royalty_terms_count: number
}

export type CodeDetail = CodeSummary & {
  royalty_terms: Term[]
  recent_usage: { id: number; scopes: string[]; fee_count: number; created_at: string }[]
}
