import { useEffect, useState } from 'react'
import { ClipboardList, FileBarChart } from 'lucide-react'
import { get, qs } from '../../api'
import { DataTable, type Column } from '../../components/DataTable'
import { DateRangePicker, type DateRange } from '../../components/DateRangePicker'
import { Bars, Button, Card, Explainer, Field, inputClass, StatCard } from '../../components/ui'

type SeriesPoint = { date: string; created: number; scored: number }
type Slice = { key: string; label: string; created: number; scored: number }

type VolumeReport = {
  period: { from: string; to: string }
  group_by: string
  totals: { created: number; scored: number }
  series: SeriesPoint[]
  slices: Slice[]
}

const GROUP_BY_OPTIONS: Record<string, string> = {
  language: 'Language',
  gender: 'Gender',
  client: 'Client',
  scope: 'Scope',
}

const pad = (n: number) => String(n).padStart(2, '0')
const monthStart = () => {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`
}
const today = () => {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

// ISO week key ('YYYY-Www') for client-side bucketing of long date ranges.
// Bars still slices the first 5 chars off labels, so these display as 'Www'.
const isoWeekKey = (dateStr: string) => {
  const d = new Date(`${dateStr}T00:00:00Z`)
  const day = (d.getUTCDay() + 6) % 7
  d.setUTCDate(d.getUTCDate() - day + 3)
  const firstThursday = new Date(Date.UTC(d.getUTCFullYear(), 0, 4))
  const firstDay = (firstThursday.getUTCDay() + 6) % 7
  firstThursday.setUTCDate(firstThursday.getUTCDate() - firstDay + 3)
  const week = 1 + Math.round((d.getTime() - firstThursday.getTime()) / (7 * 86400000))
  return `${d.getUTCFullYear()}-W${pad(week)}`
}

const toBars = (series: SeriesPoint[], field: 'created' | 'scored'): Record<string, number> => {
  if (series.length <= 35) {
    const out: Record<string, number> = {}
    for (const p of series) out[p.date] = p[field]
    return out
  }
  const buckets: Record<string, number> = {}
  for (const p of series) {
    const key = isoWeekKey(p.date)
    buckets[key] = (buckets[key] ?? 0) + p[field]
  }
  return buckets
}

export default function AssessmentVolume() {
  const [range, setRange] = useState<DateRange>({ from: monthStart(), to: today() })
  const [groupBy, setGroupBy] = useState('language')
  const [report, setReport] = useState<VolumeReport | null>(null)
  const [loading, setLoading] = useState(false)

  const params = { from: range.from, to: range.to, group_by: groupBy }

  const load = () => {
    setLoading(true)
    get<VolumeReport>(`/reports/volume?${qs(params)}`)
      .then(setReport)
      .finally(() => setLoading(false))
  }

  // Initial load only; subsequent loads are user-driven via the Filter button.
  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const t = report?.totals
  const isScope = groupBy === 'scope'

  return (
    <div className="space-y-4">
      <Explainer title="assessment volume">
        <p>
          Counts are read from the <b>assessments</b> and <b>scored-results</b> tables over an arbitrary date range —
          not just the dashboard's fixed 14 days. <b>Created</b> is assessments registered; <b>scored</b> is scoring
          calls that produced results.
        </p>
        <p>
          Slice by language, gender, client, or scope. For <b>scope</b>, a scored result counts once per scope it
          includes, so scope counts can exceed the total scored.
        </p>
      </Explainer>

      <Card title="Filters">
        <div className="flex flex-wrap items-end gap-3">
          <DateRangePicker value={range} onChange={setRange} />
          <Field label="Slice by">
            <select className={inputClass} value={groupBy} onChange={(e) => setGroupBy(e.target.value)}>
              {Object.entries(GROUP_BY_OPTIONS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Button onClick={load}>Filter</Button>
        </div>
      </Card>

      {t && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <StatCard label="Assessments created" value={t.created} icon={<ClipboardList size={20} strokeWidth={1.75} />} />
          <StatCard label="Results scored" value={t.scored} icon={<FileBarChart size={20} strokeWidth={1.75} />} />
        </div>
      )}

      {loading && <p className="text-sm text-gray-400">Loading…</p>}

      {!loading && report && (
        <>
          <Card title="Created per day">
            <Bars data={toBars(report.series, 'created')} />
          </Card>

          <Card title="Scored per day">
            <Bars data={toBars(report.series, 'scored')} />
          </Card>

          <Card title={`By ${GROUP_BY_OPTIONS[report.group_by]?.toLowerCase() ?? report.group_by}`}>
            <DataTable
              rows={report.slices}
              rowKey={(s) => s.key}
              empty="No activity in this period."
              columns={
                [
                  {
                    header: 'Group',
                    primary: true,
                    cell: (s) => <span className="font-medium text-gray-700">{s.label}</span>,
                  },
                  {
                    header: 'Created',
                    cell: (s) => (isScope ? <span className="text-gray-300">—</span> : s.created),
                  },
                  {
                    header: 'Scored',
                    cell: (s) => s.scored,
                  },
                ] satisfies Column<Slice>[]
              }
            />
          </Card>
        </>
      )}
    </div>
  )
}
