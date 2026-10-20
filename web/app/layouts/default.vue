<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'

const auth = useAuthStore()
const organizations = useOrganizationStore()
const route = useRoute()
const { can } = useOrganizationPermissions()

// The mobile menu closes on navigation by itself (`autoClose`); also close it after switching
// organization from it, since the list page doesn't navigate.
const menuOpen = ref(false)
watch(() => organizations.current?.id, () => {
  menuOpen.value = false
})

// Organization pages only make sense with a current organization.
// `active` also gives the link aria-current="page".
const links = computed<NavigationMenuItem[]>(() => [
  { label: 'Dashboard', icon: 'i-lucide-house', to: '/', active: route.path === '/' },
  ...(auth.user?.current_organization
    ? [
        { label: 'Projects', icon: 'i-lucide-folder', to: '/projects', active: route.path.startsWith('/projects') },
        { label: 'Members', icon: 'i-lucide-users', to: '/members', active: route.path.startsWith('/members') },
        { label: 'Roles', icon: 'i-lucide-shield', to: '/roles', active: route.path.startsWith('/roles') },
        ...(can('audit_log.view')
          ? [{ label: 'Audit log', icon: 'i-lucide-scroll-text', to: '/audit-log', active: route.path.startsWith('/audit-log') }]
          : []),
        ...(can('ai_usage.view')
          ? [{ label: 'AI usage', icon: 'i-lucide-sparkles', to: '/ai-usage', active: route.path.startsWith('/ai-usage') }]
          : []),
      ]
    : []),
])

// A collapsed sidebar shows only icons (the label moves to a tooltip), so name each link.
const navigation = computed(() => links.value.map(item => ({ ...item, 'aria-label': item.label })))
</script>

<template>
  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      v-if="auth.user"
      v-model:open="menuOpen"
      collapsible
      resizable
      :menu="{ title: 'Navigation' }"
      :ui="{ footer: 'border-t border-default' }"
    >
      <template #header="{ collapsed }">
        <NuxtLink
          to="/"
          class="flex items-center gap-2 font-semibold"
          :class="collapsed ? 'mx-auto' : ''"
          aria-label="SaaS Starter, home"
        >
          <UIcon
            name="i-lucide-layers"
            class="size-5 shrink-0 text-primary"
          />
          <span v-if="!collapsed">SaaS Starter</span>
        </NuxtLink>
      </template>

      <template #default="{ collapsed }">
        <OrgSwitcher :collapsed="collapsed" />
        <UNavigationMenu
          :items="navigation"
          :collapsed="collapsed"
          orientation="vertical"
          tooltip
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardPanel>
      <!-- Pages render their own <h1>, so the app name here (mobile only, next to the sidebar toggle) is not a heading. -->
      <template #header>
        <UDashboardNavbar
          title="SaaS Starter"
          :as="{ title: 'p' }"
          :toggle="!!auth.user"
          :ui="{ title: 'lg:hidden' }"
        >
          <template #leading>
            <UDashboardSidebarCollapse
              v-if="auth.user"
              class="hidden lg:inline-flex"
            />
          </template>

          <template #right>
            <UserMenu header />
          </template>
        </UDashboardNavbar>
      </template>

      <!-- One <slot /> in a fixed place, so clearing the session never remounts the page. -->
      <template #body>
        <div class="mx-auto w-full max-w-6xl">
          <slot />
        </div>
      </template>
    </UDashboardPanel>
  </UDashboardGroup>
</template>
