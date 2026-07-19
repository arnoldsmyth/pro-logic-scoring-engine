import { useEffect, useState } from 'react'
import { get } from '../../api'
import { DataTable, type Column } from '../../components/DataTable'
import { Card, Explainer } from '../../components/ui'

type AgingRow = {
  payee_id: number | null
  recipient: string
  currency: string
  accrued: number
  paid: number
  void: number
  aging: { d0_30: number; d31_60: number; d61_90: number; d90_plus: number }
}

type AgingReport = {
  as_of: string
  rows: AgingRow[]
}

const fmt = (n: number) => n.toFixed(2)

export default function PayoutAging() {
  const [report, setReport] = useState<AgingReport | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    setLoading(true)
    get<AgingReport>('/reports/payout-aging')
      .then(setReport)
      .finally(() => setLoading(false))
  }, [])

  return (
    <div className="space-y-4">
      <Explainer title="payout aging">
        <p>
          Each row is one <b>payee × currency</b> pair, summed straight from the payouts ledger across all time —
          amounts are <b>never blended across currencies</b>.
        </p>
        <p>
          The aging buckets split the <b>accrued (unpaid)</b> balance by how long it's been outstanding — 0–30, 31–60,
          61–90, and 90+ days — so the office team can see who's overdue and prioritize settlements accordingly.
        </p>
        <p>
          <b>Paid</b> and <b>void</b> columns are lifetime totals shown for context; they don't factor into the aging
          buckets, which only track money still owed.
        </p>
      </Explainer>

      <Card
        title="Payout aging"
        actions={report && <span className="text-xs text-gray-400">as of {report.as_of}</span>}
      >
        {loading && <p className="text-sm text-gray-400">Loading…</p>}
        {!loading && (
          <DataTable
            rows={report?.rows ?? []}
            rowKey={(r) => `${r.payee_id ?? 'none'}-${r.currency}`}
            empty="No payouts recorded yet."
            columns={
              [
                {
                  header: 'Recipient',
                  primary: true,
                  cell: (r) => <span className="font-medium text-gray-700">{r.recipient}</span>,
                },
                { header: 'Currency', cell: (r) => r.currency },
                {
                  header: 'Accrued',
                  cell: (r) => (
                    <span className={r.accrued > 0 ? 'font-semibold text-gray-800' : 'text-gray-600'}>
                      {fmt(r.accrued)}
                    </span>
                  ),
                },
                {
                  header: '0–30 d',
                  cell: (r) => (
                    <span className={r.aging.d0_30 > 0 ? 'text-red-600' : 'text-gray-600'}>{fmt(r.aging.d0_30)}</span>
                  ),
                },
                {
                  header: '31–60 d',
                  cell: (r) => (
                    <span className={r.aging.d31_60 > 0 ? 'text-red-600' : 'text-gray-600'}>
                      {fmt(r.aging.d31_60)}
                    </span>
                  ),
                },
                {
                  header: '61–90 d',
                  cell: (r) => (
                    <span className={r.aging.d61_90 > 0 ? 'text-red-600' : 'text-gray-600'}>
                      {fmt(r.aging.d61_90)}
                    </span>
                  ),
                },
                {
                  header: '90+ d',
                  cell: (r) => (
                    <span className={r.aging.d90_plus > 0 ? 'text-red-600' : 'text-gray-600'}>
                      {fmt(r.aging.d90_plus)}
                    </span>
                  ),
                },
                { header: 'Paid', cell: (r) => fmt(r.paid) },
                { header: 'Void', cell: (r) => fmt(r.void) },
              ] satisfies Column<AgingRow>[]
            }
          />
        )}
      </Card>
    </div>
  )
}
