# Control Panel (React SPA)

Admin UI per [docs/08-control-panel.md](../platform/docs/08-control-panel.md):
dashboard, API keys, access codes & royalty statements, assessment search with
audit-trace walkthrough, norm-set lifecycle, and the under-the-hood pipeline
pages rendered from live rule data.

## Stack

Vite + React 19 + TypeScript + Tailwind v4. Auth is Laravel session cookies
(Sanctum CSRF flow) against `/panel/api/*` — completely separate from the
`/v2` bearer-key API. Roles: `admin` (mutations) / `viewer` (read-only).

## Develop

```bash
npm install
npm run dev        # Vite on :5173, proxies /panel/api + /sanctum to :8000
```

Run the Laravel API alongside: `php artisan serve` in `../api`.

## Build & serve

```bash
npm run build      # outputs to ../api/public/panel-assets (gitignored)
```

Laravel serves the SPA at `/panel` (catch-all in `api/routes/web.php`). The
asset directory is deliberately named differently from the `/panel` URL
prefix — PHP's built-in server mis-resolves SCRIPT_NAME when a URL prefix
collides with a real directory.

## Panel users

```bash
php artisan panel:user "Name" email@example.com --role=admin
```
