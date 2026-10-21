import { defineStore, getActivePinia, skipHydrate } from 'pinia'
import type { RouteLocationRaw } from 'vue-router'
import type { AuthResponse, ImpersonationSession, LoginPayload, LoginResponse, Organization, RegisterPayload, ResetPasswordPayload, TwoFactorChallengePayload, User } from '~/types/api'

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
  // A platform admin's own token, set aside during a read-only "view as user" session
  // (token is then the session's). Stop or the session's expiry puts it back. Kept for
  // 12 hours, well past any session (ADMIN_IMPERSONATION_MINUTES, 30 by default), not
  // the 30 days of auth_token; without it, Stop just logs out.
  const adminToken = useCookie<string | null>('admin_token', {
    default: () => null,
    sameSite: 'lax',
    secure: !import.meta.dev,
    maxAge: 60 * 60 * 12,
  })
  const user = ref<User | null>(null)
  // The support view expired during server rendering (restoreAdminToken): the browser
  // says so and leaves the user's pages once it's running.
  const supportViewEnded = ref(false)
  let stopping = false

  // Logout drops the token before the user (see logout), so check both.
  const isLoggedIn = computed(() => !!token.value && !!user.value?.id)
  const isImpersonating = computed(() => !!user.value?.impersonated_by)

  function setSession(response: AuthResponse) {
    token.value = response.token
    user.value = response.user
  }

  function clearSession() {
    token.value = null
    adminToken.value = null
    user.value = null
    useOrganizationStore(pinia).reset()
  }

  /**
   * Start a platform admin's read-only "view as user" session: keep the admin's token
   * aside, load the app as the user and open their dashboard.
   */
  async function startImpersonation(session: ImpersonationSession) {
    const api = useApi({ handleUnauthorized: false })
    const nuxtApp = useNuxtApp()
    const organizations = useOrganizationStore(pinia)

    adminToken.value = token.value
    token.value = session.token
    try {
      user.value = (await api<{ data: User }>('/api/me')).data
    }
    catch (error) {
      // End the session that was opened (best effort), then go back to the admin's token.
      await api('/api/logout', { method: 'POST' }).catch(() => {})
      token.value = adminToken.value
      adminToken.value = null
      throw error
    }

    organizations.reset()
    // Pages cached as the admin aren't the user's.
    nuxtApp.runWithContext(() => clearNuxtData())
    await nuxtApp.runWithContext(() => organizations.fetch()).catch(error => console.error('Could not load organizations', error))
    await nuxtApp.runWithContext(() => navigateTo('/'))
  }

  /**
   * During server rendering: the "view as user" session expired, so put the admin's
   * own token back (fetchMe then loads the admin). The browser finishes the job.
   */
  function restoreAdminToken() {
    if (!adminToken.value) {
      return
    }
    token.value = adminToken.value
    adminToken.value = null
    user.value = null
    supportViewEnded.value = true
    useOrganizationStore(pinia).reset()
  }

  /**
   * End the "view as user" session (Stop, or `expired`: the API already refused its
   * token) and go back to the admin's own account, on that user's admin page.
   */
  async function stopImpersonation({ expired = false }: { expired?: boolean } = {}) {
    if (stopping) {
      return
    }
    // The admin's token is gone from this browser (cookie cleared): just log out.
    if (!adminToken.value) {
      return logout()
    }
    stopping = true
    const api = useApi({ handleUnauthorized: false })
    const nuxtApp = useNuxtApp()
    const toast = useToast()
    const organizations = useOrganizationStore(pinia)
    const viewedId = user.value?.id

    try {
      if (!expired) {
        try {
          await api('/api/logout', { method: 'POST' })
        }
        catch (error) {
          // It ends on its own when it expires; go back to the admin account anyway.
          console.error('Could not end the support view', error)
        }
      }

      token.value = adminToken.value
      adminToken.value = null
      try {
        user.value = (await api<{ data: User }>('/api/me')).data
      }
      catch {
        // The admin's own token is gone too (signed out meanwhile).
        clearSession()
        await nuxtApp.runWithContext(() => navigateTo('/login'))
        return
      }

      organizations.reset()
      nuxtApp.runWithContext(() => clearNuxtData())
      await nuxtApp.runWithContext(() => organizations.fetch()).catch(error => console.error('Could not load organizations', error))
      if (expired) {
        toast.add({ title: 'The support view ended', description: 'You\'re back in your own account.', color: 'info' })
      }
      await nuxtApp.runWithContext(() => navigateTo(viewedId ? `/admin/users/${viewedId}` : '/admin/users'))
    }
    finally {
      stopping = false
    }
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

  // Logged in, or (two-factor on) the challenge to send with a code to completeTwoFactor.
  function startSession(response: LoginResponse): string | null {
    if ('two_factor' in response) {
      return response.challenge
    }
    setSession(response)
    return null
  }

  /** Returns the two-factor challenge when the API asks for a code, otherwise null (logged in). */
  async function login(payload: LoginPayload): Promise<string | null> {
    const api = useApi()
    return startSession(await api<LoginResponse>('/api/login', { method: 'POST', body: payload }))
  }

  // A reset signs out every device and logs in with a new token (after a code, with two-factor on).
  async function resetPassword(payload: ResetPasswordPayload): Promise<string | null> {
    const api = useApi()
    return startSession(await api<LoginResponse>('/api/reset-password', { method: 'POST', body: payload }))
  }

  async function completeTwoFactor(challenge: string, payload: TwoFactorChallengePayload) {
    const api = useApi()
    setSession(await api<AuthResponse>('/api/two-factor-challenge', { method: 'POST', body: { ...payload, challenge } }))
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
    // Twice at most: when a "view as user" session expired, useApi put the admin's own
    // token back, so ask again (with the same instance: no Nuxt context after an await).
    for (let attempt = 0; attempt < 2; attempt++) {
      const sent: string | null = token.value
      try {
        const response = await api<{ data: User }>('/api/me')
        user.value = response?.data?.id ? response.data : null
        return
      }
      catch (error) {
        if (token.value && token.value !== sent) {
          continue
        }
        // A 401 already cleared the session in useApi. Anything else (API down)
        // leaves the token in place so a later reload can recover.
        console.error('Could not load the current user', error)
        return
      }
    }
  }

  // skipHydrate: keep the live cookie ref instead of replacing it with the SSR payload value.
  return { token: skipHydrate(token), adminToken: skipHydrate(adminToken), user, supportViewEnded, isLoggedIn, isImpersonating, startImpersonation, stopImpersonation, restoreAdminToken, register, login, resetPassword, completeTwoFactor, logout, fetchMe, setUser, clearSession, setCurrentOrganization }
})
