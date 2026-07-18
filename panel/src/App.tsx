import { useState } from 'react'
import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom'
import {
  BookOpen, FileBarChart, FlaskConical, KeyRound, LayoutDashboard, LogOut, Menu,
  Ticket, Users, Workflow, X,
} from 'lucide-react'
import { AuthProvider, useAuth } from './auth'
import Assessments from './views/Assessments'
import CodeDetail from './views/codes/CodeDetail'
import CodeNew from './views/codes/CodeNew'
import CodesList from './views/codes/CodesList'
import Content from './views/Content'
import Dashboard from './views/Dashboard'
import Keys from './views/Keys'
import Login from './views/Login'
import Norms from './views/Norms'
import Pipeline from './views/Pipeline'
import Reports from './views/Reports'

const NAV = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/keys', label: 'Clients & keys', icon: KeyRound },
  { to: '/codes', label: 'Codes & royalties', icon: Ticket },
  { to: '/assessments', label: 'Assessments', icon: Users },
  { to: '/norms', label: 'Norms & analytics', icon: FlaskConical },
  { to: '/pipeline', label: 'Pipeline', icon: Workflow },
  { to: '/content', label: 'Content', icon: BookOpen },
  { to: '/reports', label: 'Reports', icon: FileBarChart },
]

function Nav({ onNavigate }: { onNavigate?: () => void }) {
  return (
    <nav className="flex flex-col gap-1">
      {NAV.map(({ to, label, icon: Icon }) => (
        <NavLink
          key={to}
          to={to}
          end={to === '/'}
          onClick={onNavigate}
          className={({ isActive }) =>
            `flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors ${
              isActive
                ? 'bg-sky-50 font-medium text-sky-700'
                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
            }`
          }
        >
          <Icon size={18} strokeWidth={1.75} />
          {label}
        </NavLink>
      ))}
    </nav>
  )
}

function Brand() {
  return (
    <div className="flex items-center gap-2.5">
      <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-600 text-sm font-bold text-white">
        SE
      </div>
      <div className="leading-tight">
        <div className="text-sm font-semibold text-gray-900">Scoring Engine</div>
        <div className="text-[11px] text-gray-400">control panel</div>
      </div>
    </div>
  )
}

function UserFooter() {
  const { user, logout } = useAuth()
  if (!user) return null
  return (
    <div className="flex items-center justify-between gap-2 border-t border-gray-200 pt-3">
      <div className="min-w-0 leading-tight">
        <div className="truncate text-sm font-medium text-gray-700">{user.name}</div>
        <div className="text-xs text-gray-400">{user.role}</div>
      </div>
      <button
        onClick={logout}
        aria-label="Sign out"
        className="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
      >
        <LogOut size={18} strokeWidth={1.75} />
      </button>
    </div>
  )
}

function Shell() {
  const { user, loading } = useAuth()
  const [drawerOpen, setDrawerOpen] = useState(false)

  if (loading) return <div className="p-8 text-sm text-gray-400">Loading…</div>
  if (!user) return <Login />

  return (
    <div className="min-h-screen bg-gray-50 lg:flex">
      {/* Desktop sidebar */}
      <aside className="hidden lg:flex lg:w-60 lg:shrink-0 lg:flex-col lg:gap-6 lg:border-r lg:border-gray-200 lg:bg-white lg:p-4">
        <Brand />
        <div className="flex-1">
          <Nav />
        </div>
        <UserFooter />
      </aside>

      {/* Mobile top bar */}
      <header className="sticky top-0 z-20 flex items-center justify-between border-b border-gray-200 bg-white px-4 py-3 lg:hidden">
        <Brand />
        <button
          onClick={() => setDrawerOpen(true)}
          aria-label="Open navigation"
          className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
        >
          <Menu size={22} strokeWidth={1.75} />
        </button>
      </header>

      {/* Mobile drawer */}
      {drawerOpen && (
        <div className="fixed inset-0 z-30 lg:hidden">
          <div className="absolute inset-0 bg-gray-900/40" onClick={() => setDrawerOpen(false)} />
          <div className="absolute inset-y-0 left-0 flex w-72 flex-col gap-6 bg-white p-4 shadow-xl">
            <div className="flex items-center justify-between">
              <Brand />
              <button
                onClick={() => setDrawerOpen(false)}
                aria-label="Close navigation"
                className="rounded-lg p-2 text-gray-500 hover:bg-gray-100"
              >
                <X size={20} strokeWidth={1.75} />
              </button>
            </div>
            <div className="flex-1 overflow-y-auto">
              <Nav onNavigate={() => setDrawerOpen(false)} />
            </div>
            <UserFooter />
          </div>
        </div>
      )}

      <div className="min-w-0 flex-1">
        <main className="mx-auto max-w-6xl px-4 py-6 lg:px-8">
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/keys" element={<Keys />} />
            <Route path="/codes" element={<CodesList />} />
            <Route path="/codes/new" element={<CodeNew />} />
            <Route path="/codes/:code" element={<CodeDetail />} />
            <Route path="/assessments" element={<Assessments />} />
            <Route path="/norms" element={<Norms />} />
            <Route path="/pipeline" element={<Pipeline />} />
            <Route path="/content" element={<Content />} />
            <Route path="/reports" element={<Reports />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </main>
      </div>
    </div>
  )
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter basename="/panel">
        <Shell />
      </BrowserRouter>
    </AuthProvider>
  )
}
