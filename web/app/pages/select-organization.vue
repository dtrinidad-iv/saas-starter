<script setup lang="ts">
definePageMeta({ layout: 'auth', middleware: 'auth' })
useSeoMeta({ title: 'Choose an organization' })

const auth = useAuthStore()
const organizations = useOrganizationStore()
const toast = useToast()
const route = useRoute()

// Never back to this page, which would leave the user on it.
const redirect = computed(() => {
  const target = safeRedirect(route.query.redirect)
  return target.startsWith('/select-organization') ? '/' : target
})

// Loaded by the auth plugin on a full page load, or by the login page.
if (!organizations.organizations.length) {
  await organizations.fetch().catch(error => console.error('Could not load organizations', error))
}

// Nothing to choose (e.g. this URL opened directly with one organization).
if (organizations.organizations.length <= 1) {
  await navigateTo(redirect.value, { replace: true })
}

const switchingId = ref<number | null>(null)

async function choose(organizationId: number) {
  if (switchingId.value !== null) {
    return
  }

  switchingId.value = organizationId
  try {
    if (organizationId !== organizations.current?.id) {
      await organizations.switchTo(organizationId)
    }
  }
  catch (error) {
    toast.add({ title: 'Could not switch organization', description: errorMessage(error), color: 'error' })
    // Membership may have changed; show the server's view.
    await organizations.fetch().catch(() => {})
    return
  }
  finally {
    switchingId.value = null
  }

  await navigateTo(redirect.value)
}
</script>

<template>
  <UCard>
    <template #header>
      <h1 class="text-xl font-semibold">
        Choose an organization
      </h1>
      <p class="mt-1 text-sm text-muted">
        You can switch later from the sidebar.
      </p>
    </template>

    <ul class="space-y-2">
      <li
        v-for="organization in organizations.organizations"
        :key="organization.id"
      >
        <UButton
          color="neutral"
          variant="outline"
          block
          :loading="switchingId === organization.id"
          :disabled="switchingId !== null && switchingId !== organization.id"
          trailing-icon="i-lucide-chevron-right"
          :ui="{ base: 'justify-start text-left', trailingIcon: 'ms-auto' }"
          @click="choose(organization.id)"
        >
          <span class="min-w-0">
            <span class="block truncate font-medium">{{ organization.name }}</span>
            <span class="block truncate text-xs text-muted">{{ organization.role.name }}</span>
          </span>
          <UBadge
            v-if="organization.id === organizations.current?.id"
            label="Last used"
            color="neutral"
            variant="subtle"
            size="sm"
            class="ms-2 shrink-0"
          />
        </UButton>
      </li>
    </ul>

    <template #footer>
      <p class="text-center text-sm text-muted">
        Not you?
        <ULink
          as="button"
          class="text-primary font-medium"
          @click="auth.logout()"
        >
          Log in with another account
        </ULink>
      </p>
    </template>
  </UCard>
</template>
