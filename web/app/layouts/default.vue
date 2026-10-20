<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

const auth = useAuthStore()

async function logout() {
  await auth.logout()
  await navigateTo('/login')
}

const userMenu = computed<DropdownMenuItem[][]>(() => [
  [{ label: auth.user?.email ?? '', type: 'label' }],
  [{ label: 'Log out', icon: 'i-lucide-log-out', onSelect: logout }],
])
</script>

<template>
  <div class="min-h-screen">
    <header class="border-b border-default">
      <UContainer class="flex h-16 items-center justify-between gap-4">
        <NuxtLink
          to="/"
          class="font-semibold"
        >
          SaaS Starter
        </NuxtLink>

        <!-- The organization switcher will go here. -->

        <UDropdownMenu
          v-if="auth.user"
          :items="userMenu"
          :content="{ align: 'end' }"
        >
          <UButton
            color="neutral"
            variant="ghost"
            :label="auth.user.name"
            trailing-icon="i-lucide-chevron-down"
          />
        </UDropdownMenu>
      </UContainer>
    </header>

    <UMain>
      <UContainer class="py-8">
        <slot />
      </UContainer>
    </UMain>
  </div>
</template>
