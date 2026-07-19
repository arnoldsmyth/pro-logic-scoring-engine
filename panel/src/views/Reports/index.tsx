import { useState, type ComponentType } from 'react'
import AssessmentVolume from './AssessmentVolume'
import PayoutAging from './PayoutAging'
import RoyaltyStatement from './RoyaltyStatement'

/**
 * Reports shell. To add a report, drop a new entry in REPORTS: give it a
 * key + label and either a `component` (live) or `soon: true` (placeholder
 * tab). Everything else — the sub-nav and the body switch — follows.
 */
type ReportTab = {
  key: string
  label: string
  component?: ComponentType
  soon?: boolean
}

const REPORTS: ReportTab[] = [
  { key: 'royalties', label: 'Royalty statement', component: RoyaltyStatement },
  { key: 'aging', label: 'Payout aging', component: PayoutAging },
  { key: 'volume', label: 'Assessment volume', component: AssessmentVolume },
  { key: 'norms', label: 'Norm health', soon: true },
]

export default function Reports() {
  const [active, setActive] = useState(REPORTS[0].key)
  const tab = REPORTS.find((r) => r.key === active) ?? REPORTS[0]
  const Body = tab.component

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-1 border-b border-gray-200">
        {REPORTS.map((r) => (
          <button
            key={r.key}
            onClick={() => setActive(r.key)}
            className={`-mb-px border-b-2 px-3.5 py-2 text-sm font-medium transition-colors ${
              r.key === active
                ? 'border-sky-600 text-sky-700'
                : 'border-transparent text-gray-500 hover:text-gray-800'
            }`}
          >
            {r.label}
            {r.soon && <span className="ml-1.5 text-[11px] font-normal text-gray-400">soon</span>}
          </button>
        ))}
      </div>

      {Body ? (
        <Body />
      ) : (
        <div className="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-400">
          <p className="font-medium text-gray-500">{tab.label} is coming soon.</p>
          <p className="mt-1">This report is planned but not built yet.</p>
        </div>
      )}
    </div>
  )
}
