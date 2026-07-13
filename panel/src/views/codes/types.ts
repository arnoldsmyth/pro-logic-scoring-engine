export type Term = {
  id: number
  payee_id: number | null
  recipient: string
  category: 'royalty' | 'fee' | 'residual'
  payout_type: string | null
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
  order_type: 'training' | 'complimentary' | 'lead' | 'sale'
  charge_amount: string
  charge_currency: string
  product_code: string
  allowed_scopes: string[]
  max_uses: number | null
  uses_count: number
  expires_at: string | null
  client: string | null
  client_id: number | null
  notes: string | null
  active: boolean
  usage_events: number
  scope_and_type_locked: boolean
  payout_terms_count: number
  active_payout_terms_count: number
}

export type CodeDetail = CodeSummary & {
  payout_terms: Term[]
  recent_charges: {
    id: number
    external_order_id: string | null
    amount: string
    currency: string
    is_repeat: boolean
    payout_count: number
    created_at: string
  }[]
}
