<script setup lang="ts">
import type { User } from '~/types/api'

definePageMeta({ layout: 'auth', middleware: 'auth' })
useSeoMeta({ title: 'Verify email' })

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const { sending, resend } = useEmailVerification()

const status = ref<'verifying' | 'failed'>('verifying')
const failure = ref('')

function param(name: string): string {
  const value = route.query[name]
  return typeof value === 'string' ? value : ''
}

// In the browser only: the link is single-purpose, so don't post it during SSR too.
onMounted(async () => {
  const [id, hash, expires, signature] = ['id', 'hash', 'expires', 'signature'].map(param)
  if (!id || !hash || !expires || !signature) {
    status.value = 'failed'
    failure.value = 'This link is incomplete. Open the link from the email again.'
    return
  }

  try {
    const path = `/api/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}`
    const response = await api<{ data: User }>(path, { method: 'POST', query: { expires, signature } })
    auth.setUser(response.data)
    toast.add({ title: 'Email verified', color: 'success' })
    await navigateTo('/')
  }
  catch (error) {
    status.value = 'failed'
    failure.value = isFetchError(error) && error.statusCode === 403
      ? 'This link has expired, or it is for another account or an older email address.'
      : errorMessage(error)
  }
})
</script>

<template>
  <UCard>
    <template #header>
      <h1 class="text-xl font-semibold">
        Verify email
      </h1>
    </template>

    <p
      v-if="status === 'verifying'"
      class="flex items-center gap-2 text-sm text-muted"
      role="status"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="size-4 animate-spin"
      />
      Verifying {{ auth.user?.email }}…
    </p>

    <div
      v-else
      class="space-y-4"
    >
      <UAlert
        color="error"
        variant="subtle"
        icon="i-lucide-link-2-off"
        title="Could not verify your email"
        :description="failure"
        role="alert"
      />
      <p class="text-sm text-muted">
        You're logged in as {{ auth.user?.email }}.
      </p>
      <div class="flex flex-wrap gap-2">
        <UButton
          v-if="auth.user && !auth.user.email_verified"
          label="Send a new link"
          :loading="sending"
          @click="resend"
        />
        <UButton
          to="/"
          color="neutral"
          variant="outline"
          label="Go to dashboard"
        />
      </div>
    </div>
  </UCard>
</template>
