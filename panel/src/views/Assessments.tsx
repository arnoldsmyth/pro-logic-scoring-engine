import { useEffect, useRef, useState } from 'react'
import { get } from '../api'
import { DataTable, type Column } from '../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../components/ui'

type Row = {
  public_id: string
  external_id: string | null
  name: string
  email: string
  language: string
  gender: string | null
  api_key: string | null
  tools_submitted: number
  times_scored: number
  created_at: string
}

type Detail = {
  public_id: string
  name: string
  email: string
  tools: { tool: string; answers: number; submitted_at: string }[]
  scopes_ready: Record<string, boolean>
  results: {
    id: number
    scopes: string[]
    norm_set: string
    access_code: string | null
    has_audit: boolean
    scored_at: string
    results: Record<string, unknown>
  }[]
}

type Audit = {
  norm_set: string
  stages: Record<string, { rules_fired: number; rules: { rule: number; proc: string }[]; scale_values: { scaleKey: number | null; response: number }[] }>
  content_keys_resolved: unknown[]
}

type TimelineTake = {
  public_id: string
  created_at: string
  is_current: boolean
  results: { scopes: string[]; norm_set: string; scored_at: string; results: Record<string, unknown> }[]
}
type Timeline = {
  identity: { matched_by: string; value: string }
  takes: TimelineTake[]
}

// Rank deltas between two takes' latest results, for the scopes both share:
// "societal_change m 3→1". Payloads are {area: rank} for single-dimension
// scopes and {area: {m,c,s}} for mcs; insights/reflections aren't ranks.
function takeDeltas(prev: TimelineTake, next: TimelineTake): string[] {
  const prevRes = prev.results.at(-1)?.results ?? {}
  const nextRes = next.results.at(-1)?.results ?? {}
  const deltas: string[] = []
  for (const scope of Object.keys(nextRes)) {
    if (scope === 'insights' || scope === 'reflections') continue
    const a = prevRes[scope] as Record<string, unknown> | undefined
    const b = nextRes[scope] as Record<string, unknown> | undefined
    if (!a || !b) continue
    for (const area of Object.keys(b)) {
      const av = a[area]
      const bv = b[area]
      if (typeof bv === 'object' && bv !== null && typeof av === 'object' && av !== null) {
        for (const dim of Object.keys(bv as Record<string, unknown>)) {
          const x = (av as Record<string, unknown>)[dim]
          const y = (bv as Record<string, unknown>)[dim]
          if (x !== undefined && x !== y) deltas.push(`${area} ${dim} ${x}→${y}`)
        }
      } else if (av !== undefined && av !== bv) {
        deltas.push(`${area} ${av}→${bv}`)
      }
    }
  }
  return deltas
}

