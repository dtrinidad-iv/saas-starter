<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

// `header`: compact trigger for the top bar, without the version line.
defineProps<{ collapsed?: boolean, header?: boolean }>()

const auth = useAuthStore()
const colorMode = useColorMode()
const { appVersion } = useRuntimeConfig().public

const appearances = [
  { label: 'Light', value: 'light', icon: 'i-lucide-sun' },
  { label: 'Dark', value: 'dark', icon: 'i-lucide-moon' },
  { label: 'System', value: 'system', icon: 'i-lucide-monitor' },
]

const items = computed<DropdownMenuItem[][]>(() => [
  [{ label: auth.user?.email ?? '', type: 'label' }],
  [{
    label: 'Appearance',
    icon: 'i-lucide-sun-moon',
    children: appearances.map(appearance => ({
      label: appearance.label,
      icon: appearance.icon,
      type: 'checkbox' as const,
      checked: colorMode.preference === appearance.value,
      onSelect: (event: Event) => {
        event.preventDefault()
        colorMode.preference = appearance.value
      },
    })),
  }],
  [{ label: 'Account settings', icon: 'i-lucide-user-cog', to: '/account' }],
  [{ label: 'Log out', icon: 'i-lucide-log-out', onSelect: () => auth.logout() }],
])
</script>

<template>
  <div
    v-if="auth.user"
    :class="header ? '' : 'w-full space-y-1'"
  >
    <UDropdownMenu
      :items="items"
      :content="header ? { align: 'end' } : { side: 'top', align: 'start' }"
    >
      <UButton
        v-if="header"
        color="neutral"
        variant="ghost"
        icon="i-lucide-circle-user"
        trailing-icon="i-lucide-chevron-down"
        :label="auth.user.name"
        :aria-label="`${auth.user.name}, account menu`"
        :ui="{ label: 'hidden sm:inline' }"
      />
      <UButton
        v-else
        color="neutral"
        variant="ghost"
        icon="i-lucide-circle-user"
        :label="collapsed ? undefined : auth.user.name"
        :aria-label="`${auth.user.name}, account menu`"
        :trailing-icon="collapsed ? undefined : 'i-lucide-chevrons-up-down'"
        :square="collapsed"
        block
        :ui="{ label: 'truncate', trailingIcon: 'ms-auto' }"
      />
    </UDropdownMenu>

    <p
      v-if="!header && !collapsed"
      class="px-2.5 text-xs text-muted"
    >
      v{{ appVersion }} ·
      <ULink
        to="https://github.com/dtrinidad-iv/saas-starter"
        target="_blank"
        class="text-muted hover:text-default"
      >
        Source
      </ULink>
    </p>
  </div>
</template>
