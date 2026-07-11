import { useEffect, useState } from 'react'
import { get } from '../api'
import { Bars, Card, Explainer } from '../components/ui'

type Stats = {
  scoring_calls_by_day: Record<string, number>
  scores_by_scope: Record<string, number>
  scores_by_code_type: Record<string, number>
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

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {Object.entries({
          Assessments: stats.totals.assessments,
          'Scored results': stats.totals.scored_results,
          'Usage events': stats.totals.usage_events,
          'Webhook failures': stats.totals.webhook_failures,
        }).map(([label, value]) => (
          <Card key={label}>
            <div className="text-2xl font-semibold text-gray-800">{value}</div>
            <div className="text-xs text-gray-500">{label}</div>
          </Card>
        ))}
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
        <Card title="Scores by code type">
          <ul className="space-y-1 text-sm">
            {Object.entries(stats.scores_by_code_type).map(([type, n]) => (
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
