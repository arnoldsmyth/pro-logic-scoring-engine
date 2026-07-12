import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ApiError, post } from '../../api'
import { Button, Card, Explainer, Field, inputClass } from '../../components/ui'
import { CODE_TYPE_LABELS, SCOPE_LABELS } from '../../labels'

export default function CodeNew() {
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', type: 'training', issued_to: '', count: '1' })
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
        type: form.type,
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
          Type and allowed scopes lock permanently the moment this code scores anything for the first time — get them
          right now, or issue a fresh code later rather than trying to change a code already in use. Royalty terms
          are added afterward, from the code's detail page.
        </p>
      </Explainer>

      <Card title="Issue a new code">
        {error && <p className="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

        <div className="space-y-4">
          <Field label="Name (for royalty reporting)">
            <input className={inputClass} value={form.name} placeholder="Acme Corp – Q3 training batch" onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </Field>

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

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Field label="Type (label)">
              <select className={inputClass} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                {Object.entries(CODE_TYPE_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </Field>
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
