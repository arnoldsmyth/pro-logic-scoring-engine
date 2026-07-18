// Fetch wrapper for the panel admin API: session cookies + Sanctum CSRF.
// Every mutating request echoes the XSRF-TOKEN cookie Laravel sets.

let csrfReady = false

function cookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'))
  return match ? decodeURIComponent(match[1]) : null
}

async function ensureCsrf(): Promise<void> {
  if (!csrfReady || cookie('XSRF-TOKEN') === null) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    csrfReady = true
  }
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    public details?: unknown,
  ) {
    super(message)
  }
}

export async function api<T = unknown>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase()
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...((options.headers as Record<string, string>) ?? {}),
  }
  if (method !== 'GET') {
    await ensureCsrf()
    headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN') ?? ''
    if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  }

  const response = await fetch(`/panel/api${path}`, { ...options, headers, credentials: 'include' })
  if (response.status === 204) return undefined as T

  const body = await response.json().catch(() => ({}))
  if (!response.ok) {
    const error = (body as { error?: { code?: string; message?: string; details?: unknown } }).error
    throw new ApiError(response.status, error?.code ?? 'error', error?.message ?? `HTTP ${response.status}`, error?.details)
  }
  return body as T
}

/** Build a query string from a params map, skipping undefined/null/empty values. */
export const qs = (params: Record<string, string | number | undefined | null>) => {
  const u = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') u.set(k, String(v))
  return u.toString()
}

export const get = <T,>(path: string) => api<T>(path)
export const post = <T,>(path: string, body?: unknown) =>
  api<T>(path, { method: 'POST', body: body === undefined ? undefined : JSON.stringify(body) })
export const patch = <T,>(path: string, body: unknown) =>
  api<T>(path, { method: 'PATCH', body: JSON.stringify(body) })
