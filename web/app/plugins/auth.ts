// Load the current user once on startup. On a full page load this runs on the
// server and the user is handed to the browser in the Pinia payload.
export default defineNuxtPlugin(async () => {
  const auth = useAuthStore()

  if (auth.token && !auth.user) {
    await auth.fetchMe()
  }
})
