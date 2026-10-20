export default defineNuxtRouteMiddleware((to) => {
  if (!useAuthStore().isLoggedIn) {
    // Come back here after logging in (e.g. an invitation link).
    const query = to.fullPath === '/' ? {} : { redirect: to.fullPath }
    return navigateTo({ path: '/login', query })
  }
})
