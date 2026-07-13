import { useEffect, useState } from 'react'
import { Activity, ClipboardList, FileBarChart, Webhook } from 'lucide-react'
import { get } from '../api'
import { Bars, Card, Explainer, StatCard } from '../components/ui'

type Stats = {
  scoring_calls_by_day: Record<string, number>
  scores_by_scope: Record<string, number>
  scores_by_order_type: Record<string, number>
  totals: { assessments: number; scored_results: number; usage_events: number; webhook_failures: number }
}

export default function Dashboard() {
  const [stats, setStats] = useState<Stats | null>(null)

  useEffect(() => {
    get<Stats>('/stats').then(setStats)
  }, [])

  if (!stats) return <p className="text-sm text-gray-400">Loading…</p>

  return (
    <div className="space-y-4">
      <Explainer title="what these numbers measure">
        <p>
          Every successful scoring call writes a <b>usage event</b> — the metering layer royalty statements are
          produced from. The charts here aggregate those events; assessments and results are counted from their own
          tables. Failed requests return errors synchronously to the caller and are not metered.
        </p>
      </Explainer>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Assessments" value={stats.totals.assessments} icon={<ClipboardList size={20} strokeWidth={1.75} />} />
        <StatCard label="Scored results" value={stats.totals.scored_results} icon={<FileBarChart size={20} strokeWidth={1.75} />} />
        <StatCard label="Usage events" value={stats.totals.usage_events} icon={<Activity size={20} strokeWidth={1.75} />} />
        <StatCard label="Webhook failures" value={stats.totals.webhook_failures} icon={<Webhook size={20} strokeWidth={1.75} />} />
      </div>

      <Card title="Scoring calls — last 14 days">
        <Bars data={stats.scoring_calls_by_day} />
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Scores by scope">
          {Object.keys(stats.scores_by_scope).length === 0 && <p className="text-sm text-gray-400">No scoring calls yet.</p>}
          <ul className="space-y-1 text-sm">
            {Object.entries(stats.scores_by_scope).map(([scope, n]) => (
              <li key={scope} className="flex justify-between">
                <code className="text-gray-700">{scope}</code>
                <span className="text-gray-500">{n}</span>
              </li>
            ))}
          </ul>
        </Card>
        <Card title="Scores by order type">
          <ul className="space-y-1 text-sm">
            {Object.entries(stats.scores_by_order_type).map(([type, n]) => (
              <li key={type} className="flex justify-between">
                <span className="text-gray-700">{type}</span>
                <span className="text-gray-500">{n}</span>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  )
}
