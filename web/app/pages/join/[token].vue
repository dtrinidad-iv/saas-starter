<script setup lang="ts">
import type { InvitationPreview, Organization } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Join organization' })

const route = useRoute()
const api = useApi()
const auth = useAuthStore()
const organizations = useOrganizationStore()
const toast = useToast()

const token = computed(() => String(route.params.token))

// Errors are returned as data so the page can explain them (403 wrong account, 404 invalid).
const { data: result } = await useAsyncData(`join:${token.value}`, async () => {
  try {
    const response = await api<{ data: InvitationPreview }>(`/api/join/${token.value}`)
    return { ok: true as const, invitation: response.data }
  }
  catch (error) {
    return {
      ok: false as const,
      status: isFetchError(error) ? error.statusCode ?? 0 : 0,
      message: errorMessage(error),
    }
  }
})

const accepting = ref(false)

async function accept() {
  accepting.value = true
  try {
    const response = await api<{ data: Organization }>(`/api/join/${token.value}`, { method: 'POST' })
    auth.setCurrentOrganization(response.data)
    await organizations.fetch()
    toast.add({ title: `Welcome to ${response.data.name}`, color: 'success' })
    await navigateTo('/')
  }
  catch (error) {
    toast.add({ title: 'Could not join', description: errorMessage(error), color: 'error' })
  }
  finally {
    accepting.value = false
  }
}

async function switchAccount() {
  await auth.logout({ path: '/login', query: { redirect: route.fullPath } })
}
</script>

<template>
  <div class="mx-auto max-w-md">
    <UCard v-if="result?.ok">
      <template #header>
        <h1 class="text-xl font-semibold">
          Join {{ result.invitation.organization.name }}
        </h1>
      </template>

      <p class="text-sm">
        <strong>{{ result.invitation.invited_by ?? 'Someone' }}</strong>
        invited <strong>{{ result.invitation.email }}</strong>
        to join <strong>{{ result.invitation.organization.name }}</strong>
        with the <strong>{{ result.invitation.role.name }}</strong> role.
      </p>

      <template #footer>
        <div class="flex justify-end gap-2">
          <UButton
            label="Not now"
            color="neutral"
            variant="ghost"
            to="/"
          />
          <UButton
            label="Accept invitation"
            :loading="accepting"
            @click="accept"
          />
        </div>
      </template>
    </UCard>

    <UCard v-else-if="result?.status === 403">
      <template #header>
        <h1 class="text-xl font-semibold">
          Wrong account
        </h1>
      </template>
      <p class="text-sm">
        {{ result.message }} You're logged in as <strong>{{ auth.user?.email }}</strong>.
      </p>
      <template #footer>
        <div class="flex justify-end">
          <UButton
            label="Log in with another account"
            @click="switchAccount"
          />
        </div>
      </template>
    </UCard>

    <UCard v-else>
      <template #header>
        <h1 class="text-xl font-semibold">
          Invitation not available
        </h1>
      </template>
      <p class="text-sm">
        {{ result?.message ?? 'This invitation is no longer valid.' }}
        Ask the person who invited you to send a new one.
      </p>
      <template #footer>
        <div class="flex justify-end">
          <UButton
            label="Go to dashboard"
            color="neutral"
            to="/"
          />
        </div>
      </template>
    </UCard>
  </div>
</template>