export default function Assessments() {
  const [q, setQ] = useState('')
  const [rows, setRows] = useState<Row[]>([])
  const [detail, setDetail] = useState<Detail | null>(null)
  const [audit, setAudit] = useState<Audit | null>(null)
  const [timeline, setTimeline] = useState<Timeline | null>(null)
  const detailRef = useRef<HTMLDivElement>(null)
  const auditRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (detail) detailRef.current?.scrollIntoView({ block: 'start' })
  }, [detail?.public_id])

  useEffect(() => {
    if (audit) auditRef.current?.scrollIntoView({ block: 'start' })
  }, [audit])

  const search = () => {
    get<{ assessments: Row[] }>(`/assessments?q=${encodeURIComponent(q)}`).then((r) => setRows(r.assessments))
  }
  useEffect(search, [])

  const open = async (id: string) => {
    setAudit(null)
    setTimeline(null)
    setDetail(await get<Detail>(`/assessments/${id}`))
    get<Timeline>(`/assessments/${id}/person-timeline`).then(setTimeline)
  }

  const loadAudit = async (resultId: number) => {
    if (!detail) return
    setAudit(await get<Audit>(`/assessments/${detail.public_id}/audit/${resultId}`))
  }

  return (
    <div className="space-y-4">
      <Explainer title="assessment lifecycle">
        <p>
          An assessment is created with registration info, receives tools incrementally (each validated on write),
          and can be scored any number of times — every scoring call records which scopes, norm set, and access code
          it used, forever. The audit trace (when captured with <code>audit:true</code>) shows each rule the
          interpreter fired, stage by stage, with the intermediate scale values — the full walkthrough of how the
          inputs became the result.
        </p>
      </Explainer>

      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Search external id / email / name">
            <input className={`${inputClass} sm:w-72`} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && search()} />
          </Field>
          <Button onClick={search}>Search</Button>
        </div>
      </Card>

      <Card title={`Assessments (${rows.length})`}>
        <DataTable
          rows={rows}
          rowKey={(r) => r.public_id}
          empty="No assessments match."
          columns={[
            { header: 'Name', primary: true, cell: (r) => <span className="font-medium text-gray-700">{r.name}</span> },
            { header: 'External id', cell: (r) => <span className="text-xs">{r.external_id ?? '—'}</span> },
            { header: 'Email', cell: (r) => r.email },
            { header: 'Lang', cell: (r) => r.language },
            { header: 'Key', cell: (r) => <span className="text-xs">{r.api_key}</span> },
            { header: 'Tools', cell: (r) => `${r.tools_submitted}/9` },
            { header: 'Scored', cell: (r) => `${r.times_scored}×` },
            { header: 'Created', cell: (r) => <span className="text-xs">{r.created_at.slice(0, 10)}</span> },
          ] satisfies Column<Row>[]}
          actions={(r) => <Button kind="secondary" onClick={() => open(r.public_id)}>Open</Button>}
        />
      </Card>

      {detail && (
        <div ref={detailRef} className="scroll-mt-20 lg:scroll-mt-4">
        <Card title={`${detail.name} — ${detail.public_id}`}>
          <div className="grid gap-4 lg:grid-cols-2">
            <div>
              <h3 className="mb-2 text-xs font-semibold uppercase text-gray-400">Tools received</h3>
              <ul className="space-y-1 text-sm">
                {detail.tools.map((t) => (
                  <li key={t.tool} className="flex justify-between">
                    <code className="text-gray-700">{t.tool}</code>
                    <span className="text-xs text-gray-400">{t.answers} answers · {t.submitted_at.slice(0, 10)}</span>
                  </li>
                ))}
              </ul>
              <h3 className="mb-2 mt-4 text-xs font-semibold uppercase text-gray-400">Scope readiness</h3>
              <div className="flex flex-wrap gap-1">
                {Object.entries(detail.scopes_ready).map(([scope, ready]) => (
                  <Badge key={scope} tone={ready ? 'green' : 'gray'}>{scope}</Badge>
                ))}
              </div>
            </div>
            <div>
              <h3 className="mb-2 text-xs font-semibold uppercase text-gray-400">Results</h3>
              <ul className="space-y-2 text-sm">
                {detail.results.map((res) => (
                  <li key={res.id} className="rounded border border-gray-100 p-2">
                    <div className="flex items-center justify-between">
                      <span className="text-gray-700">{res.scopes.join(', ')}</span>
                      <span className="text-xs text-gray-400">{res.scored_at.slice(0, 16).replace('T', ' ')}</span>
                    </div>
                    <div className="mt-1 flex items-center gap-2 text-xs text-gray-500">
                      <Badge tone="blue">norms: {res.norm_set}</Badge>
                      {res.has_audit ? (
                        <Button kind="secondary" onClick={() => loadAudit(res.id)}>View audit trace</Button>
                      ) : (
                        <span>no audit captured</span>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </Card>
        </div>
      )}

      {timeline && timeline.takes.length > 1 && (
        <Card title={`Person timeline — matched by ${timeline.identity.matched_by} (${timeline.identity.value})`}>
          <p className="mb-3 text-sm text-gray-500">
            {timeline.takes.length} takes linked to this person. Each take is an independent submission — deltas
            below compare consecutive takes' latest results where scopes overlap.
          </p>
          <ol className="space-y-3">
            {timeline.takes.map((take, i) => {
              const deltas = i > 0 ? takeDeltas(timeline.takes[i - 1], take) : []
              return (
                <li key={take.public_id} className={`rounded-lg border p-3 text-sm ${take.is_current ? 'border-sky-300 bg-sky-50/50' : 'border-gray-100'}`}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="font-medium text-gray-700">
                      Take {i + 1}
                      {take.is_current && <span className="ml-2 text-xs font-normal text-sky-600">(viewing)</span>}
                    </span>
                    <span className="text-xs text-gray-400">
                      {take.created_at.slice(0, 10)} · {take.results.length} result{take.results.length === 1 ? '' : 's'}
                      {take.results.at(-1) && ` · norms ${take.results.at(-1)!.norm_set}`}
                    </span>
                  </div>
                  {i > 0 && (
                    <div className="mt-2 text-xs">
                      {take.results.length === 0 ? (
                        <span className="text-gray-400">not scored yet</span>
                      ) : deltas.length === 0 ? (
                        <Badge tone="gray">no rank changes vs previous take</Badge>
                      ) : (
                        <div className="flex flex-wrap gap-1">
                          {deltas.slice(0, 24).map((d) => (
                            <Badge key={d} tone="amber">{d}</Badge>
                          ))}
                          {deltas.length > 24 && <span className="text-gray-400">…and {deltas.length - 24} more</span>}
                        </div>
                      )}
                    </div>
                  )}
                </li>
              )
            })}
          </ol>
        </Card>
      )}

      {audit && (
        <div ref={auditRef} className="scroll-mt-20 lg:scroll-mt-4">
        <Card title={`Audit trace — norm set ${audit.norm_set}`}>
          <p className="mb-3 text-sm text-gray-500">
            The four-stage cascade as it actually ran. Each stage lists the rules fired in cursor order and the scale
            values it produced for the next stage.
          </p>
          <div className="grid gap-4 lg:grid-cols-4">
            {Object.entries(audit.stages).map(([stage, data]) => (
              <div key={stage} className="rounded border border-gray-200">
                <div className="border-b border-gray-100 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-700">
                  {stage} <span className="font-normal text-gray-400">— {data.rules_fired} rules</span>
                </div>
                <div className="max-h-64 overflow-y-auto p-2 text-xs">
                  {data.rules.slice(0, 100).map((r, i) => (
                    <div key={i} className="flex justify-between py-0.5 text-gray-500">
                      <code>#{r.rule}</code>
                      <span>{r.proc.replace('sp_', '')}</span>
                    </div>
                  ))}
                  {data.rules.length > 100 && <p className="pt-1 text-gray-400">…and {data.rules.length - 100} more</p>}
                </div>
                <div className="border-t border-gray-100 px-3 py-1 text-xs text-gray-400">
                  {data.scale_values.length} scale values out
                </div>
              </div>
            ))}
          </div>
          <p className="mt-3 text-xs text-gray-400">{audit.content_keys_resolved.length} content keys resolved by the insight stage.</p>
        </Card>
        </div>
      )}
    </div>
  )
}
