import type { ReactNode } from 'react'

export type Column<T> = {
  header: string
  cell: (row: T) => ReactNode
  /** Card title on mobile (first primary column wins). */
  primary?: boolean
  /** Skip on the mobile card (e.g. redundant with the title). */
  hideOnCard?: boolean
}

/**
 * Responsive table (prolog-549): a classic table on sm+ screens, stacked
 * label/value cards below that — no horizontal scrolling on phones. Row
 * actions render in the last table cell and in the card footer.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  actions,
  empty,
}: {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  actions?: (row: T) => ReactNode
  empty?: string
}) {
  if (rows.length === 0) {
    return <p className="py-2 text-sm text-gray-400">{empty ?? 'Nothing here yet.'}</p>
  }

  const primary = columns.find((c) => c.primary) ?? columns[0]
  const cardColumns = columns.filter((c) => c !== primary && !c.hideOnCard)

  return (
    <>
      {/* Desktop table; wide tables pan inside the card instead of spilling out */}
      <div className="hidden overflow-x-auto sm:block">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-400">
              {columns.map((c, i) => (
                <th key={i} className="whitespace-nowrap px-3 py-2 font-medium">
                  {c.header}
                </th>
              ))}
              {actions && <th className="px-3 py-2" />}
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100">
            {rows.map((row) => (
              <tr key={rowKey(row)}>
                {columns.map((c, i) => (
                  <td key={i} className="px-3 py-2 align-top text-gray-600">
                    {c.cell(row)}
                  </td>
                ))}
                {actions && <td className="px-3 py-2 align-top">{actions(row)}</td>}
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Mobile stacked cards */}
      <ul className="space-y-3 sm:hidden">
        {rows.map((row) => (
          <li key={rowKey(row)} className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div className="mb-2 text-sm font-semibold text-gray-800">{primary.cell(row)}</div>
            <dl className="space-y-1.5">
              {cardColumns.map((c, i) => (
                <div key={i} className="flex flex-wrap items-baseline justify-between gap-3 text-sm">
                  <dt className="shrink-0 text-xs uppercase tracking-wide text-gray-400">{c.header}</dt>
                  <dd className="min-w-0 ml-auto break-words text-right text-gray-600">{c.cell(row)}</dd>
                </div>
              ))}
            </dl>
            {actions && <div className="mt-3 flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-3">{actions(row)}</div>}
          </li>
        ))}
      </ul>
    </>
  )
}
