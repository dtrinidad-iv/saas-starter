<script setup lang="ts">
import type { DropdownMenuItem, NavigationMenuItem } from '@nuxt/ui'

const auth = useAuthStore()
const organizations = useOrganizationStore()
const route = useRoute()

// The mobile menu closes on navigation by itself; also close it after switching
// organization from it, since the list page doesn't navigate.
const menuOpen = ref(false)
watch(() => organizations.current?.id, () => {
  menuOpen.value = false
})

async function logout() {
  await auth.logout()
  await navigateTo('/login')
}

const userMenu = computed<DropdownMenuItem[][]>(() => [
  [{ label: auth.user?.email ?? '', type: 'label' }],
  [{ label: 'Log out', icon: 'i-lucide-log-out', onSelect: logout }],
])

// Organization pages only make sense with a current organization.
// `active` also gives the link aria-current="page".
const navigation = computed<NavigationMenuItem[]>(() => auth.user?.current_organization
  ? [
      { label: 'Projects', icon: 'i-lucide-folder', to: '/projects', active: route.path.startsWith('/projects') },
      { label: 'Members', icon: 'i-lucide-users', to: '/members', active: route.path.startsWith('/members') },
      { label: 'Roles', icon: 'i-lucide-shield', to: '/roles', active: route.path.startsWith('/roles') },
    ]
  : [])
</script>

<template>
  <div class="min-h-screen">
    <!-- Below lg, the switcher and links move into the header's menu (#body) so the header never overflows. -->
    <UHeader
      v-model:open="menuOpen"
      :toggle="!!auth.user"
      :menu="{ title: 'Navigation' }"
    >
      <template #left>
        <NuxtLink
          to="/"
          class="shrink-0 font-semibold"
        >
          SaaS Starter
        </NuxtLink>
        <OrgSwitcher
          v-if="auth.user"
          class="ms-3 hidden lg:block"
        />
      </template>

      <UNavigationMenu
        :items="navigation"
        variant="link"
      />

      <template #right>
        <UDropdownMenu
          v-if="auth.user"
          :items="userMenu"
          :content="{ align: 'end' }"
        >
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-circle-user"
            trailing-icon="i-lucide-chevron-down"
            :aria-label="`${auth.user.name}, account menu`"
            :ui="{ label: 'hidden sm:inline' }"
            :label="auth.user.name"
          />
        </UDropdownMenu>
      </template>

      <template
        v-if="auth.user"
        #body
      >
        <div class="space-y-4">
          <OrgSwitcher />
          <UNavigationMenu
            :items="navigation"
            orientation="vertical"
            class="-mx-2.5"
          />
        </div>
      </template>
    </UHeader>

    <UMain>
      <UContainer class="py-8">
        <slot />
      </UContainer>
    </UMain>
  </div>
</template>
