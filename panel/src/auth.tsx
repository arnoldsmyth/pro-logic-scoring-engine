import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { get, post } from './api'

export type User = { id: number; name: string; email: string; role: 'admin' | 'viewer' }

type AuthState = {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthState>(null!)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    get<{ user: User | null }>('/me')
      .then((r) => setUser(r.user))
      .catch(() => setUser(null))
      .finally(() => setLoading(false))
  }, [])

  const login = async (email: string, password: string) => {
    const r = await post<{ user: User }>('/login', { email, password })
    setUser(r.user)
  }

  const logout = async () => {
    await post('/logout')
    setUser(null)
  }

  return <AuthContext.Provider value={{ user, loading, login, logout }}>{children}</AuthContext.Provider>
}

export const useAuth = () => useContext(AuthContext)
