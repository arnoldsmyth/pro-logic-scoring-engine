import { useEffect, useState } from 'react'
import { get, post } from '../api'
import { useAuth } from '../auth'
import { DataTable, type Column } from '../components/DataTable'
import { Badge, Button, Card, Explainer } from '../components/ui'

type NormSet = {
  id: number
  slug: string
  status: 'candidate' | 'active' | 'retired'
  language: string | null
  gender: string | null
  provisional: boolean
  description: string | null
  entries: number
  provenance: Record<string, unknown>
  impact: { pct_changed?: number; baseline?: string; assessments_compared?: number; changes_by_dimension?: Record<string, number> } | null
  activated_at: string | null
}

type Population = {
  language: string
  gender: string | null
  samples_per_scale: Record<string, number>
  threshold: number
  eligible: boolean
  drift_vs_active: Record<string, number> | null
  drift_baseline: string | null
}

const statusTone = { candidate: 'amber', active: 'green', retired: 'gray' } as const

export default function Norms() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [sets, setSets] = useState<NormSet[]>([])
  const [populations, setPopulations] = useState<Population[]>([])
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = () =>
    get<{ sets: NormSet[]; populations: Population[] }>('/norms').then((r) => {
      setSets(r.sets)
      setPopulations(r.populations)
    })
  useEffect(() => {
    load()
  }, [])

  const act = async (slug: string, action: 'impact' | 'promote' | 'retire') => {
    setBusy(slug)
    setError(null)
    try {
      await post(`/norms/${slug}/${action}`)
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Action failed')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="space-y-4">
      <Explainer title="norm sets and the promotion pipeline">
        <p>
          A norm set is a versioned raw→normed conversion table — the lookup that turns a respondent's raw scale
          scores into population-relative values. Norms are <b>data, never code</b>: every scored result records the
          set it used, so nothing ever silently rescores. The legacy male/female tables were migrated verbatim; new
          sets are derived from the anonymized response distributions accumulating below. The pipeline:{' '}
          <b>samples reach ≥400 per scale → build candidate → generate the side-by-side impact report (% of results
          whose top-3 codes would change) → a human reviews and promotes</b>. Promotion is never automatic.
        </p>
      </Explainer>

      {error && <p className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      <Card title="Norm sets">
        <DataTable
          rows={sets}
          rowKey={(n) => n.id}
          empty="No norm sets registered."
          columns={[
            {
              header: 'Slug',
              primary: true,
              cell: (n) => (
                <>
                  <code className="font-medium text-gray-700">{n.slug}</code>
                  {n.provisional && <span className="ml-2"><Badge tone="amber">provisional</Badge></span>}
                </>
              ),
            },
            { header: 'Status', cell: (n) => <Badge tone={statusTone[n.status]}>{n.status}</Badge> },
            { header: 'Population', cell: (n) => `${n.language ?? 'all languages'} / ${n.gender ?? 'pooled'}` },
            { header: 'Entries', cell: (n) => n.entries },
            {
              header: 'Impact report',
              cell: (n) => (
                <span className="text-xs">
                  {n.impact
                    ? `${n.impact.pct_changed}% top-3 change vs ${n.impact.baseline} (n=${n.impact.assessments_compared})`
                    : '—'}
                </span>
              ),
            },
            { header: 'Activated', cell: (n) => <span className="text-xs">{n.activated_at?.slice(0, 10) ?? '—'}</span> },
          ] satisfies Column<NormSet>[]}
          actions={(n) => (
            <>
              {isAdmin && n.status === 'candidate' && (
                <span className="flex gap-2">
                  <Button kind="secondary" disabled={busy === n.slug} onClick={() => act(n.slug, 'impact')}>
                    {busy === n.slug ? 'Working…' : 'Run impact'}
                  </Button>
                  <Button disabled={busy === n.slug || n.impact === null} onClick={() => act(n.slug, 'promote')}>
                    Promote
                  </Button>
                </span>
              )}
              {isAdmin && n.status === 'active' && !n.slug.endsWith('-legacy') && (
                <Button kind="danger" disabled={busy === n.slug} onClick={() => act(n.slug, 'retire')}>
                  Retire
                </Button>
              )}
            </>
          )}
        />
      </Card>

      <Card title="Sample accumulation by population">
        {populations.length === 0 && (
          <p className="text-sm text-gray-400">
            No samples yet — every gendered scoring call adds anonymized raw-score observations here.
          </p>
        )}
        <div className="space-y-4">
          {populations.map((p) => (
            <div key={`${p.language}-${p.gender}`}>
              <div className="mb-1 flex items-center gap-2 text-sm font-medium text-gray-700">
                {p.language} / {p.gender ?? 'pooled'}
                {p.eligible ? <Badge tone="green">threshold met</Badge> : <Badge tone="gray">accumulating</Badge>}
                {p.drift_baseline && <span className="text-xs font-normal text-gray-400">drift vs {p.drift_baseline}</span>}
              </div>
              <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                {Object.entries(p.samples_per_scale).map(([scale, n]) => (
                  <div key={scale} className="rounded border border-gray-100 p-2 text-xs">
                    <div className="flex justify-between text-gray-500">
                      <span>scale {scale}</span>
                      <span>
                        {n}/{p.threshold}
                        {p.drift_vs_active?.[scale] !== undefined && ` · drift ${p.drift_vs_active[scale]}`}
                      </span>
                    </div>
                    <div className="mt-1 h-1.5 rounded bg-gray-100">
                      <div
                        className={`h-1.5 rounded ${n >= p.threshold ? 'bg-emerald-500' : 'bg-sky-400'}`}
                        style={{ width: `${Math.min(100, (n / p.threshold) * 100)}%` }}
                      />
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}
