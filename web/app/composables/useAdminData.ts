import type { WatchSource } from 'vue'

export type AdminFailure =
  | { reason: 'not-found' }
  // The API's EnsurePlatformAdmin: turn two-factor on, or enter a fresh code.
  | { reason: 'two_factor_required' | 'two_factor_reauth', message: string }
  // Retrying won't help until the email is verified (the banner resends the link).
  | { reason: 'email_unverified' }
  | { reason: 'error', message: string }

export type AdminResult<T> = { ok: true, data: T } | { ok: false, failure: AdminFailure }

/**
 * Loads a platform admin endpoint. Failures come back as data (like the project
 * page), so a page can show "not found" (the API answers 404 to anyone who isn't
 * an admin), the two-factor step, or an error, all through AdminGate.
 */
export async function useAdminData<T>(key: MaybeRefOrGetter<string>, request: () => Promise<T>, watchSources: WatchSource[] = []) {
  const auth = useAuthStore()
  // Before the await: after it, this composable has lost the Nuxt context on the server.
  const requestEvent = useRequestEvent()

  const { data: result, status, refresh } = await useAsyncData<AdminResult<T>>(
    key,
    async () => {
      // Not an admin: same answer the API would give, without asking it.
      if (!auth.user?.is_platform_admin) {
        return { ok: false, failure: { reason: 'not-found' } }
      }
      try {
        return { ok: true, data: await request() }
      }
      catch (error) {
        return { ok: false, failure: adminFailure(error) }
      }
    },
    { watch: watchSources },
  )

  const data = computed(() => (result.value?.ok ? result.value.data : null))
  const failure = computed(() => (result.value && !result.value.ok ? result.value.failure : null))

  // A server-rendered "not found" page should also be an HTTP 404.
  if (requestEvent && failure.value?.reason === 'not-found') {
    setResponseStatus(requestEvent, 404)
  }

  return { data, failure, status, refresh }
}

function adminFailure(error: unknown): AdminFailure {
  if (isFetchError(error)) {
    if (error.statusCode === 404) {
      return { reason: 'not-found' }
    }
    if (isEmailUnverified(error)) {
      return { reason: 'email_unverified' }
    }
    const code = error.data?.code
    if (error.statusCode === 403 && (code === 'two_factor_required' || code === 'two_factor_reauth')) {
      return { reason: code, message: error.data?.message ?? '' }
    }
  }
  return { reason: 'error', message: errorMessage(error) }
}
