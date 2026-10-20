// Load the current user (and their organizations) once on startup. On a full
// page load this runs on the server and the data reaches the browser in the
// Pinia payload.
export default defineNuxtPlugin(async () => {
  const auth = useAuthStore()
  const organizations = useOrganizationStore()

  if (auth.token && !auth.user) {
    await auth.fetchMe()

    if (auth.isLoggedIn) {
      await organizations.fetch().catch(error => console.error('Could not load organizations', error))
    }
  }
})
