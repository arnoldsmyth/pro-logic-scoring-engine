import { Field, inputClass } from './ui'

export type DateRange = { from: string; to: string }

/**
 * Dumb, presentational from/to date picker built on native
 * <input type="date">. Controlled via value + onChange so any report
 * (royalties, assessment volume, norm health) can reuse it.
 */
export function DateRangePicker({
  value,
  onChange,
}: {
  value: DateRange
  onChange: (next: DateRange) => void
}) {
  return (
    <>
      <Field label="From">
        <input
          type="date"
          className={inputClass}
          value={value.from}
          max={value.to || undefined}
          onChange={(e) => onChange({ ...value, from: e.target.value })}
        />
      </Field>
      <Field label="To">
        <input
          type="date"
          className={inputClass}
          value={value.to}
          min={value.from || undefined}
          onChange={(e) => onChange({ ...value, to: e.target.value })}
        />
      </Field>
    </>
  )
}
