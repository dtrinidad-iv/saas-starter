import type { RouteLocationRaw } from 'vue-router'

/**
 * Where to go once a new session starts (log in, password reset): someone in several
 * organizations chooses one first. Not for an invitation link: accepting it sets the
 * organization anyway. Call it synchronously at the top of a setup function.
 */
export function useAfterLogin() {
  const organizations = useOrganizationStore()

  return async function afterLogin(redirect: string): Promise<RouteLocationRaw> {
    try {
      // The auth plugin only loads organizations on startup, not after this login.
      await organizations.fetch()
    }
    catch (error) {
      console.error('Could not load organizations', error)
      return redirect
    }

    // Already headed to the picker (e.g. it was opened while logged out): don't nest it.
    if (redirect.startsWith('/select-organization')) {
      return redirect
    }

    if (organizations.organizations.length > 1 && !redirect.startsWith('/join/')) {
      return { path: '/select-organization', query: { redirect } }
    }

    return redirect
  }
}
