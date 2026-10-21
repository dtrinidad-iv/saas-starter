<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth', middleware: 'guest' })
useSeoMeta({ title: 'Forgot password' })

const api = useApi()
const toast = useToast()

interface ForgotState { email: string }
const form = useTemplateRef<Form<ForgotState>>('form')
const state = reactive<ForgotState>({ email: '' })
const loading = ref(false)
// The API answers the same whether or not the account exists.
const sentMessage = ref<string | null>(null)

function validate(state: Partial<ForgotState>): FormError[] {
  return state.email ? [] : [{ name: 'email', message: 'Enter your email.' }]
}

async function onSubmit(event: FormSubmitEvent<ForgotState>) {
  loading.value = true
  try {
    const response = await api<{ message: string }>('/api/forgot-password', { method: 'POST', body: event.data })
    sentMessage.value = response.message
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      form.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not send the link', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <h1 class="text-xl font-semibold">
        Forgot password
      </h1>
    </template>

    <UAlert
      v-if="sentMessage"
      color="success"
      variant="subtle"
      icon="i-lucide-mail-check"
      title="Check your email"
      :description="`${sentMessage} The link works for 60 minutes.`"
      role="status"
    />

    <UForm
      v-else
      ref="form"
      :state="state"
      :validate="validate"
      class="space-y-4"
      @submit="onSubmit"
    >
      <p class="text-sm text-muted">
        Enter the email you log in with and we'll send you a link to choose a new password.
      </p>

      <UFormField
        label="Email"
        name="email"
      >
        <UInput
          v-model="state.email"
          type="email"
          autocomplete="email"
          class="w-full"
        />
      </UFormField>

      <UButton
        type="submit"
        label="Send reset link"
        :loading="loading"
        block
      />
    </UForm>

    <template #footer>
      <p class="text-center text-sm text-muted">
        Remembered it?
        <ULink
          to="/login"
          class="text-primary font-medium"
        >
          Log in
        </ULink>
      </p>
    </template>
  </UCard>
</template>
