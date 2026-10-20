// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  modules: ['@nuxt/eslint', '@nuxt/ui', '@pinia/nuxt'],

  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    public: {
      // Overridden by NUXT_PUBLIC_API_BASE
      apiBase: 'http://localhost:8000',
    },
  },

  compatibilityDate: '2025-07-15',
})
