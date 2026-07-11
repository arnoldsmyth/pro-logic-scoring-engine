import { BrowserRouter, NavLink, Navigate, Route, Routes } from 'react-router-dom'
import { AuthProvider, useAuth } from './auth'
import Assessments from './views/Assessments'
import Codes from './views/Codes'
import Content from './views/Content'
import Dashboard from './views/Dashboard'
import Keys from './views/Keys'
import Login from './views/Login'
import Norms from './views/Norms'
import Pipeline from './views/Pipeline'

const NAV = [
  { to: '/', label: 'Dashboard' },
  { to: '/keys', label: 'Clients & keys' },
  { to: '/codes', label: 'Codes & royalties' },
  { to: '/assessments', label: 'Assessments' },
  { to: '/norms', label: 'Norms & analytics' },
  { to: '/pipeline', label: 'Pipeline' },
  { to: '/content', label: 'Content' },
]

function Shell() {
  const { user, loading, logout } = useAuth()

  if (loading) return <div className="p-8 text-sm text-gray-400">Loading…</div>
  if (!user) return <Login />

  return (
    <div className="min-h-screen bg-gray-50">
      <header className="border-b border-gray-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
          <div className="flex items-center gap-6">
            <span className="font-semibold text-gray-800">Scoring Engine</span>
            <nav className="flex gap-1">
              {NAV.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.to === '/'}
                  className={({ isActive }) =>
                    `rounded-md px-3 py-1.5 text-sm ${isActive ? 'bg-sky-50 font-medium text-sky-700' : 'text-gray-600 hover:bg-gray-100'}`
                  }
                >
                  {item.label}
                </NavLink>
              ))}
            </nav>
          </div>
          <div className="flex items-center gap-3 text-sm text-gray-500">
            <span>
              {user.name} <span className="text-xs text-gray-400">({user.role})</span>
            </span>
            <button onClick={logout} className="text-gray-400 hover:text-gray-600">
              Sign out
            </button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-6xl px-4 py-6">
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/keys" element={<Keys />} />
          <Route path="/codes" element={<Codes />} />
          <Route path="/assessments" element={<Assessments />} />
          <Route path="/norms" element={<Norms />} />
          <Route path="/pipeline" element={<Pipeline />} />
          <Route path="/content" element={<Content />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </main>
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
