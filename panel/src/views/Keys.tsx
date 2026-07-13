import { useEffect, useState } from 'react'
import { get, patch, post } from '../api'
import { useAuth } from '../auth'
import { DataTable, type Column } from '../components/DataTable'
import { Badge, Button, Card, Explainer, Field, inputClass } from '../components/ui'

type Client = {
  id: number
  name: string
  billing_email: string | null
  notes: string | null
  active: boolean
  api_keys: number
  access_codes: number
}

type Key = {
  id: number
  name: string
  client: string | null
  client_id: number | null
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
  const [clients, setClients] = useState<Client[]>([])
  const [keys, setKeys] = useState<Key[]>([])
  const [clientForm, setClientForm] = useState({ name: '', billing_email: '' })
  const [keyForm, setKeyForm] = useState({ name: '', client_id: '', rate: '60' })
  const [freshToken, setFreshToken] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = () => {
    get<{ clients: Client[] }>('/clients').then((r) => setClients(r.clients))
    get<{ keys: Key[] }>('/keys').then((r) => setKeys(r.keys))
  }
  useEffect(load, [])

  const run = async (fn: () => Promise<unknown>) => {
    setError(null)
    try {
      await fn()
      load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Action failed')
    }
  }

  const createClient = () =>
    run(async () => {
      await post('/clients', { name: clientForm.name, billing_email: clientForm.billing_email || null })
      setClientForm({ name: '', billing_email: '' })
    })

  const createKey = () =>
    run(async () => {
      const r = await post<{ token: string }>('/keys', {
        name: keyForm.name,
        client_id: keyForm.client_id ? Number(keyForm.client_id) : null,
        rate_limit_per_minute: Number(keyForm.rate),
      })
      setFreshToken(r.token)
      setKeyForm({ name: '', client_id: '', rate: '60' })
    })

  const rotate = (id: number) =>
    run(async () => {
      const r = await post<{ token: string }>(`/keys/${id}/rotate`)
      setFreshToken(r.token)
    })

  return (
    <div className="space-y-4">
      <Explainer title="clients, keys, and what they gate">
        <p>
          A <b>client</b> is who pays us — the normalized record keys and access codes are issued against (no more
          free-typed names fragmenting statements and, later, Stripe billing). A client can hold several API keys
          (e.g. test vs live). Partner systems call the scoring API with <code>Authorization: Bearer &lt;token&gt;</code>;
          only a SHA-256 hash is stored — a token is visible once, at issue or rotation. Clients are deactivated,
          never deleted — their keys, codes, and charges reference them forever.
        </p>
      </Explainer>

      {error && <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}

      {freshToken && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm">
          <p className="font-medium text-amber-900">New token — copy it now, it will not be shown again:</p>
          <code className="mt-1 block break-all rounded bg-white p-2 text-amber-800">{freshToken}</code>
        </div>
      )}

      <Card title="Clients">
        {isAdmin && (
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <Field label="Client name">
              <input className={`${inputClass} sm:w-56`} value={clientForm.name} onChange={(e) => setClientForm({ ...clientForm, name: e.target.value })} />
            </Field>
            <Field label="Billing email (optional)">
              <input className={`${inputClass} sm:w-56`} type="email" value={clientForm.billing_email} onChange={(e) => setClientForm({ ...clientForm, billing_email: e.target.value })} />
            </Field>
            <Button onClick={createClient} disabled={clientForm.name.trim() === ''}>Add client</Button>
          </div>
        )}
        <DataTable
          rows={clients}
          rowKey={(c) => c.id}
          empty="No clients yet — add one to issue keys and codes against."
          columns={[
            { header: 'Name', primary: true, cell: (c) => <span className="font-medium text-gray-700">{c.name}</span> },
            { header: 'Billing email', cell: (c) => <span className="text-xs">{c.billing_email ?? '—'}</span> },
            { header: 'Keys', cell: (c) => c.api_keys },
            { header: 'Codes', cell: (c) => c.access_codes },
            { header: 'Status', cell: (c) => (c.active ? <Badge tone="green">active</Badge> : <Badge tone="red">inactive</Badge>) },
          ] satisfies Column<Client>[]}
          actions={isAdmin ? (c) => (
            <Button kind={c.active ? 'danger' : 'secondary'} onClick={() => run(() => patch(`/clients/${c.id}`, { active: !c.active }))}>
              {c.active ? 'Deactivate' : 'Reactivate'}
            </Button>
          ) : undefined}
        />
      </Card>

      <Card title="API keys">
        {isAdmin && (
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <Field label="Key name (e.g. acme-live)">
              <input className={`${inputClass} sm:w-48`} value={keyForm.name} onChange={(e) => setKeyForm({ ...keyForm, name: e.target.value })} />
            </Field>
            <Field label="Client">
              <select className={inputClass} value={keyForm.client_id} onChange={(e) => setKeyForm({ ...keyForm, client_id: e.target.value })}>
                <option value="">— none —</option>
                {clients.filter((c) => c.active).map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </select>
            </Field>
            <Field label="Rate limit / min">
              <input className={`${inputClass} w-28`} type="number" value={keyForm.rate} onChange={(e) => setKeyForm({ ...keyForm, rate: e.target.value })} />
            </Field>
            <Button onClick={createKey} disabled={keyForm.name.trim() === ''}>Issue key</Button>
          </div>
        )}
        <DataTable
          rows={keys}
          rowKey={(k) => k.id}
          empty="No API keys issued yet."
          columns={[
            { header: 'Name', primary: true, cell: (k) => <span className="font-medium text-gray-700">{k.name}</span> },
            { header: 'Client', cell: (k) => k.client ?? '—' },
            { header: 'Token', cell: (k) => <code className="text-xs text-gray-500">{k.key_prefix}</code> },
            { header: 'Rate/min', cell: (k) => k.rate_limit_per_minute },
            { header: 'Default code', cell: (k) => <span className="text-xs">{k.default_access_code ?? '—'}</span> },
            { header: 'Usage', cell: (k) => k.usage_events },
            { header: 'Last used', cell: (k) => <span className="text-xs">{k.last_used_at?.slice(0, 10) ?? 'never'}</span> },
            { header: 'Status', cell: (k) => (k.active ? <Badge tone="green">active</Badge> : <Badge tone="red">revoked</Badge>) },
          ] satisfies Column<Key>[]}
          actions={isAdmin ? (k) => (
            <span className="flex gap-2">
              <Button kind="secondary" onClick={() => rotate(k.id)}>Rotate</Button>
              <Button kind={k.active ? 'danger' : 'secondary'} onClick={() => run(() => patch(`/keys/${k.id}`, { active: !k.active }))}>
                {k.active ? 'Revoke' : 'Restore'}
              </Button>
            </span>
          ) : undefined}
        />
      </Card>
    </div>
  )
}
