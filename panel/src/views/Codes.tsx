import { useEffect, useState } from 'react'
import { get, post } from '../api'
import { useAuth } from '../auth'
import { DataTable, type Column } from '../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../components/ui'
import { CODE_TYPE_LABELS, SCOPE_LABELS } from '../labels'

type Term = { id: number; recipient: string; kind: string; amount: string; currency: string; active: boolean }
type Code = {
  id: number
  code: string
  type: 'training' | 'bizdev' | 'derivative'
  product_code: string
  allowed_scopes: string[]
  max_uses: number | null
  uses_count: number
  issued_to: string | null
  active: boolean
  usage_events: number
  royalty_terms: Term[]
}
type Statement = {
  period: { from: string; to: string }
  events: number
  derivative_events_excluded: number
  totals_by_recipient: Record<string, Record<string, number>>
}

const typeTone = { training: 'blue', bizdev: 'amber', derivative: 'gray' } as const

export default function Codes() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [codes, setCodes] = useState<Code[]>([])
  const [statement, setStatement] = useState<Statement | null>(null)
  const [form, setForm] = useState({ type: 'training', issued_to: '', count: '1' })
  const [scopes, setScopes] = useState<string[]>(['full'])
  const [issued, setIssued] = useState<string[]>([])

  const load = () => {
    get<{ codes: Code[] }>('/codes').then((r) => setCodes(r.codes))
    get<Statement>('/codes/statement').then(setStatement)
  }
  useEffect(load, [])

  // 'full' is exclusive: it grants everything, so picking it clears the
  // rest, and picking anything specific drops 'full'.
  const toggleScope = (scope: string) => {
    setScopes((current) => {
      if (scope === 'full') return ['full']
      const next = current.includes(scope)
        ? current.filter((s) => s !== scope)
        : [...current.filter((s) => s !== 'full'), scope]
      return next.length === 0 ? ['full'] : next
    })
  }

  const issue = async () => {
    const r = await post<{ codes: string[] }>('/codes', {
      type: form.type,
      product_code: 'VC18',
      allowed_scopes: scopes,
      issued_to: form.issued_to || null,
      count: Number(form.count),
    })
    setIssued(r.codes)
    load()
  }

  return (
    <div className="space-y-4">
      <Explainer title="codes, royalty terms, and the metering trail">
        <p>
          A code is an opaque identifier granting specific scoring scopes against one catalog product — never a brand
          name. Its <b>type</b> drives royalty treatment: training and bizdev codes pay per the royalty terms attached
          to them; <b>derivative</b> codes owe nothing by design. A code can carry several active terms at once (e.g.
          a per-report fee to the content owner plus a partner revenue share) — each usage event records every fee due
          at that moment, so statements below are reproducible from the metering trail alone.
        </p>
      </Explainer>

      {statement && (
        <Card
          title={`Royalty statement ${statement.period.from} → ${statement.period.to}`}
          actions={<a className="text-sm text-sky-600 hover:underline" href="/panel/api/codes/statement.csv">Export CSV</a>}
        >
          <div className="flex gap-8 text-sm">
            <div>
              <div className="text-xl font-semibold text-gray-800">{statement.events}</div>
              <div className="text-xs text-gray-500">usage events</div>
            </div>
            <div>
              <div className="text-xl font-semibold text-gray-800">{statement.derivative_events_excluded}</div>
              <div className="text-xs text-gray-500">derivative events (excluded from totals)</div>
            </div>
            <div className="flex-1">
              {Object.entries(statement.totals_by_recipient).length === 0 && (
                <p className="text-gray-400">No royalties due this period.</p>
              )}
              {Object.entries(statement.totals_by_recipient).map(([recipient, currencies]) => (
                <div key={recipient} className="flex justify-between border-b border-gray-100 py-1">
                  <span className="text-gray-700">{recipient}</span>
                  <span className="font-medium text-gray-800">
                    {Object.entries(currencies).map(([cur, amt]) => `${amt.toFixed(2)} ${cur}`).join(' · ')}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </Card>
      )}

      {isAdmin && (
        <Card title="Issue codes">
          <div className="mb-4">
            <span className="mb-1 block text-sm font-medium text-gray-600">Allowed scopes</span>
            <p className="mb-2 text-xs text-gray-400">
              What the code's holder may score. "Full" grants everything; pick specific scopes to narrow it.
            </p>
            <div className="flex flex-wrap gap-2">
              {Object.entries(SCOPE_LABELS).map(([value, label]) => {
                const selected = scopes.includes(value)
                return (
                  <button
                    key={value}
                    type="button"
                    onClick={() => toggleScope(value)}
                    aria-pressed={selected}
                    className={`rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition-colors ${
                      selected
                        ? 'bg-sky-600 text-white ring-sky-600'
                        : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50'
                    }`}
                  >
                    {label} <code className={selected ? 'text-sky-100' : 'text-gray-400'}>{value}</code>
                  </button>
                )
              })}
            </div>
          </div>
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Type">
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
            <Button onClick={issue}>Issue</Button>
          </div>
          {issued.length > 0 && (
            <div className="mt-3 rounded border border-amber-300 bg-amber-50 p-3 text-xs">
              {issued.map((c) => (
                <code key={c} className="mr-3 text-amber-800">{c}</code>
              ))}
            </div>
          )}
        </Card>
      )}

      <Card title="Codes">
        <DataTable
          rows={codes}
          rowKey={(c) => c.id}
          empty="No access codes issued yet."
          columns={[
            { header: 'Code', primary: true, cell: (c) => <code className="text-xs text-gray-600">{c.code}</code> },
            { header: 'Type', cell: (c) => <Badge tone={typeTone[c.type]}>{c.type}</Badge> },
            { header: 'Scopes', cell: (c) => <span className="text-xs">{c.allowed_scopes.join(', ')}</span> },
            { header: 'Issued to', cell: (c) => c.issued_to ?? '—' },
            { header: 'Uses', cell: (c) => `${c.uses_count}${c.max_uses !== null ? ` / ${c.max_uses}` : ''}` },
            {
              header: 'Terms',
              cell: (c) => (
                <span className="text-xs">
                  {c.royalty_terms.length === 0
                    ? c.type === 'derivative' ? 'none (derivative)' : 'none'
                    : c.royalty_terms.map((t) => `${t.recipient}: ${t.amount} ${t.currency}${t.active ? '' : ' (ended)'}`).join('; ')}
                </span>
              ),
            },
            { header: 'Status', cell: (c) => (c.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>) },
          ] satisfies Column<Code>[]}
        />
      </Card>
    </div>
  )
}
