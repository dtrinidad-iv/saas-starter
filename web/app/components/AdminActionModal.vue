<script setup lang="ts" generic="T">
import type { Form, FormError } from '@nuxt/ui'

// A platform admin action: says what will happen, asks why (for the platform log),
// and runs it (`action` posts the reason). When the API wants a fresher code (two_factor_reauth), it asks for
// one here and then sends the action again. Pages mount it (v-if) for each action,
// so it starts empty every time.
const props = defineProps<{
  title: string
  description: string
  confirmLabel: string
  action: (reason: string) => Promise<T>
  danger?: boolean
}>()
const emit = defineEmits<{ done: [data: T] }>()
const open = defineModel<boolean>('open', { default: false })

const state = reactive({ reason: '' })
const step = ref<'reason' | 'code'>('reason')
const error = ref<string | null>(null)
const loading = ref(false)
const form = useTemplateRef<Form<{ reason: string }>>('form')

function validate(data: typeof state): FormError[] {
  const length = data.reason.trim().length
  if (length < 10) return [{ name: 'reason', message: 'Write at least 10 characters.' }]
  if (length > 500) return [{ name: 'reason', message: 'Keep it under 500 characters.' }]
  return []
}

async function send() {
  if (loading.value) return
  loading.value = true
  error.value = null
  try {
    emit('done', await props.action(state.reason.trim()))
    open.value = false
  }
  catch (caught) {
    if (isFetchError(caught) && caught.statusCode === 403 && caught.data?.code === 'two_factor_reauth') {
      step.value = 'code'
      return
    }
    step.value = 'reason'
    await nextTick() // the reason form is mounted again before its errors are set
    const fieldErrors = toFormErrors(caught)
    const reasonErrors = fieldErrors.filter(fieldError => fieldError.name === 'reason')
    form.value?.setErrors(reasonErrors)
    // Anything else (e.g. "already suspended") isn't about the reason field.
    error.value = fieldErrors.find(fieldError => fieldError.name !== 'reason')?.message
      ?? (reasonErrors.length ? null : errorMessage(caught))
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    :title="title"
    :description="step === 'code' ? 'Enter a code from your authenticator app to finish this.' : description"
  >
    <template #body>
      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        :description="error"
        class="mb-4"
      />

      <TwoFactorReconfirmForm
        v-if="step === 'code'"
        :submit-label="confirmLabel"
        :busy="loading"
        @confirmed="send"
      />

      <UForm
        v-else
        ref="form"
        :state="state"
        :validate="validate"
        class="space-y-4"
        @submit="send"
      >
        <UFormField
          label="Reason"
          name="reason"
          help="For the platform log: a ticket number or what the user asked for. They don't see it."
          required
        >
          <UTextarea
            v-model="state.reason"
            :rows="3"
            :maxlength="500"
            autofocus
            class="w-full"
          />
        </UFormField>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="ghost"
            @click="open = false"
          />
          <UButton
            type="submit"
            :label="confirmLabel"
            :color="danger ? 'error' : 'primary'"
            :loading="loading"
          />
        </div>
      </UForm>
    </template>
  </UModal>
</template>
