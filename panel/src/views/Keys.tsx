import { useEffect, useState } from 'react'
import { get, patch, post } from '../api'
import { useAuth } from '../auth'
import { DataTable, type Column } from '../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../components/ui'

type Key = {
  id: number
  name: string
  key_prefix: string
  rate_limit_per_minute: number
  default_access_code: string | null
  webhook_url: string | null
  active: boolean
  last_used_at: string | null
  usage_events: number
}

export default function Keys() {
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [keys, setKeys] = useState<Key[]>([])
  const [name, setName] = useState('')
  const [rate, setRate] = useState('60')
  const [freshToken, setFreshToken] = useState<string | null>(null)

  const load = () => get<{ keys: Key[] }>('/keys').then((r) => setKeys(r.keys))
  useEffect(() => {
    load()
  }, [])

  const create = async () => {
    const r = await post<{ token: string }>('/keys', { name, rate_limit_per_minute: Number(rate) })
    setFreshToken(r.token)
    setName('')
    load()
  }

  const rotate = async (id: number) => {
    const r = await post<{ token: string }>(`/keys/${id}/rotate`)
    setFreshToken(r.token)
    load()
  }

  const toggle = async (key: Key) => {
    await patch(`/keys/${key.id}`, { active: !key.active })
    load()
  }

  return (
    <div className="space-y-4">
      <Explainer title="API keys and what they gate">
        <p>
          Partner systems call the scoring API with <code>Authorization: Bearer &lt;token&gt;</code>. Only a SHA-256
          hash is stored — a token is visible once, at issue or rotation. Each key carries its own rate limit, an
          optional default access code (used when a scoring call doesn't name one), and an optional webhook that
          receives HMAC-signed <code>scored</code> events.
        </p>
      </Explainer>

      {freshToken && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
          <p className="font-medium text-amber-900">New token — copy it now, it will not be shown again:</p>
          <code className="mt-1 block break-all rounded bg-white p-2 text-amber-800">{freshToken}</code>
        </div>
      )}

      {isAdmin && (
        <Card title="Issue a key">
          <div className="flex flex-wrap items-end gap-3">
            <Field label="Client name">
              <input className={inputClass} value={name} onChange={(e) => setName(e.target.value)} />
            </Field>
            <Field label="Rate limit / min">
              <input className={inputClass} type="number" value={rate} onChange={(e) => setRate(e.target.value)} />
            </Field>
            <Button onClick={create} disabled={name === ''}>
              Issue
            </Button>
          </div>
        </Card>
      )}

      <Card title="Keys">
        <DataTable
          rows={keys}
          rowKey={(k) => k.id}
          empty="No API keys issued yet."
          columns={[
            { header: 'Name', primary: true, cell: (k) => <span className="font-medium text-gray-700">{k.name}</span> },
            { header: 'Token', cell: (k) => <code className="text-xs text-gray-500">{k.key_prefix}</code> },
            { header: 'Rate/min', cell: (k) => k.rate_limit_per_minute },
            { header: 'Default code', cell: (k) => <span className="text-xs">{k.default_access_code ?? '—'}</span> },
            { header: 'Webhook', cell: (k) => <span className="text-xs">{k.webhook_url ? 'yes' : '—'}</span> },
            { header: 'Usage', cell: (k) => k.usage_events },
            { header: 'Last used', cell: (k) => <span className="text-xs">{k.last_used_at?.slice(0, 10) ?? 'never'}</span> },
            { header: 'Status', cell: (k) => (k.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>) },
          ] satisfies Column<Key>[]}
          actions={isAdmin ? (k) => (
            <span className="flex gap-2">
              <Button kind="secondary" onClick={() => rotate(k.id)}>Rotate</Button>
              <Button kind={k.active ? 'danger' : 'secondary'} onClick={() => toggle(k)}>
                {k.active ? 'Revoke' : 'Restore'}
              </Button>
            </span>
          ) : undefined}
        />
      </Card>
    </div>
  )
}
