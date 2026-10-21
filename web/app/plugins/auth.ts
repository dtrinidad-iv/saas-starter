// Load the current user (and their organizations) once on startup. On a full
// page load this runs on the server and the data reaches the browser in the
// Pinia payload.
export default defineNuxtPlugin(async (nuxtApp) => {
  const auth = useAuthStore()
  const organizations = useOrganizationStore()

  // A "view as user" session expired while the server rendered this page, which is now
  // the admin's own (restoreAdminToken): say so, and leave the user's pages.
  if (import.meta.client && auth.supportViewEnded) {
    auth.supportViewEnded = false
    onNuxtReady(() => nuxtApp.runWithContext(() => {
      useToast().add({ title: 'The support view ended', description: 'You\'re back in your own account.', color: 'info' })
      if (!useRoute().path.startsWith('/admin')) {
        return navigateTo('/admin/users')
      }
    }))
  }

  if (auth.token && !auth.user) {
    await auth.fetchMe()

    if (auth.isLoggedIn) {
      await organizations.fetch().catch(error => console.error('Could not load organizations', error))
    }
  }
})
