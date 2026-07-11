import { useEffect, useState } from 'react'
import { get } from '../api'
import { Card, Explainer, Field, inputClass } from '../components/ui'

const TOOLS = [
  'reflections', 'personalmotivators', 'areamissions', 'abilitiesfilter',
  'personalstyle', 'personalexpectations', 'person', 'role', 'organization',
]

type Questions = { tool: string; language: string; questions: { q: number; text: string }[] }
type Summary = { content_rows_by_language: Record<string, Record<string, number>> }

export default function Content() {
  const [tool, setTool] = useState('areamissions')
  const [language, setLanguage] = useState('en')
  const [questions, setQuestions] = useState<Questions | null>(null)
  const [summary, setSummary] = useState<Summary | null>(null)

  useEffect(() => {
    get<Summary>('/content/translations-summary').then(setSummary)
  }, [])

  useEffect(() => {
    get<Questions>(`/content/questions?tool=${tool}&language=${language}`).then(setQuestions)
  }, [tool, language])

  return (
    <div className="space-y-4">
      <Explainer title="products are data, not code">
        <p>
          Everything a respondent sees — questions, result prose, archetype text — lives in content tables per
          language, not in code. Adding a language is a translation project (~8.3k rows), not an engineering one.
          This browser is read-only in v1.
        </p>
      </Explainer>

      {summary && (
        <Card title="Content rows by language">
          <div className="flex gap-8 text-sm">
            {Object.entries(summary.content_rows_by_language).map(([lang, tables]) => (
              <div key={lang}>
                <div className="font-semibold text-gray-700">{lang}</div>
                {Object.entries(tables).map(([table, n]) => (
                  <div key={table} className="flex justify-between gap-4 text-xs text-gray-500">
                    <span>{table}</span>
                    <span>{n}</span>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card title="Question text">
        <div className="mb-4 flex gap-3">
          <Field label="Tool">
            <select className={inputClass} value={tool} onChange={(e) => setTool(e.target.value)}>
              {TOOLS.map((t) => (
                <option key={t} value={t}>{t}</option>
              ))}
            </select>
          </Field>
          <Field label="Language">
            <select className={inputClass} value={language} onChange={(e) => setLanguage(e.target.value)}>
              <option value="en">en</option>
              <option value="fr">fr</option>
              <option value="pt">pt</option>
            </select>
          </Field>
        </div>
        <ol className="max-h-96 space-y-1 overflow-y-auto text-sm">
          {questions?.questions.map((q) => (
            <li key={q.q} className="flex gap-3 border-b border-gray-50 py-1">
              <span className="w-8 shrink-0 text-right text-xs text-gray-400">{q.q}.</span>
              <span className="text-gray-700">{q.text}</span>
            </li>
          ))}
        </ol>
      </Card>
    </div>
  )
}
