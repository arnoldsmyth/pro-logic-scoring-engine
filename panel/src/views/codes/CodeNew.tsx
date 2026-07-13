import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError, post } from '../../api'
import { Button, Card, Explainer, Field, inputClass } from '../../components/ui'
import { ORDER_TYPE_LABELS, SCOPE_LABELS } from '../../labels'

export default function CodeNew() {
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', order_type: 'training', charge_amount: '0', charge_currency: 'USD', issued_to: '', count: '1' })
  const [scopes, setScopes] = useState<string[]>(['full'])
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  // 'full' is exclusive: it grants everything, so picking it clears the
  // rest, and picking anything specific drops 'full'.
  const toggleScope = (scope: string) => {
    setScopes((current) => {
      if (scope === 'full') return ['full']
      const next = current.includes(scope) ? current.filter((s) => s !== scope) : [...current.filter((s) => s !== 'full'), scope]
      return next.length === 0 ? ['full'] : next
    })
  }

  const issue = async () => {
    setBusy(true)
    setError(null)
    try {
      const r = await post<{ codes: string[] }>('/codes', {
        name: form.name,
        order_type: form.order_type,
        charge_amount: Number(form.charge_amount),
        charge_currency: form.charge_currency,
        product_code: 'VC18',
        allowed_scopes: scopes,
        issued_to: form.issued_to || null,
        count: Number(form.count),
      })
      navigate(r.codes.length === 1 ? `/codes/${r.codes[0]}` : '/codes')
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Failed to issue code(s)')
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <Explainer title="issuing a code">
        <p>
          Order type and allowed scopes lock permanently the moment this code scores anything — get them right now,
          or issue a fresh code later. The charge is what the client owes per order (once per order, however many
          times it's rescored); $0 is normal for training, complimentary, and lead codes today. The payout schedule
          splitting the charge is added afterward, from the code's detail page.
        </p>
      </Explainer>

      <Card title="Issue a new code">
        {error && <p className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

        <div className="space-y-4">
          <Field label="Name (for reporting)">
            <input className={inputClass} value={form.name} placeholder="Acme Corp – Q3 sales" onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field label="Order type">
              <select className={inputClass} value={form.order_type} onChange={(e) => setForm({ ...form, order_type: e.target.value })}>
                {Object.entries(ORDER_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </Field>
            <Field label="Charge per order">
              <input className={inputClass} type="number" min="0" step="0.01" value={form.charge_amount} onChange={(e) => setForm({ ...form, charge_amount: e.target.value })} />
            </Field>
            <Field label="Currency">
              <input className={inputClass} maxLength={3} value={form.charge_currency} onChange={(e) => setForm({ ...form, charge_currency: e.target.value.toUpperCase() })} />
            </Field>
          </div>

          <div>
            <span className="mb-1 block text-sm font-medium text-gray-600">Allowed scopes</span>
            <p className="mb-2 text-xs text-gray-400">What the code's holder may score. "Full" grants everything.</p>
            <div className="flex flex-wrap gap-2">
              {Object.entries(SCOPE_LABELS).map(([value, label]) => {
                const selected = scopes.includes(value)
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => toggleScope(value)}
                    aria-pressed={selected}
                    className={`rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition-colors ${selected ? 'bg-sky-600 text-white ring-sky-600' : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50'}`}
                  >
                    {label} <code className={selected ? 'text-sky-100' : 'text-gray-400'}>{value}</code>
                  </button>
                )
              })}
            </div>
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Issued to">
              <input className={inputClass} value={form.issued_to} onChange={(e) => setForm({ ...form, issued_to: e.target.value })} />
            </Field>
            <Field label="Count">
              <input className={inputClass} type="number" min="1" max="500" value={form.count} onChange={(e) => setForm({ ...form, count: e.target.value })} />
            </Field>
          </div>

          <Button onClick={issue} disabled={busy || form.name.trim() === ''}>{busy ? 'Issuing…' : 'Issue code'}</Button>
        </div>
      </Card>
    </div>
  )
}
