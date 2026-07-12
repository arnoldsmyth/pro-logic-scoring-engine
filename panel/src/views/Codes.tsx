import { useEffect, useState } from 'react'
import { get, patch, post } from '../api'
import { useAuth } from '../auth'
import { DataTable, type Column } from '../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../components/ui'
import { CODE_TYPE_LABELS, SCOPE_LABELS, TERM_KIND_LABELS } from '../labels'

type Term = {
  id: number
  recipient: string
  kind: string
  amount: string
  currency: string
  language: string | null
  active: boolean
}
type Code = {
  id: number
  code: string
  name: string | null
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
  no_fee_events: number
  totals_by_recipient: Record<string, Record<string, number>>
  totals_by_code: Record<string, Record<string, number>>
}

const typeTone = { training: 'blue', bizdev: 'amber', derivative: 'gray' } as const

const EMPTY_TERM = { recipient: '', kind: 'flat_per_report', amount: '', currency: 'USD', language: '' }

export default function Codes() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [codes, setCodes] = useState<Code[]>([])
  const [statement, setStatement] = useState<Statement | null>(null)
  const [form, setForm] = useState({ name: '', type: 'training', issued_to: '', count: '1' })
  const [scopes, setScopes] = useState<string[]>(['full'])
  const [issued, setIssued] = useState<string[]>([])
  const [manageId, setManageId] = useState<number | null>(null)
  const [termForm, setTermForm] = useState(EMPTY_TERM)
  const [error, setError] = useState<string | null>(null)

  const managed = codes.find((c) => c.id === manageId) ?? null

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

  const run = async (fn: () => Promise<unknown>) => {
    setError(null)
    try {
      await fn()
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Action failed')
    }
  }

  const issue = () =>
    run(async () => {
      const r = await post<{ codes: string[] }>('/codes', {
        name: form.name,
        type: form.type,
        product_code: 'VC18',
        allowed_scopes: scopes,
        issued_to: form.issued_to || null,
        count: Number(form.count),
      })
      setIssued(r.codes)
    })

  const addTerm = () =>
    run(async () => {
      await post(`/codes/${managed!.id}/terms`, {
        recipient: termForm.recipient,
        kind: termForm.kind,
        amount: Number(termForm.amount),
        currency: termForm.currency,
        language: termForm.language || null,
      })
      setTermForm(EMPTY_TERM)
    })

  return (
    <div className="space-y-4">
      <Explainer title="codes, royalty terms, and the metering trail">
        <p>
          A code is an opaque identifier granting specific scoring scopes against one catalog product; its display
          name is for reporting only and never leaves the panel. The <b>type</b> is a descriptive label — whether a
          code owes royalty is driven entirely by its royalty terms: a code with no active terms owes nothing. A code
          can carry several terms at once (a per-report fee to the content owner, a partner revenue share, a
          language-scoped translator fee that fires only on matching-language reports, or a{' '}
          <b>conversion fee charged exactly once per person</b> when a free lead converts). Each usage event records
          every fee due at that moment, so statements are reproducible from the metering trail alone.
        </p>
      </Explainer>

      {error && <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {statement && (
        <Card
          title={`Royalty statement ${statement.period.from} → ${statement.period.to}`}
          actions={<a className="text-sm text-sky-600 hover:underline" href="/panel/api/codes/statement.csv">Export CSV</a>}
        >
          <div className="flex flex-wrap gap-8 text-sm">
            <div>
              <div className="text-xl font-semibold text-gray-800">{statement.events}</div>
              <div className="text-xs text-gray-500">usage events</div>
            </div>
            <div>
              <div className="text-xl font-semibold text-gray-800">{statement.no_fee_events}</div>
              <div className="text-xs text-gray-500">events with no fees due</div>
            </div>
            <div className="min-w-48 flex-1">
              <div className="mb-1 text-xs uppercase tracking-wide text-gray-400">By recipient</div>
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
            <div className="min-w-48 flex-1">
              <div className="mb-1 text-xs uppercase tracking-wide text-gray-400">By code</div>
              {Object.entries(statement.totals_by_code ?? {}).length === 0 && <p className="text-gray-400">—</p>}
              {Object.entries(statement.totals_by_code ?? {}).map(([codeName, currencies]) => (
                <div key={codeName} className="flex justify-between border-b border-gray-100 py-1">
                  <span className="text-gray-700">{codeName}</span>
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
            <Field label="Name (for royalty reporting)">
              <input className={`${inputClass} sm:w-64`} value={form.name} placeholder="Acme Corp – Q3 training batch" onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </Field>
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
            <Button onClick={issue} disabled={form.name.trim() === ''}>Issue</Button>
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
            {
              header: 'Name',
              primary: true,
              cell: (c) => (
                <div>
                  <span className="font-medium text-gray-700">{c.name ?? '(unnamed)'}</span>
                  <code className="ml-2 text-xs text-gray-400">{c.code}</code>
                </div>
              ),
            },
            { header: 'Type', cell: (c) => <Badge tone={typeTone[c.type]}>{c.type}</Badge> },
            { header: 'Scopes', cell: (c) => <span className="text-xs">{c.allowed_scopes.join(', ')}</span> },
            { header: 'Uses', cell: (c) => `${c.uses_count}${c.max_uses !== null ? ` / ${c.max_uses}` : ''}` },
            {
              header: 'Royalty',
              cell: (c) => {
                const active = c.royalty_terms.filter((t) => t.active)
                return active.length === 0
                  ? <Badge tone="gray">none due</Badge>
                  : <span className="text-xs">{active.map((t) => `${t.recipient}: ${t.amount} ${t.currency}${t.language ? ` (${t.language})` : ''}`).join('; ')}</span>
              },
            },
            { header: 'Status', cell: (c) => (c.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>) },
          ] satisfies Column<Code>[]}
          actions={isAdmin ? (c) => (
            <Button kind="secondary" onClick={() => { setManageId(c.id === manageId ? null : c.id); setTermForm(EMPTY_TERM) }}>
              {c.id === manageId ? 'Close' : 'Manage'}
            </Button>
          ) : undefined}
        />
      </Card>

      {isAdmin && managed && (
        <Card
          title={`Manage: ${managed.name ?? managed.code}`}
          actions={
            <Button
              kind={managed.active ? 'danger' : 'secondary'}
              onClick={() => run(() => patch(`/codes/${managed.id}`, { active: !managed.active }))}
            >
              {managed.active ? 'Revoke code' : 'Restore code'}
            </Button>
          }
        >
          <h3 className="mb-2 text-xs font-semibold uppercase text-gray-400">Royalty terms</h3>
          {managed.royalty_terms.length === 0 && (
            <p className="mb-3 text-sm text-gray-400">No terms — this code currently owes nothing when used.</p>
          )}
          <ul className="mb-4 space-y-2">
            {managed.royalty_terms.map((t) => (
              <li key={t.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-100 px-3 py-2 text-sm">
                <span className="text-gray-700">
                  {t.recipient} — {TERM_KIND_LABELS[t.kind] ?? t.kind}, {t.amount} {t.currency}
                  {t.language ? ` · ${t.language} only` : ' · all languages'}
                </span>
                {t.active
                  ? <Button kind="secondary" onClick={() => run(() => post(`/terms/${t.id}/end`))}>End term</Button>
                  : <Badge tone="gray">ended</Badge>}
              </li>
            ))}
          </ul>

          <h3 className="mb-2 text-xs font-semibold uppercase text-gray-400">Add a term</h3>
          <p className="mb-2 text-xs text-gray-400">
            Terms are ended, never deleted — history stays intact. "On conversion" charges once per person, ever —
            built for free lead-gen assessments that later convert to paid.
          </p>
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Recipient">
              <input className={inputClass} value={termForm.recipient} onChange={(e) => setTermForm({ ...termForm, recipient: e.target.value })} />
            </Field>
            <Field label="Kind">
              <select className={inputClass} value={termForm.kind} onChange={(e) => setTermForm({ ...termForm, kind: e.target.value })}>
                {Object.entries(TERM_KIND_LABELS).map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </select>
            </Field>
            <Field label="Amount">
              <input className={inputClass} type="number" min="0" step="0.01" value={termForm.amount} onChange={(e) => setTermForm({ ...termForm, amount: e.target.value })} />
            </Field>
            <Field label="Currency">
              <input className={`${inputClass} w-20`} maxLength={3} value={termForm.currency} onChange={(e) => setTermForm({ ...termForm, currency: e.target.value.toUpperCase() })} />
            </Field>
            <Field label="Language (optional)">
              <select className={inputClass} value={termForm.language} onChange={(e) => setTermForm({ ...termForm, language: e.target.value })}>
                <option value="">All languages</option>
                <option value="en">English only</option>
                <option value="fr">French only</option>
                <option value="pt">Portuguese only</option>
              </select>
            </Field>
            <Button onClick={addTerm} disabled={termForm.recipient.trim() === '' || termForm.amount === ''}>
              Add term
            </Button>
          </div>
        </Card>
      )}
    </div>
  )
}
