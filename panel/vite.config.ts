import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Dev: Vite serves the SPA and proxies API + auth cookies to Laravel.
// Build: output goes into the Laravel public dir, served at /panel.
export default defineConfig({
  base: '/panel-assets/',
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/panel/api': 'http://127.0.0.1:8000',
      '/sanctum': 'http://127.0.0.1:8000',
    },
  },
  build: {
    outDir: '../api/public/panel-assets',
    emptyOutDir: true,
  },
})
