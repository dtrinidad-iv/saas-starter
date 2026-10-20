import { defineStore, skipHydrate } from 'pinia'
import type { AuthResponse, LoginPayload, RegisterPayload, User } from '~/types/api'

export const useAuthStore = defineStore('auth', () => {
  // A cookie (not localStorage) so server-side rendering can send the token too.
  const token = useCookie<string | null>('auth_token', {
    default: () => null,
    sameSite: 'lax',
    secure: !import.meta.dev,
    maxAge: 60 * 60 * 24 * 30,
  })
  const user = ref<User | null>(null)

  const isLoggedIn = computed(() => !!user.value?.id)

  function setSession(response: AuthResponse) {
    token.value = response.token
    user.value = response.user
  }

  function clearSession() {
    token.value = null
    user.value = null
  }

  async function register(payload: RegisterPayload) {
    const api = useApi()
    setSession(await api<AuthResponse>('/api/register', { method: 'POST', body: payload }))
  }

  async function login(payload: LoginPayload) {
    const api = useApi()
    setSession(await api<AuthResponse>('/api/login', { method: 'POST', body: payload }))
  }

  async function logout() {
    const api = useApi()
    try {
      await api('/api/logout', { method: 'POST' })
    }
    finally {
      // Log out locally even if the API call fails (e.g. token already revoked).
      clearSession()
    }
  }

  async function fetchMe() {
    if (!token.value) {
      return
    }

    const api = useApi()
    try {
      const response = await api<{ data: User }>('/api/me')
      user.value = response?.data?.id ? response.data : null
    }
    catch (error) {
      // A 401 already cleared the session in useApi. Anything else (API down)
      // leaves the token in place so a later reload can recover.
      console.error('Could not load the current user', error)
    }
  }

  // skipHydrate: keep the live cookie ref instead of replacing it with the SSR payload value.
  return { token: skipHydrate(token), user, isLoggedIn, register, login, logout, fetchMe, clearSession }
})
