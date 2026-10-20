export default defineNuxtRouteMiddleware((to) => {
  if (useAuthStore().isLoggedIn) {
    return navigateTo(safeRedirect(to.query.redirect))
  }
})
