import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { ApiError, get, patch, post } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../../components/ui'
import { ORDER_TYPE_LABELS, PAYOUT_CATEGORY_LABELS, PAYOUT_TYPE_OPTIONS, SCOPE_LABELS, TERM_KIND_LABELS } from '../../labels'
import type { CodeDetail as CodeDetailType, Term } from './types'

const EMPTY_TERM = { payee_id: '', category: 'royalty', payout_type: 'pro_d_royalty', kind: 'flat', amount: '', currency: 'USD', language: '' }
const orderTypeTone = { training: 'blue', complimentary: 'gray', lead: 'amber', sale: 'green' } as const

function describeTerm(t: Term): string {
  const value = t.category === 'residual'
    ? 'balance'
    : t.kind === 'percent_of_charge' ? `${t.amount}% of charge` : `${t.amount} ${t.currency}`
  const lang = t.language ? ` · ${t.language} only` : ''
  return `${t.recipient} — ${PAYOUT_CATEGORY_LABELS[t.category] ?? t.category}${t.payout_type ? ` (${PAYOUT_TYPE_OPTIONS[t.payout_type] ?? t.payout_type})` : ''}, ${value}${lang}`
}

export default function CodeDetail() {
  const { code: codeParam } = useParams<{ code: string }>()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [code, setCode] = useState<CodeDetailType | null>(null)
  const [meta, setMeta] = useState({ name: '', client_id: '', notes: '', max_uses: '', expires_at: '', charge_amount: '0', charge_currency: 'USD' })
  const [clients, setClients] = useState<{ id: number; name: string; active: boolean }[]>([])
  const [payees, setPayees] = useState<{ id: number; name: string; active: boolean }[]>([])
  const [newPayee, setNewPayee] = useState('')
  const [scopes, setScopes] = useState<string[]>([])
  const [orderType, setOrderType] = useState('training')
  const [termForm, setTermForm] = useState(EMPTY_TERM)
  const [editingTerm, setEditingTerm] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)

  const load = () => {
    get<CodeDetailType>(`/codes/${codeParam}`)
      .then((c) => {
        setCode(c)
        setMeta({
          name: c.name ?? '',
          client_id: c.client_id?.toString() ?? '',
          notes: c.notes ?? '',
          max_uses: c.max_uses?.toString() ?? '',
          expires_at: c.expires_at?.slice(0, 10) ?? '',
          charge_amount: c.charge_amount,
          charge_currency: c.charge_currency,
        })
        setScopes(c.allowed_scopes)
        setOrderType(c.order_type)
      })
      .catch(() => setNotFound(true))
  }
  useEffect(load, [codeParam])
  useEffect(() => {
    get<{ clients: typeof clients }>('/clients').then((r) => setClients(r.clients))
    get<{ payees: typeof payees }>('/payees').then((r) => setPayees(r.payees))
  }, [])

  const run = async (fn: () => Promise<unknown>) => {
    setError(null)
    try {
      await fn()
      load()
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Action failed')
    }
  }

  const toggleScope = (scope: string) => {
    setScopes((current) => {
      if (scope === 'full') return ['full']
      const next = current.includes(scope) ? current.filter((s) => s !== scope) : [...current.filter((s) => s !== 'full'), scope]
      return next.length === 0 ? ['full'] : next
    })
  }

  const saveMeta = () =>
    run(() => patch(`/codes/${codeParam}`, {
      name: meta.name,
      client_id: meta.client_id ? Number(meta.client_id) : null,
      notes: meta.notes || null,
      max_uses: meta.max_uses ? Number(meta.max_uses) : null,
      expires_at: meta.expires_at || null,
      charge_amount: Number(meta.charge_amount),
      charge_currency: meta.charge_currency,
    }))

  const saveScopesAndType = () => run(() => patch(`/codes/${codeParam}`, { order_type: orderType, allowed_scopes: scopes }))

  const termPayload = () => ({
    payee_id: Number(termForm.payee_id),
    category: termForm.category,
    payout_type: termForm.payout_type || null,
    kind: termForm.kind,
    amount: termForm.category === 'residual' ? 0 : Number(termForm.amount),
    currency: termForm.currency,
    language: termForm.language || null,
  })

  const addTerm = () =>
    run(async () => {
      await post(`/codes/${codeParam}/terms`, termPayload())
      setTermForm(EMPTY_TERM)
    })

  const saveTermEdit = (termId: number) =>
    run(async () => {
      await patch(`/terms/${termId}`, termPayload())
      setEditingTerm(null)
      setTermForm(EMPTY_TERM)
    })

  const startEditTerm = (t: Term) => {
    setEditingTerm(t.id)
    setTermForm({ payee_id: t.payee_id?.toString() ?? '', category: t.category, payout_type: t.payout_type ?? '', kind: t.kind, amount: t.amount, currency: t.currency, language: t.language ?? '' })
  }

  const quickAddPayee = () =>
    run(async () => {
      const r = await post<{ id: number; name: string }>('/payees', { name: newPayee })
      setPayees((p) => [...p, { id: r.id, name: r.name, active: true }])
      setTermForm((f) => ({ ...f, payee_id: r.id.toString() }))
      setNewPayee('')
    })

  if (notFound) {
    return (
      <div className="space-y-4">
        <Link to="/codes" className="inline-flex items-center gap-1 text-sm text-sky-600 hover:underline"><ArrowLeft size={15} /> Back to codes</Link>
        <Card><p className="text-sm text-gray-500">Code not found.</p></Card>
      </div>
    )
  }
  if (!code) return <p className="text-sm text-gray-400">Loading…</p>

  // Schedule preview: how the configured charge would split today (flat +
  // percent lines that aren't language-scoped, residual takes the rest).
  const activeTerms = code.payout_terms.filter((t) => t.active)
  const chargeNum = Number(meta.charge_amount)
  const allocated = activeTerms
    .filter((t) => t.category !== 'residual')
    .reduce((sum, t) => sum + (t.kind === 'percent_of_charge' ? (chargeNum * Number(t.amount)) / 100 : Number(t.amount)), 0)
  const residualLine = activeTerms.find((t) => t.category === 'residual')

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <Link to="/codes" className="inline-flex items-center gap-1 text-sm text-sky-600 hover:underline"><ArrowLeft size={15} /> Back to codes</Link>
        <Button
          kind={code.active ? 'danger' : 'secondary'}
          onClick={() => run(() => patch(`/codes/${codeParam}`, { active: !code.active }))}
        >
          {code.active ? 'Revoke code' : 'Restore code'}
        </Button>
      </div>

      {error && <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <Explainer title="charges, payouts, and what locks when">
        <p>
          Every usage of this code logs a charge: the first usage per order charges the configured amount and splits
          it into payouts; repeats log $0 pointing at the original — royalty due once per order, structurally. Order
          type and scopes freeze after first use; a payout line freezes once it has accrued a real payout (end it and
          add a new line instead — history is never rewritten). Charge changes apply to future orders only; the
          ledgers never change retroactively.
        </p>
      </Explainer>

      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-lg font-semibold text-gray-900">{code.name ?? '(unnamed)'}</h1>
        <code className="text-xs text-gray-400">{code.code}</code>
        <Badge tone={orderTypeTone[code.order_type]}>{ORDER_TYPE_LABELS[code.order_type] ?? code.order_type}</Badge>
        {code.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>}
      </div>

      <Card title="Metadata & charge">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name"><input className={inputClass} value={meta.name} onChange={(e) => setMeta({ ...meta, name: e.target.value })} /></Field>
          <Field label="Client">
            <select className={inputClass} value={meta.client_id} onChange={(e) => setMeta({ ...meta, client_id: e.target.value })}>
              <option value="">— none —</option>
              {clients.filter((c) => c.active || c.id.toString() === meta.client_id).map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </Field>
          <Field label="Charge per order (future orders only)"><input className={inputClass} type="number" min="0" step="0.01" value={meta.charge_amount} onChange={(e) => setMeta({ ...meta, charge_amount: e.target.value })} /></Field>
          <Field label="Charge currency"><input className={inputClass} maxLength={3} value={meta.charge_currency} onChange={(e) => setMeta({ ...meta, charge_currency: e.target.value.toUpperCase() })} /></Field>
          <Field label="Max uses (blank = unlimited)"><input className={inputClass} type="number" min="1" value={meta.max_uses} onChange={(e) => setMeta({ ...meta, max_uses: e.target.value })} /></Field>
          <Field label="Expires (blank = never)"><input className={inputClass} type="date" value={meta.expires_at} onChange={(e) => setMeta({ ...meta, expires_at: e.target.value })} /></Field>
          <div className="sm:col-span-2">
            <Field label="Notes"><textarea className={inputClass} rows={2} value={meta.notes} onChange={(e) => setMeta({ ...meta, notes: e.target.value })} /></Field>
          </div>
        </div>
        {isAdmin && <Button onClick={saveMeta} kind="secondary">Save</Button>}
      </Card>

      <Card title="Order type and allowed scopes">
        {code.scope_and_type_locked ? (
          <p className="mb-3 text-sm text-amber-700">
            <Badge tone="amber">locked</Badge> This code has scored {code.uses_count} time{code.uses_count === 1 ? '' : 's'} — order type and scopes
            are frozen so historical usage and conversion reporting stay accurate. Issue a new code instead.
          </p>
        ) : (
          <p className="mb-3 text-xs text-gray-400">Never used yet — freely editable. Locks permanently after the first scoring call.</p>
        )}
        <div className="mb-4 flex flex-wrap gap-2">
          {Object.entries(SCOPE_LABELS).map(([value, label]) => {
            const selected = scopes.includes(value)
            return (
              <button
                key={value}
                type="button"
                disabled={code.scope_and_type_locked || !isAdmin}
                onClick={() => toggleScope(value)}
                aria-pressed={selected}
                className={`rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${selected ? 'bg-sky-600 text-white ring-sky-600' : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50'}`}
              >
                {label} <code className={selected ? 'text-sky-100' : 'text-gray-400'}>{value}</code>
              </button>
            )
          })}
        </div>
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Order type">
            <select className={inputClass} disabled={code.scope_and_type_locked || !isAdmin} value={orderType} onChange={(e) => setOrderType(e.target.value)}>
              {Object.entries(ORDER_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </Field>
          {isAdmin && !code.scope_and_type_locked && <Button kind="secondary" onClick={saveScopesAndType}>Save type & scopes</Button>}
        </div>
      </Card>

      <Card title="Payout schedule">
        <p className="mb-3 text-xs text-gray-400">
          How each real charge splits among stakeholders. Lines are ended, never deleted. The residual line absorbs
          the balance so the schedule always sums to the charge; language-scoped lines only fire on matching-language
          orders (the residual grows when they don't fire).
        </p>

        {chargeNum > 0 && activeTerms.length > 0 && (
          <div className="mb-4 rounded-lg bg-gray-50 p-3 text-xs text-gray-600">
            Preview on a {chargeNum.toFixed(2)} {meta.charge_currency} charge (if every line fires):
            itemized {allocated.toFixed(2)} · {residualLine
              ? `residual to ${residualLine.recipient}: ${(chargeNum - allocated).toFixed(2)}`
              : <span className="font-medium text-amber-700">no residual line — {(chargeNum - allocated).toFixed(2)} unallocated</span>}
          </div>
        )}

        {code.payout_terms.length === 0 && <p className="mb-3 text-sm text-gray-400">No payout lines — real charges under this code would pay out nothing.</p>}
        <ul className="mb-4 space-y-2">
          {code.payout_terms.map((t) => (
            <li key={t.id} className="rounded-lg border border-gray-100 p-3 text-sm">
              {editingTerm === t.id ? (
                <div className="flex flex-wrap items-end gap-2">
                  <Field label="Payee">
                    <select className={inputClass} value={termForm.payee_id} onChange={(e) => setTermForm({ ...termForm, payee_id: e.target.value })}>
                      {payees.map((p) => (
                        <option key={p.id} value={p.id}>{p.name}</option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Amount"><input className={inputClass} type="number" step="0.01" value={termForm.amount} onChange={(e) => setTermForm({ ...termForm, amount: e.target.value })} /></Field>
                  <Button onClick={() => saveTermEdit(t.id)}>Save</Button>
                  <Button kind="secondary" onClick={() => setEditingTerm(null)}>Cancel</Button>
                </div>
              ) : (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-gray-700">
                    {describeTerm(t)}
                    {t.locked && <span className="ml-2"><Badge tone="gray">accrued</Badge></span>}
                    {!t.active && <span className="ml-2"><Badge tone="gray">ended</Badge></span>}
                  </span>
                  {isAdmin && t.active && (
                    <span className="flex gap-2">
                      {!t.locked && <Button kind="secondary" onClick={() => startEditTerm(t)}>Edit</Button>}
                      <Button kind="secondary" onClick={() => run(() => post(`/terms/${t.id}/end`))}>End line</Button>
                    </span>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>

        {isAdmin && (
          <>
            <h3 className="mb-2 text-xs font-semibold uppercase text-gray-400">Add a line</h3>
            <div className="flex flex-wrap items-end gap-3">
              <Field label="Payee">
                <select className={inputClass} value={termForm.payee_id} onChange={(e) => setTermForm({ ...termForm, payee_id: e.target.value })}>
                  <option value="">— pick —</option>
                  {payees.filter((p) => p.active).map((p) => (
                    <option key={p.id} value={p.id}>{p.name}</option>
                  ))}
                </select>
              </Field>
              <Field label="…or new payee">
                <span className="flex gap-2">
                  <input className={`${inputClass} w-36`} value={newPayee} onChange={(e) => setNewPayee(e.target.value)} />
                  <Button kind="secondary" onClick={quickAddPayee} disabled={newPayee.trim() === ''}>Add</Button>
                </span>
              </Field>
              <Field label="Category">
                <select className={inputClass} value={termForm.category} onChange={(e) => setTermForm({ ...termForm, category: e.target.value, payout_type: e.target.value === 'residual' ? 'residual_margin' : termForm.payout_type })}>
                  {Object.entries(PAYOUT_CATEGORY_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </Field>
              <Field label="Payout type">
                <select className={inputClass} value={termForm.payout_type} onChange={(e) => setTermForm({ ...termForm, payout_type: e.target.value })}>
                  <option value="">—</option>
                  {Object.entries(PAYOUT_TYPE_OPTIONS).map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </Field>
              {termForm.category !== 'residual' && (
                <>
                  <Field label="Kind">
                    <select className={inputClass} value={termForm.kind} onChange={(e) => setTermForm({ ...termForm, kind: e.target.value })}>
                      {Object.entries(TERM_KIND_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>{label}</option>
                      ))}
                    </select>
                  </Field>
                  <Field label={termForm.kind === 'percent_of_charge' ? '% of charge' : 'Amount'}>
                    <input className={inputClass} type="number" min="0" step="0.01" value={termForm.amount} onChange={(e) => setTermForm({ ...termForm, amount: e.target.value })} />
                  </Field>
                </>
              )}
              <Field label="Currency"><input className={`${inputClass} w-20`} maxLength={3} value={termForm.currency} onChange={(e) => setTermForm({ ...termForm, currency: e.target.value.toUpperCase() })} /></Field>
              <Field label="Language">
                <select className={inputClass} value={termForm.language} onChange={(e) => setTermForm({ ...termForm, language: e.target.value })}>
                  <option value="">All languages</option>
                  <option value="en">English only</option>
                  <option value="fr">French only</option>
                  <option value="pt">Portuguese only</option>
                </select>
              </Field>
              <Button onClick={addTerm} disabled={termForm.payee_id === '' || (termForm.category !== 'residual' && termForm.amount === '')}>
                Add line
              </Button>
            </div>
          </>
        )}
      </Card>

      <Card title="Recent charges">
        {code.recent_charges.length === 0 && <p className="text-sm text-gray-400">Never used yet.</p>}
        <ul className="space-y-1 text-sm">
          {code.recent_charges.map((c) => (
            <li key={c.id} className="flex flex-wrap justify-between gap-2 border-b border-gray-50 py-1">
              <span className="text-gray-700">
                {Number(c.amount) > 0 ? `${c.amount} ${c.currency}` : '$0'}
                {c.is_repeat && <Badge tone="gray">repeat</Badge>}
                {c.external_order_id && <code className="ml-2 text-xs text-gray-400">{c.external_order_id}</code>}
              </span>
              <span className="text-xs text-gray-400">{c.payout_count} payout{c.payout_count === 1 ? '' : 's'} · {c.created_at.slice(0, 16).replace('T', ' ')}</span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}
