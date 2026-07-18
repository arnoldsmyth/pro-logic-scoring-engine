import { useEffect, useState } from 'react'
import { BadgeDollarSign, Coins, Receipt, TrendingUp, Wallet } from 'lucide-react'
import { get, qs } from '../../api'
import { DataTable, type Column } from '../../components/DataTable'
import { DateRangePicker, type DateRange } from '../../components/DateRangePicker'
import { Badge, Button, Card, Explainer, Field, inputClass, StatCard } from '../../components/ui'
import { PAYOUT_TYPE_OPTIONS } from '../../labels'

type Money = Record<string, number>

type ReportLine = {
  payout_id: number
  recipient: string
  category: 'royalty' | 'fee' | 'residual'
  payout_type: string | null
  language: string | null
  amount: number
  currency: string
  status: 'accrued' | 'paid' | 'void'
  charge_id: number
  original_charge_id: number | null
  product_code: string | null
  external_order_id: string | null
  order_type: string
  charge_amount: number
  charge_date: string
}

type ReportGroup = {
  key: string
  label: string
  totals: { accrued: Money; paid: Money; void: Money; net_owed: Money; lines: number }
  lines: ReportLine[]
}

type RoyaltyReport = {
  period: { from: string; to: string }
  group_by: string
  totals: {
    accrued: Money
    paid: Money
    void: Money
    net_owed: Money
    charges: number
    repeat_charges: number
  }
  conversion: { leads: number; converted: number; rate: number | null }
  groups: ReportGroup[]
}

const money = (currencies: Money) =>
  Object.entries(currencies)
    .map(([cur, amt]) => `${amt.toFixed(2)} ${cur}`)
    .join(' · ') || '0.00'

const GROUP_BY_OPTIONS: Record<string, string> = {
  payee: 'Payee',
  client: 'Client',
  code: 'Code',
  order_type: 'Order type',
}

const STATUSES = ['accrued', 'paid', 'void'] as const
const categoryTone = { royalty: 'blue', fee: 'gray', residual: 'amber' } as const
// Short labels for the badge — the full PAYOUT_CATEGORY_LABELS ("Residual
// (balance catch-all)") is too long for an inline chip.
const categoryLabel = { royalty: 'Royalty', fee: 'Fee', residual: 'Residual' } as const

