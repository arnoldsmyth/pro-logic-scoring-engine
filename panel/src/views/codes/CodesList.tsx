import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { get } from '../../api'
import { useAuth } from '../../auth'
import { DataTable, type Column } from '../../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../../components/ui'
import { ORDER_TYPE_LABELS } from '../../labels'
import type { CodeSummary } from './types'

type Statement = {
  period: { from: string; to: string }
  charges: number
  repeat_charges: number
  by_order_type: Record<string, { usages: number; charged: Record<string, number> }>
  payouts_by_recipient: Record<string, Record<string, number>>
  payouts_by_code: Record<string, Record<string, number>>
  conversion: { leads: number; converted: number; rate: number | null }
}

const orderTypeTone = { training: 'blue', complimentary: 'gray', lead: 'amber', sale: 'green' } as const

const money = (currencies: Record<string, number>) =>
  Object.entries(currencies).map(([cur, amt]) => `${amt.toFixed(2)} ${cur}`).join(' · ') || '0.00'

export default function CodesList() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [codes, setCodes] = useState<CodeSummary[]>([])
  const [statement, setStatement] = useState<Statement | null>(null)
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')

  const search = () => {
    const params = new URLSearchParams()
    if (q) params.set('q', q)
    if (status) params.set('status', status)
    get<{ codes: CodeSummary[] }>(`/codes?${params}`).then((r) => setCodes(r.codes))
  }
  useEffect(search, [status])
  useEffect(() => {
    get<Statement>('/codes/statement').then(setStatement)
  }, [])

  return (
    <div className="space-y-4">
      <Explainer title="codes, charges, and payouts">
        <p>
          A code grants scoring scopes for one catalog product and carries a <b>charge</b> — what the client owes per
          order — plus a <b>payout schedule</b> splitting that charge among stakeholders. The <b>order type</b>{' '}
          (training / complimentary / lead / sale) is a reporting dimension, never a gate: every usage logs a charge,
          most of which are simply $0 today. A repeat usage of the same order logs a $0 charge pointing at the
          original, so a royalty is due exactly once per order. Lead → sale conversion is inferred from an order id
          carrying both a lead charge and a later sale charge — never stored.
        </p>
      </Explainer>

      {statement && (
        <Card
          title={`Charges & payouts ${statement.period.from} → ${statement.period.to}`}
          actions={<a className="text-sm text-sky-600 hover:underline" href="/panel/api/codes/statement.csv">Export CSV</a>}
        >
          <div className="flex flex-wrap gap-8 text-sm">
            <div>
              <div className="text-xl font-semibold text-gray-800">{statement.charges}</div>
              <div className="text-xs text-gray-500">charges logged</div>
              <div className="mt-1 text-xs text-gray-400">{statement.repeat_charges} repeat ($0)</div>
            </div>
            <div>
              <div className="mb-1 text-xs uppercase tracking-wide text-gray-400">By order type</div>
              {Object.entries(statement.by_order_type).length === 0 && <p className="text-gray-400">No usage this period.</p>}
              {Object.entries(statement.by_order_type).map(([type, data]) => (
                <div key={type} className="flex justify-between gap-6 border-b border-gray-100 py-1">
                  <span className="text-gray-700">{ORDER_TYPE_LABELS[type] ?? type} <span className="text-xs text-gray-400">×{data.usages}</span></span>
                  <span className="font-medium text-gray-800">{money(data.charged)}</span>
                </div>
              ))}
            </div>
            <div className="min-w-48">
              <div className="mb-1 text-xs uppercase tracking-wide text-gray-400">Payouts by recipient</div>
              {Object.entries(statement.payouts_by_recipient).length === 0 && <p className="text-gray-400">No payouts due.</p>}
              {Object.entries(statement.payouts_by_recipient).map(([recipient, currencies]) => (
                <div key={recipient} className="flex justify-between gap-6 border-b border-gray-100 py-1">
                  <span className="text-gray-700">{recipient}</span>
                  <span className="font-medium text-gray-800">{money(currencies)}</span>
                </div>
              ))}
            </div>
            <div>
              <div className="mb-1 text-xs uppercase tracking-wide text-gray-400">Lead conversion</div>
              <div className="text-xl font-semibold text-gray-800">
                {statement.conversion.rate !== null ? `${statement.conversion.rate}%` : '—'}
              </div>
              <div className="text-xs text-gray-400">{statement.conversion.converted} of {statement.conversion.leads} leads converted</div>
            </div>
          </div>
        </Card>
      )}

      <Card
        title="Codes"
        actions={isAdmin ? (
          <Link to="/codes/new">
            <Button><Plus size={15} /> Issue new code</Button>
          </Link>
        ) : undefined}
      >
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <Field label="Search name, code, or client">
            <input className={`${inputClass} sm:w-72`} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && search()} />
          </Field>
          <Field label="Status">
            <select className={inputClass} value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">All</option>
              <option value="active">Active</option>
              <option value="revoked">Revoked</option>
            </select>
          </Field>
          <Button kind="secondary" onClick={search}>Filter</Button>
        </div>

        <DataTable
          rows={codes}
          rowKey={(c) => c.code}
          empty="No access codes issued yet."
          columns={[
            {
              header: 'Name',
              primary: true,
              cell: (c) => (
                <Link to={`/codes/${c.code}`} className="font-medium text-sky-700 hover:underline">
                  {c.name ?? '(unnamed)'}
                </Link>
              ),
            },
            { header: 'Code', cell: (c) => <code className="text-xs text-gray-400">{c.code}</code> },
            { header: 'Order type', cell: (c) => <Badge tone={orderTypeTone[c.order_type]}>{ORDER_TYPE_LABELS[c.order_type] ?? c.order_type}</Badge> },
            { header: 'Charge', cell: (c) => (Number(c.charge_amount) > 0 ? `${Number(c.charge_amount).toFixed(2)} ${c.charge_currency}` : <Badge tone="gray">$0</Badge>) },
            { header: 'Scopes', cell: (c) => <span className="text-xs">{c.allowed_scopes.join(', ')}</span> },
            { header: 'Uses', cell: (c) => `${c.uses_count}${c.max_uses !== null ? ` / ${c.max_uses}` : ''}` },
            {
              header: 'Payouts',
              cell: (c) => (c.active_payout_terms_count === 0 ? <Badge tone="gray">none</Badge> : <Badge tone="blue">{c.active_payout_terms_count} line{c.active_payout_terms_count === 1 ? '' : 's'}</Badge>),
            },
            { header: 'Status', cell: (c) => (c.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>) },
          ] satisfies Column<CodeSummary>[]}
          actions={(c) => (
            <Link to={`/codes/${c.code}`}>
              <Button kind="secondary">Manage</Button>
            </Link>
          )}
        />
      </Card>
    </div>
  )
}
