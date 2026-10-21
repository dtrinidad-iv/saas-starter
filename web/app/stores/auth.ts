import { defineStore, getActivePinia, skipHydrate } from 'pinia'
import type { RouteLocationRaw } from 'vue-router'
import type { AuthResponse, LoginPayload, Organization, RegisterPayload, ResetPasswordPayload, User } from '~/types/api'

export const useAuthStore = defineStore('auth', () => {
  // The Pinia instance of this request. Other stores are looked up through it, because
  // clearSession can run after an await (outside the Nuxt context) during SSR.
  const pinia = getActivePinia()

  // A cookie (not localStorage) so server-side rendering can send the token too.
  const token = useCookie<string | null>('auth_token', {
    default: () => null,
    sameSite: 'lax',
    secure: !import.meta.dev,
    maxAge: 60 * 60 * 24 * 30,
  })
  const user = ref<User | null>(null)

  // Logout drops the token before the user (see logout), so check both.
  const isLoggedIn = computed(() => !!token.value && !!user.value?.id)

  function setSession(response: AuthResponse) {
    token.value = response.token
    user.value = response.user
  }

  function clearSession() {
    token.value = null
    user.value = null
    useOrganizationStore(pinia).reset()
  }

  function setCurrentOrganization(organization: Organization) {
    if (user.value) {
      user.value = { ...user.value, current_organization: organization }
    }
  }

  async function register(payload: RegisterPayload) {
    const api = useApi()
    setSession(await api<AuthResponse>('/api/register', { method: 'POST', body: payload }))
  }

  async function login(payload: LoginPayload) {
    const api = useApi()
    setSession(await api<AuthResponse>('/api/login', { method: 'POST', body: payload }))
  }

  // A reset signs out every device and logs in with a new token.
  async function resetPassword(payload: ResetPasswordPayload) {
    const api = useApi()
    setSession(await api<AuthResponse>('/api/reset-password', { method: 'POST', body: payload }))
  }

  // After an account change the API returned (name, email, verification).
  function setUser(updated: User) {
    user.value = updated
  }

  async function logout(to: RouteLocationRaw = '/login') {
    // Not useApi's 401 handling: it would clear the session on this page (see below).
    const api = useApi({ handleUnauthorized: false })
    const nuxtApp = useNuxtApp()
    try {
      await api('/api/logout', { method: 'POST' })
    }
    catch (error) {
      // Log out locally anyway (e.g. token already revoked in another tab).
      console.error('Could not revoke the token', error)
    }

    // Drop the token, leave the page, then clear the user: clearing it while the
    // page is still shown fires its organization watchers (refetching without a
    // token) and hides the sidebar for a frame.
    token.value = null
    try {
      await nuxtApp.runWithContext(() => navigateTo(to))
    }
    finally {
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
  return { token: skipHydrate(token), user, isLoggedIn, register, login, resetPassword, logout, fetchMe, setUser, clearSession, setCurrentOrganization }
})