const pad = (n: number) => String(n).padStart(2, '0')
const monthStart = () => {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`
}
const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

type Payee = { id: number; name: string }

export default function RoyaltyStatement() {
  const [range, setRange] = useState<DateRange>({ from: monthStart(), to: today() })
  const [groupBy, setGroupBy] = useState('payee')
  const [payeeId, setPayeeId] = useState('')
  const [statuses, setStatuses] = useState<string[]>([])
  const [payees, setPayees] = useState<Payee[]>([])
  const [report, setReport] = useState<RoyaltyReport | null>(null)
  const [loading, setLoading] = useState(false)

  // Params sent to both the JSON fetch and the CSV download — kept identical
  // so the export always mirrors what's on screen.
  const params = {
    from: range.from,
    to: range.to,
    group_by: groupBy,
    payee_id: payeeId,
    status: statuses.join(','),
  }

  const load = () => {
    setLoading(true)
    get<RoyaltyReport>(`/reports/royalties?${qs(params)}`)
      .then(setReport)
      .finally(() => setLoading(false))
  }

  // Initial load only; subsequent loads are user-driven via the Filter button.
  useEffect(() => {
    load()
    get<{ payees: Payee[] }>('/payees')
      .then((r) => setPayees(r.payees))
      .catch(() => setPayees([]))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const toggleStatus = (s: string) =>
    setStatuses((prev) => (prev.includes(s) ? prev.filter((x) => x !== s) : [...prev, s]))

  const t = report?.totals

  return (
    <div className="space-y-4">
      <Explainer title="royalty statements">
        <p>
          This report reads straight from the <b>charges &amp; payouts ledgers</b> — not Stripe. Every order logs one
          charge; a <b>royalty is due exactly once per order</b>, so a repeat usage records a $0 charge that references
          the original rather than charging again.
        </p>
        <p>
          For each charge the <b>payouts always sum to the charge amount</b>: the <b>residual</b> line absorbs whatever
          balance is left after the fixed royalty and fee lines. A negative residual means the fixed lines exceed the
          charge — a visible misconfiguration, shown in red, not hidden.
        </p>
        <p>
          Amounts are <b>never summed across currencies</b>; each currency is totalled on its own (e.g. "12.00 USD ·
          5.00 EUR"). Lead → sale conversion is inferred from orders carrying both a lead and a later sale charge.
        </p>
      </Explainer>

      <Card
        title="Filters"
        actions={
          <a
            className="text-sm text-sky-600 hover:underline"
            href={`/panel/api/reports/royalties.csv?${qs(params)}`}
          >
            Export CSV
          </a>
        }
      >
        <div className="flex flex-wrap items-end gap-3">
          <DateRangePicker value={range} onChange={setRange} />
          <Field label="Group by">
            <select className={inputClass} value={groupBy} onChange={(e) => setGroupBy(e.target.value)}>
              {Object.entries(GROUP_BY_OPTIONS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Payee">
            <select className={inputClass} value={payeeId} onChange={(e) => setPayeeId(e.target.value)}>
              <option value="">All payees</option>
              {payees.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label="Status">
            <div className="flex gap-1">
              {STATUSES.map((s) => (
                <button
                  key={s}
                  type="button"
                  onClick={() => toggleStatus(s)}
                  className={`rounded-lg border px-2.5 py-2 text-sm capitalize transition-colors ${
                    statuses.includes(s)
                      ? 'border-sky-500 bg-sky-50 text-sky-700'
                      : 'border-gray-300 bg-white text-gray-500 hover:bg-gray-50'
                  }`}
                >
                  {s}
                </button>
              ))}
            </div>
          </Field>
          <Button onClick={load}>Filter</Button>
        </div>
      </Card>

      {t && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard label="Total accrued" value={money(t.accrued)} icon={<Coins size={20} strokeWidth={1.75} />} />
          <StatCard label="Total paid" value={money(t.paid)} icon={<Wallet size={20} strokeWidth={1.75} />} />
          <StatCard label="Net owed" value={money(t.net_owed)} icon={<BadgeDollarSign size={20} strokeWidth={1.75} />} />
          <StatCard
            label={`${t.repeat_charges} repeat ($0)`}
            value={t.charges}
            icon={<Receipt size={20} strokeWidth={1.75} />}
          />
          <StatCard
            label={`${report.conversion.converted} of ${report.conversion.leads} leads`}
            value={report.conversion.rate !== null ? `${report.conversion.rate}%` : '—'}
            icon={<TrendingUp size={20} strokeWidth={1.75} />}
          />
        </div>
      )}

      {loading && <p className="text-sm text-gray-400">Loading…</p>}

      {!loading && report && report.groups.length === 0 && (
        <Card title={`${report.period.from} → ${report.period.to}`}>
          <p className="py-6 text-center text-sm text-gray-400">No charges in this period.</p>
        </Card>
      )}

      {!loading &&
        report?.groups.map((group) => (
          <Card
            key={group.key}
            title={group.label}
            actions={
              <div className="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-gray-500">
                <span>
                  accrued <span className="font-medium text-gray-700">{money(group.totals.accrued)}</span>
                </span>
                <span>
                  paid <span className="font-medium text-gray-700">{money(group.totals.paid)}</span>
                </span>
                <span>
                  net owed <span className="font-medium text-gray-700">{money(group.totals.net_owed)}</span>
                </span>
              </div>
            }
          >
            <DataTable
              rows={group.lines}
              rowKey={(l) => l.payout_id}
              empty="No payout lines."
              columns={
                [
                  {
                    header: 'Recipient',
                    primary: true,
                    cell: (l) => <span className="font-medium text-gray-700">{l.recipient}</span>,
                  },
                  {
                    header: 'Category',
                    cell: (l) => (
                      <Badge tone={categoryTone[l.category]}>{categoryLabel[l.category] ?? l.category}</Badge>
                    ),
                  },
                  {
                    header: 'Payout type',
                    cell: (l) =>
                      l.payout_type ? (
                        <span className="text-xs">{PAYOUT_TYPE_OPTIONS[l.payout_type] ?? l.payout_type}</span>
                      ) : (
                        <span className="text-gray-300">—</span>
                      ),
                  },
                  {
                    header: 'Charge ref',
                    cell: (l) => (
                      <div className="text-xs">
                        <div className="text-gray-600">
                          {l.product_code ?? '—'}
                          {l.external_order_id && <span className="text-gray-400"> · {l.external_order_id}</span>}
                        </div>
                        {l.original_charge_id !== null && (
                          <div className="text-gray-400">repeat of #{l.original_charge_id}</div>
                        )}
                      </div>
                    ),
                  },
                  {
                    header: 'Amount',
                    cell: (l) => {
                      const amt = l.original_charge_id !== null ? 0 : l.amount
                      return (
                        <span className={amt < 0 ? 'font-medium text-red-600' : 'text-gray-700'}>
                          {amt.toFixed(2)} {l.currency}
                        </span>
                      )
                    },
                  },
                  {
                    header: 'Status',
                    cell: (l) =>
                      l.status === 'paid' ? (
                        <Badge tone="green">paid</Badge>
                      ) : l.status === 'accrued' ? (
                        <Badge tone="blue">accrued</Badge>
                      ) : (
                        <span className="text-xs text-gray-400 line-through">void</span>
                      ),
                  },
                ] satisfies Column<ReportLine>[]
              }
            />
          </Card>
        ))}
    </div>
  )
}
