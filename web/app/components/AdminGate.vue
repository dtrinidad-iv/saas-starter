<script setup lang="ts">
import type { AdminFailure } from '~/composables/useAdminData'

// What a platform admin page shows instead of its content when loading it failed.
defineProps<{ failure: AdminFailure }>()
const emit = defineEmits<{ retry: [] }>()

const api = useApi()

const pin = ref<number[]>([])
const pinInput = useTemplateRef<{ inputsRef: { $el: HTMLElement }[] }>('pinInput')
const error = ref<string | null>(null)
const loading = ref(false)

async function confirm() {
  if (loading.value) return
  const code = pin.value.join('')
  if (code.length !== 6) {
    error.value = 'Enter the 6-digit code.'
    return
  }

  loading.value = true
  error.value = null
  try {
    await api('/api/account/two-factor/reconfirm', { method: 'POST', body: { code } })
    emit('retry')
  }
  catch (caught) {
    const fieldErrors = toFormErrors(caught)
    error.value = fieldErrors[0]?.message ?? errorMessage(caught)
    pin.value = []
    await nextTick()
    pinInput.value?.inputsRef[0]?.$el.focus()
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UCard v-if="failure.reason === 'not-found'">
    <h1 class="text-xl font-semibold">
      Page not found
    </h1>
    <p class="mt-2 text-sm text-muted">
      This page doesn't exist, or you don't have access to it.
    </p>
    <UButton
      to="/"
      label="Go to the dashboard"
      color="neutral"
      variant="outline"
      class="mt-4"
    />
  </UCard>

  <UCard
    v-else-if="failure.reason === 'two_factor_required'"
    class="max-w-xl"
  >
    <div class="flex items-start gap-3">
      <UIcon
        name="i-lucide-shield-alert"
        class="mt-0.5 size-6 shrink-0 text-warning"
      />
      <div>
        <h1 class="text-xl font-semibold">
          Turn on two-factor to use the admin area
        </h1>
        <p class="mt-2 text-sm text-muted">
          The admin area shows every organization's members and billing, so it needs a code from an authenticator app as well as your password.
        </p>
        <UButton
          to="/account"
          label="Set up two-factor"
          icon="i-lucide-user-cog"
          class="mt-4"
        />
      </div>
    </div>
  </UCard>

  <UCard
    v-else-if="failure.reason === 'two_factor_reauth'"
    class="max-w-md"
  >
    <h1 class="text-xl font-semibold">
      Confirm it's you
    </h1>
    <p class="mt-2 text-sm text-muted">
      Enter the 6-digit code from your authenticator app to open the admin area on this device.
    </p>
    <form
      class="mt-4 space-y-4"
      @submit.prevent="confirm"
    >
      <UFormField
        label="Authentication code"
        name="code"
        :error="error ?? undefined"
      >
        <UPinInput
          ref="pinInput"
          v-model="pin"
          :length="6"
          type="number"
          otp
          autofocus
          size="xl"
          placeholder="○"
          aria-label="Authentication code"
          @complete="confirm"
        />
      </UFormField>
      <UButton
        type="submit"
        label="Continue"
        :loading="loading"
      />
    </form>
    <p class="mt-4 text-xs text-muted">
      Lost your phone? A recovery code still logs you in, but the admin area needs a code from the app.
    </p>
  </UCard>

  <UAlert
    v-else-if="failure.reason === 'email_unverified'"
    color="warning"
    variant="subtle"
    icon="i-lucide-mail-warning"
    title="Verify your email to use the admin area"
    description="Use the link we emailed you, or resend it from the banner at the top."
  />

  <UAlert
    v-else
    color="error"
    variant="subtle"
    title="Could not load this page"
    :description="failure.message"
  >
    <template #actions>
      <UButton
        label="Retry"
        color="neutral"
        variant="outline"
        size="sm"
        @click="emit('retry')"
      />
    </template>
  </UAlert>
</template>
