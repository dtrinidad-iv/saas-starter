/**
 * The one way to call the Laravel API. Attaches the bearer token and
 * clears the session when the API says the token is no longer valid.
 *
 * Call it synchronously at the top of a setup function or store action,
 * not after an `await`, so it can still reach the Nuxt app context.
 *
 * `handleUnauthorized: false` leaves a 401 to the caller (logout cleans up itself).
 */
export function useApi({ handleUnauthorized = true }: { handleUnauthorized?: boolean } = {}) {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  const nuxtApp = useNuxtApp()

  return $fetch.create({
    baseURL: config.public.apiBase,
    headers: { Accept: 'application/json' },

    onRequest({ options }) {
      if (auth.token) {
        options.headers.set('Authorization', `Bearer ${auth.token}`)
      }
    },

    async onResponseError({ response }) {
      if (response.status !== 401 || !handleUnauthorized) {
        return
      }

      auth.clearSession()

      // On the server, the auth middleware does the redirect on this same request.
      if (import.meta.client) {
        await nuxtApp.runWithContext(() => navigateTo('/login'))
      }
    },
  })
}
