import { defineStore } from 'pinia'
import type { Organization } from '~/types/api'

export const useOrganizationStore = defineStore('organization', () => {
  const auth = useAuthStore()

  const organizations = ref<Organization[]>([])

  // The server decides the current organization; it arrives with the user from /api/me.
  const current = computed(() => auth.user?.current_organization ?? null)

  async function fetch() {
    const api = useApi()
    const response = await api<{ data: Organization[] }>('/api/organizations')
    organizations.value = response.data
  }

  async function switchTo(organizationId: number) {
    const api = useApi()
    const nuxtApp = useNuxtApp()

    const response = await api<{ data: Organization }>(`/api/organizations/${organizationId}/switch`, {
      method: 'POST',
    })
    auth.setCurrentOrganization(response.data)

    // Page data belongs to the previous organization; reload it.
    await nuxtApp.runWithContext(() => refreshNuxtData())
  }

  async function create(name: string) {
    const api = useApi()
    const nuxtApp = useNuxtApp()

    const response = await api<{ data: Organization }>('/api/organizations', {
      method: 'POST',
      body: { name },
    })
    organizations.value = [...organizations.value, response.data]
      .sort((a, b) => a.name.localeCompare(b.name))
    auth.setCurrentOrganization(response.data)

    await nuxtApp.runWithContext(() => refreshNuxtData())
  }

  function reset() {
    organizations.value = []
  }

  return { organizations, current, fetch, switchTo, create, reset }
})
