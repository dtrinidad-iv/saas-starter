<script setup lang="ts">
import type { FormError } from '@nuxt/ui'

// The second step of logging in (or of a password reset) when two-factor is on.
const props = defineProps<{ challenge: string }>()
const emit = defineEmits<{
  success: []
  // The challenge is gone (5 minutes, or 5 wrong codes): start again with the password.
  expired: [message: string]
  back: []
}>()

const auth = useAuthStore()
const toast = useToast()

const useRecovery = ref(false)
const pin = ref<number[]>([])
const pinInput = useTemplateRef<{ inputsRef: { $el: HTMLElement }[] }>('pinInput')

// After a wrong code: empty boxes, typing again from the first one.
async function clearPin() {
  pin.value = []
  await nextTick()
  pinInput.value?.inputsRef[0]?.$el.focus()
}
const recoveryCode = ref('')
const error = ref<string | null>(null)
const loading = ref(false)

watch(useRecovery, () => {
  error.value = null
  pin.value = []
  recoveryCode.value = ''
})

// Once logged in, a late second submit (Enter right after the 6th digit) must not
// report the used-up challenge as expired.
const done = ref(false)

async function submit() {
  if (loading.value || done.value) return
  const code = pin.value.join('')
  if (!useRecovery.value && code.length !== 6) {
    error.value = 'Enter the 6-digit code.'
    return
  }
  if (useRecovery.value && !recoveryCode.value.trim()) {
    error.value = 'Enter a recovery code.'
    return
  }

  loading.value = true
  error.value = null
  try {
    await auth.completeTwoFactor(props.challenge, useRecovery.value ? { recovery_code: recoveryCode.value.trim() } : { code })
    done.value = true
    emit('success')
  }
  catch (caught) {
    const fieldErrors: FormError[] = toFormErrors(caught)
    const expired = fieldErrors.find(fieldError => fieldError.name === 'challenge')
    if (expired) {
      emit('expired', expired.message ?? 'This login has expired.')
    }
    else if (fieldErrors.length) {
      error.value = fieldErrors[0]?.message ?? "That code isn't right."
      await clearPin()
    }
    else {
      toast.add({ title: 'Could not log in', description: errorMessage(caught), color: 'error' })
    }
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <form
    class="space-y-4"
    @submit.prevent="submit"
  >
    <template v-if="!useRecovery">
      <p class="text-sm text-muted">
        Enter the 6-digit code from your authenticator app.
      </p>
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
          @complete="submit"
        />
      </UFormField>
    </template>

    <template v-else>
      <p class="text-sm text-muted">
        Enter one of the recovery codes you saved when you turned on two-factor. Each works once.
      </p>
      <UFormField
        label="Recovery code"
        name="recovery_code"
        :error="error ?? undefined"
      >
        <UInput
          v-model="recoveryCode"
          autocomplete="one-time-code"
          placeholder="xxxxx-xxxxx"
          autofocus
          class="w-full"
        />
      </UFormField>
    </template>

    <UButton
      type="submit"
      label="Verify"
      :loading="loading"
      block
    />

    <div class="flex flex-wrap justify-between gap-2 text-sm">
      <UButton
        variant="link"
        color="neutral"
        :label="useRecovery ? 'Use the authenticator app' : 'Use a recovery code instead'"
        @click="useRecovery = !useRecovery"
      />
      <UButton
        variant="link"
        color="neutral"
        label="Back"
        icon="i-lucide-arrow-left"
        @click="emit('back')"
      />
    </div>
  </form>
</template>
