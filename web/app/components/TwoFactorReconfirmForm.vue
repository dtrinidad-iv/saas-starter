<script setup lang="ts">
// A 6-digit code from the authenticator app, posted to reconfirm so this token can
// use the admin area again (AdminGate) or finish an admin action (AdminActionModal).
// busy: the caller is still working after `confirmed` (e.g. retrying an action).
const props = defineProps<{ submitLabel?: string, busy?: boolean }>()
const emit = defineEmits<{ confirmed: [] }>()

const api = useApi()

const pin = ref<number[]>([])
const pinInput = useTemplateRef<{ inputsRef: { $el: HTMLElement }[] }>('pinInput')
const error = ref<string | null>(null)
const loading = ref(false)

async function confirm() {
  if (loading.value || props.busy) return
  const code = pin.value.join('')
  if (code.length !== 6) {
    error.value = 'Enter the 6-digit code.'
    return
  }

  loading.value = true
  error.value = null
  try {
    await api('/api/account/two-factor/reconfirm', { method: 'POST', body: { code } })
    emit('confirmed')
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
  <form
    class="space-y-4"
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
      :label="submitLabel ?? 'Continue'"
      :loading="loading || busy"
    />
  </form>
</template>
