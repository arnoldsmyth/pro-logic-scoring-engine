import { useEffect, useState } from 'react'
import { get } from '../api'
import { DataTable } from '../components/DataTable'
import { Badge, Card, Explainer } from '../components/ui'

type PipelineData = {
  product: string
  stages: Record<string, { rules: number; operations: Record<string, number> }>
  dependency_matrix: Record<string, { required_tools: string[]; uses_gender_split_norms: boolean }>
  tool_question_counts: Record<string, number>
}

const STAGE_BLURB: Record<string, string> = {
  Tool: 'Recode and aggregate raw answers into per-tool scale scores (weights, sums, PZSD norm conversion).',
  Package: 'Combine tool scales into the M/C/S dimension scores across the nine career value areas.',
  Profile: 'Rank dimensions, compute the P composite, derive top-3 selections with tie-breaks and boosts.',
  Insight: 'Resolve ranked outputs into content keys — key codes and text references, no math.',
}

export default function Pipeline() {
  const [data, setData] = useState<PipelineData | null>(null)

  useEffect(() => {
    get<PipelineData>('/pipeline').then(setData)
  }, [])

  if (!data) return <p className="text-sm text-gray-400">Loading…</p>

  return (
    <div className="space-y-4">
      <Explainer title="why this page exists">
        <p>
          The legacy engine was a black box for a decade. This page is rendered from the <b>live rule data</b> the
          interpreter actually executes — rule counts and operation frequencies are queried from the same tables the
          scoring run reads, never hand-maintained. If the config changes, this page changes.
        </p>
      </Explainer>

      <Card title={`The 4-stage cascade — product ${data.product}`}>
        <div className="grid gap-4 lg:grid-cols-4">
          {Object.entries(data.stages).map(([stage, s], i) => (
            <div key={stage} className="rounded-lg border border-gray-200">
              <div className="border-b border-gray-100 bg-gray-50 px-3 py-2">
                <span className="text-xs text-gray-400">stage {i + 1}</span>
                <div className="font-semibold text-gray-800">{stage}</div>
                <div className="text-xs text-gray-500">{s.rules} rules</div>
              </div>
              <p className="px-3 py-2 text-xs text-gray-500">{STAGE_BLURB[stage]}</p>
              <div className="px-3 pb-3">
                {Object.entries(s.operations).slice(0, 8).map(([op, n]) => (
                  <div key={op} className="flex justify-between py-0.5 text-xs">
                    <code className="text-gray-600">{op.replace('sp_', '')}</code>
                    <span className="text-gray-400">{n}</span>
                  </div>
                ))}
                {Object.keys(s.operations).length > 8 && (
                  <div className="pt-1 text-xs text-gray-400">…{Object.keys(s.operations).length - 8} more ops</div>
                )}
              </div>
            </div>
          ))}
        </div>
        <p className="mt-3 text-xs text-gray-400">
          Each stage runs its rule list in legacy cursor order; each rule names an operation plus parameters and
          sources; a stage's outputs are the next stage's inputs.
        </p>
      </Card>

      <Card title="Scope → required tools (dependency matrix)">
        <DataTable
          rows={Object.entries(data.dependency_matrix)}
          rowKey={([scope]) => scope}
          columns={[
            { header: 'Scope', primary: true, cell: ([scope]) => <code className="font-medium text-gray-700">{scope}</code> },
            {
              header: 'Required tools (questions)',
              cell: ([, spec]) => (
                <span className="text-xs">
                  {spec.required_tools.map((t) => `${t} (${data.tool_question_counts[t] ?? '?'})`).join(', ')}
                </span>
              ),
            },
            {
              header: 'Gender-split norms',
              cell: ([, spec]) =>
                spec.uses_gender_split_norms ? <Badge tone="amber">yes — S/P dimensions</Badge> : <Badge tone="gray">no</Badge>,
            },
          ]}
        />
        <p className="mt-3 text-xs text-gray-400">
          S is anchored on the person tool, so full mcs needs all six self tools; P is computed from M+C+S, not from
          the person tool alone; role and org stand alone; reflections are echoed verbatim, never scored.
        </p>
      </Card>
    </div>
  )
}
