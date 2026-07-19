import { useState, type ReactNode } from 'react'
import { ChevronDown, ChevronRight, Info } from 'lucide-react'

/**
 * The docs/08 "under the hood" requirement: every view explains the
 * mechanics behind what it shows. Collapsible so it informs without
 * shouting.
 */
export function Explainer({ title, children }: { title: string; children: ReactNode }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="mb-4 rounded-xl border border-sky-200 bg-sky-50/70 text-sm">
      <button
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="flex w-full items-center gap-2 px-4 py-2.5 text-left font-medium text-sky-900"
      >
        {open ? <ChevronDown size={16} className="shrink-0 text-sky-500" /> : <ChevronRight size={16} className="shrink-0 text-sky-500" />}
        <Info size={15} className="shrink-0 text-sky-500" />
        How this works: {title}
      </button>
      {open && <div className="space-y-2 px-4 pb-3 text-sky-950/80">{children}</div>}
    </div>
  )
}

export function Card({ title, children, actions }: { title?: string; children: ReactNode; actions?: ReactNode }) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white shadow-sm">
      {(title || actions) && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 sm:px-5">
          <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
          {actions}
        </div>
      )}
      <div className="p-4 sm:p-5">{children}</div>
    </div>
  )
}

export function StatCard({ label, value, icon }: { label: string; value: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
      {icon && <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-600">{icon}</div>}
      <div className="min-w-0">
        <div className="text-2xl font-semibold tracking-tight text-gray-900">{value}</div>
        <div className="truncate text-xs text-gray-500">{label}</div>
      </div>
    </div>
  )
}

export function Badge({ tone, children }: { tone: 'green' | 'amber' | 'red' | 'gray' | 'blue'; children: ReactNode }) {
  const tones = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    amber: 'bg-amber-50 text-amber-700 ring-amber-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
    gray: 'bg-gray-100 text-gray-600 ring-gray-200',
    blue: 'bg-sky-50 text-sky-700 ring-sky-200',
  }
  return <span className={`inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ${tones[tone]}`}>{children}</span>
}

export function Bars({ data, height = 96 }: { data: Record<string, number>; height?: number }) {
  const entries = Object.entries(data)
  const max = Math.max(1, ...entries.map(([, v]) => v))
  return (
    <div className="flex items-end gap-1" style={{ height }}>
      {entries.map(([label, value], i) => (
        <div key={label} className="group relative min-w-0 flex-1">
          <div
            className="rounded-t-md bg-sky-500/80 transition-colors group-hover:bg-sky-600"
            style={{ height: Math.max(2, (value / max) * (height - 20)) }}
          />
          <div className="absolute -top-5 left-1/2 hidden -translate-x-1/2 text-xs text-gray-600 group-hover:block">
            {value}
          </div>
          <div className={`mt-1 whitespace-nowrap text-[10px] text-gray-400 ${i % 2 ? 'hidden sm:block' : ''}`}>{label.slice(5)}</div>
        </div>
      ))}
    </div>
  )
}

export function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-gray-600">{label}</span>
      {children}
    </label>
  )
}

export const inputClass =
  'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm transition-shadow focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20'

export function Button({
  children,
  onClick,
  kind = 'primary',
  disabled,
  type,
}: {
  children: ReactNode
  onClick?: () => void
  kind?: 'primary' | 'secondary' | 'danger'
  disabled?: boolean
  type?: 'submit' | 'button'
}) {
  const kinds = {
    primary: 'bg-sky-600 text-white hover:bg-sky-700 disabled:bg-gray-300',
    secondary: 'border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 disabled:text-gray-300',
    danger: 'bg-red-600 text-white hover:bg-red-700 disabled:bg-gray-300',
  }
  return (
    <button
      type={type ?? 'button'}
      onClick={onClick}
      disabled={disabled}
      className={`inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/40 ${kinds[kind]}`}
    >
      {children}
    </button>
  )
}
