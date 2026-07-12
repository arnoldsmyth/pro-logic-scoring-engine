import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { get } from '../../api'
import { useAuth } from '../../auth'
import { DataTable, type Column } from '../../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../../components/ui'
import type { CodeSummary } from './types'

type Statement = {
  period: { from: string; to: string }
  events: number
  no_fee_events: number
  totals_by_recipient: Record<string, Record<string, number>>
  totals_by_code: Record<string, Record<string, number>>
}

const typeTone = { training: 'blue', bizdev: 'amber', derivative: 'gray' } as const

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
      <Explainer title="codes, royalty terms, and the metering trail">
        <p>
          A code is an opaque identifier granting specific scoring scopes against one catalog product; its display
          name is for reporting only. The <b>type</b> is a descriptive label — whether a code owes royalty is driven
          entirely by its royalty terms. Once a code has scored anything, its type and scopes lock (open the code to
          see why); metadata and royalty terms stay manageable from the detail page.
        </p>
      </Explainer>

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
              {Object.entries(statement.totals_by_recipient).length === 0 && <p className="text-gray-400">No royalties due this period.</p>}
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

      <Card
        title="Codes"
        actions={isAdmin ? (
          <Link to="/codes/new">
            <Button><Plus size={15} /> Issue new code</Button>
          </Link>
        ) : undefined}
      >
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <Field label="Search name, code, or issued-to">
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
            { header: 'Type', cell: (c) => <Badge tone={typeTone[c.type]}>{c.type}</Badge> },
            { header: 'Scopes', cell: (c) => <span className="text-xs">{c.allowed_scopes.join(', ')}</span> },
            { header: 'Uses', cell: (c) => `${c.uses_count}${c.max_uses !== null ? ` / ${c.max_uses}` : ''}` },
            {
              header: 'Royalty',
              cell: (c) => (c.active_royalty_terms_count === 0 ? <Badge tone="gray">none due</Badge> : <Badge tone="blue">{c.active_royalty_terms_count} active term{c.active_royalty_terms_count === 1 ? '' : 's'}</Badge>),
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
