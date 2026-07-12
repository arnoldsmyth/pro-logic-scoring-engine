import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { ApiError, get, patch, post } from '../../api'
import { useAuth } from '../../auth'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../../components/ui'
import { CODE_TYPE_LABELS, SCOPE_LABELS, TERM_KIND_LABELS } from '../../labels'
import type { CodeDetail as CodeDetailType } from './types'

const EMPTY_TERM = { recipient: '', kind: 'flat_per_report', amount: '', currency: 'USD', language: '' }
const typeTone = { training: 'blue', bizdev: 'amber', derivative: 'gray' } as const

export default function CodeDetail() {
  const { code: codeParam } = useParams<{ code: string }>()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [code, setCode] = useState<CodeDetailType | null>(null)
  const [meta, setMeta] = useState({ name: '', issued_to: '', notes: '', max_uses: '', expires_at: '' });
  const [scopes, setScopes] = useState<string[]>([])
  const [type, setType] = useState('training')
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
          issued_to: c.issued_to ?? '',
          notes: c.notes ?? '',
          max_uses: c.max_uses?.toString() ?? '',
          expires_at: c.expires_at?.slice(0, 10) ?? '',
        })
        setScopes(c.allowed_scopes)
        setType(c.type)
      })
      .catch(() => setNotFound(true))
  }
  useEffect(load, [codeParam])

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
      issued_to: meta.issued_to || null,
      notes: meta.notes || null,
      max_uses: meta.max_uses ? Number(meta.max_uses) : null,
      expires_at: meta.expires_at || null,
    }))

  const saveScopesAndType = () => run(() => patch(`/codes/${codeParam}`, { type, allowed_scopes: scopes }))

  const addTerm = () =>
    run(async () => {
      await post(`/codes/${codeParam}/terms`, {
        recipient: termForm.recipient,
        kind: termForm.kind,
        amount: Number(termForm.amount),
        currency: termForm.currency,
        language: termForm.language || null,
      })
      setTermForm(EMPTY_TERM)
    })

  const saveTermEdit = (termId: number) =>
    run(async () => {
      await patch(`/terms/${termId}`, {
        recipient: termForm.recipient,
        kind: termForm.kind,
        amount: Number(termForm.amount),
        currency: termForm.currency,
        language: termForm.language || null,
      })
      setEditingTerm(null)
      setTermForm(EMPTY_TERM)
    })

  const startEditTerm = (t: CodeDetailType['royalty_terms'][number]) => {
    setEditingTerm(t.id)
    setTermForm({ recipient: t.recipient, kind: t.kind, amount: t.amount, currency: t.currency, language: t.language ?? '' })
  }

  if (notFound) {
    return (
      <div className="space-y-4">
        <Link to="/codes" className="inline-flex items-center gap-1 text-sm text-sky-600 hover:underline"><ArrowLeft size={15} /> Back to codes</Link>
        <Card><p className="text-sm text-gray-500">Code not found.</p></Card>
      </div>
    )
  }
  if (!code) return <p className="text-sm text-gray-400">Loading…</p>

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

      <Explainer title="what CRUD means here">
        <p>
          Revoke stops a code from scoring anything new but never deletes it — historical usage_events and
          scored_results keep referencing it forever, exactly as recorded. There is no delete anywhere in this flow:
          type and scopes freeze the moment a code is first used (changing them retroactively would misrepresent what
          earlier calls were permitted to do), and a royalty term freezes the moment it produces its first fee
          (editing it would rewrite an already-charged amount). The only path forward for either is end/revoke +
          issue something new — the audit trail never gets rewritten.
        </p>
      </Explainer>

      <div className="flex flex-wrap items-center gap-2">
        <h1 className="text-lg font-semibold text-gray-900">{code.name ?? '(unnamed)'}</h1>
        <code className="text-xs text-gray-400">{code.code}</code>
        <Badge tone={typeTone[code.type]}>{code.type}</Badge>
        {code.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>}
      </div>

      <Card title="Metadata">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Name"><input className={inputClass} value={meta.name} onChange={(e) => setMeta({ ...meta, name: e.target.value })} /></Field>
          <Field label="Issued to"><input className={inputClass} value={meta.issued_to} onChange={(e) => setMeta({ ...meta, issued_to: e.target.value })} /></Field>
          <Field label="Max uses (blank = unlimited)"><input className={inputClass} type="number" min="1" value={meta.max_uses} onChange={(e) => setMeta({ ...meta, max_uses: e.target.value })} /></Field>
          <Field label="Expires (blank = never)"><input className={inputClass} type="date" value={meta.expires_at} onChange={(e) => setMeta({ ...meta, expires_at: e.target.value })} /></Field>
          <div className="sm:col-span-2">
            <Field label="Notes"><textarea className={inputClass} rows={2} value={meta.notes} onChange={(e) => setMeta({ ...meta, notes: e.target.value })} /></Field>
          </div>
        </div>
        {isAdmin && <Button onClick={saveMeta} kind="secondary">Save metadata</Button>}
      </Card>

      <Card title="Type and allowed scopes">
        {code.scope_and_type_locked ? (
          <p className="mb-3 text-sm text-amber-700">
            <Badge tone="amber">locked</Badge> This code has scored {code.uses_count} time{code.uses_count === 1 ? '' : 's'} — type and scopes
            are frozen so historical usage stays accurate. Issue a new code instead of changing what this one is allowed to do.
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
          <Field label="Type">
            <select className={inputClass} disabled={code.scope_and_type_locked || !isAdmin} value={type} onChange={(e) => setType(e.target.value)}>
              {Object.entries(CODE_TYPE_LABELS).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </Field>
          {isAdmin && !code.scope_and_type_locked && <Button kind="secondary" onClick={saveScopesAndType}>Save type & scopes</Button>}
        </div>
      </Card>

      <Card title="Royalty terms">
        <p className="mb-3 text-xs text-gray-400">
          Terms are ended, never deleted — history stays intact. A term that has already produced a fee locks against
          editing (end it and add a new one instead); an unused term can be corrected freely.
        </p>
        {code.royalty_terms.length === 0 && <p className="mb-3 text-sm text-gray-400">No terms — this code currently owes nothing when used.</p>}
        <ul className="mb-4 space-y-2">
          {code.royalty_terms.map((t) => (
            <li key={t.id} className="rounded-lg border border-gray-100 p-3 text-sm">
              {editingTerm === t.id ? (
                <div className="flex flex-wrap items-end gap-2">
                  <Field label="Recipient"><input className={inputClass} value={termForm.recipient} onChange={(e) => setTermForm({ ...termForm, recipient: e.target.value })} /></Field>
                  <Field label="Amount"><input className={inputClass} type="number" step="0.01" value={termForm.amount} onChange={(e) => setTermForm({ ...termForm, amount: e.target.value })} /></Field>
                  <Button onClick={() => saveTermEdit(t.id)}>Save</Button>
                  <Button kind="secondary" onClick={() => setEditingTerm(null)}>Cancel</Button>
                </div>
              ) : (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-gray-700">
                    {t.recipient} — {TERM_KIND_LABELS[t.kind] ?? t.kind}, {t.amount} {t.currency}
                    {t.language ? ` · ${t.language} only` : ' · all languages'}
                    {t.locked && <span className="ml-2"><Badge tone="gray">charged</Badge></span>}
                    {!t.active && <span className="ml-2"><Badge tone="gray">ended</Badge></span>}
                  </span>
                  {isAdmin && t.active && (
                    <span className="flex gap-2">
                      {!t.locked && <Button kind="secondary" onClick={() => startEditTerm(t)}>Edit</Button>}
                      <Button kind="secondary" onClick={() => run(() => post(`/terms/${t.id}/end`))}>End term</Button>
                    </span>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>

        {isAdmin && (
          <>
            <h3 className="mb-2 text-xs font-semibold uppercase text-gray-400">Add a term</h3>
            <div className="flex flex-wrap items-end gap-3">
              <Field label="Recipient"><input className={inputClass} value={termForm.recipient} onChange={(e) => setTermForm({ ...termForm, recipient: e.target.value })} /></Field>
              <Field label="Kind">
                <select className={inputClass} value={termForm.kind} onChange={(e) => setTermForm({ ...termForm, kind: e.target.value })}>
                  {Object.entries(TERM_KIND_LABELS).map(([value, label]) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </select>
              </Field>
              <Field label="Amount"><input className={inputClass} type="number" min="0" step="0.01" value={termForm.amount} onChange={(e) => setTermForm({ ...termForm, amount: e.target.value })} /></Field>
              <Field label="Currency"><input className={`${inputClass} w-20`} maxLength={3} value={termForm.currency} onChange={(e) => setTermForm({ ...termForm, currency: e.target.value.toUpperCase() })} /></Field>
              <Field label="Language">
                <select className={inputClass} value={termForm.language} onChange={(e) => setTermForm({ ...termForm, language: e.target.value })}>
                  <option value="">All languages</option>
                  <option value="en">English only</option>
                  <option value="fr">French only</option>
                  <option value="pt">Portuguese only</option>
                </select>
              </Field>
              <Button onClick={addTerm} disabled={termForm.recipient.trim() === '' || termForm.amount === ''}>Add term</Button>
            </div>
          </>
        )}
      </Card>

      <Card title="Recent usage">
        {code.recent_usage.length === 0 && <p className="text-sm text-gray-400">Never used yet.</p>}
        <ul className="space-y-1 text-sm">
          {code.recent_usage.map((u) => (
            <li key={u.id} className="flex justify-between border-b border-gray-50 py-1">
              <span className="text-gray-700">{u.scopes.join(', ')}</span>
              <span className="text-xs text-gray-400">{u.fee_count} fee{u.fee_count === 1 ? '' : 's'} · {u.created_at.slice(0, 16).replace('T', ' ')}</span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}
