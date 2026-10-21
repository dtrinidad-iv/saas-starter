<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth', middleware: 'guest' })
useSeoMeta({ title: 'Choose a new password' })

const auth = useAuthStore()
const afterLogin = useAfterLogin()
const toast = useToast()
const route = useRoute()

// From the emailed link.
const token = computed(() => typeof route.query.token === 'string' ? route.query.token : '')
const email = computed(() => typeof route.query.email === 'string' ? route.query.email : '')

interface ResetState { password: string, password_confirmation: string }
const form = useTemplateRef<Form<ResetState>>('form')
const state = reactive<ResetState>({ password: '', password_confirmation: '' })
const loading = ref(false)
const linkError = ref<string | null>(null)

function validate(state: Partial<ResetState>): FormError[] {
  const errors: FormError[] = []
  if (!state.password || state.password.length < 8) {
    errors.push({ name: 'password', message: 'Use at least 8 characters.' })
  }
  if (state.password !== state.password_confirmation) {
    errors.push({ name: 'password_confirmation', message: 'Passwords do not match.' })
  }
  return errors
}

async function onSubmit(event: FormSubmitEvent<ResetState>) {
  loading.value = true
  linkError.value = null
  try {
    await auth.resetPassword({ ...event.data, token: token.value, email: email.value })
    toast.add({ title: 'Password changed', description: "You're logged in, and signed out everywhere else.", color: 'success' })
    await navigateTo(await afterLogin('/'))
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    // A bad or expired token comes back on the email field, which isn't on this form.
    const tokenError = fieldErrors.find(fieldError => fieldError.name === 'email' || fieldError.name === 'token')
    if (tokenError) {
      linkError.value = tokenError.message ?? null
    }
    else if (fieldErrors.length) {
      form.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not change the password', description: errorMessage(error), color: 'error' })
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
        Choose a new password
      </h1>
    </template>

    <UAlert
      v-if="!token || !email"
      color="error"
      variant="subtle"
      icon="i-lucide-link-2-off"
      title="This link is incomplete"
      description="Open the link from the email again, or ask for a new one."
    />

    <div
      v-else
      class="space-y-4"
    >
      <UAlert
        v-if="linkError"
        color="error"
        variant="subtle"
        icon="i-lucide-link-2-off"
        :title="linkError"
        description="Reset links work once, for 60 minutes. Ask for a new one below."
        role="alert"
      />

      <UForm
        ref="form"
        :state="state"
        :validate="validate"
        class="space-y-4"
        @submit="onSubmit"
      >
        <p class="text-sm text-muted">
          For {{ email }}. This signs you out on every other device.
        </p>

        <UFormField
          label="New password"
          name="password"
        >
          <UInput
            v-model="state.password"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Confirm new password"
          name="password_confirmation"
        >
          <UInput
            v-model="state.password_confirmation"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>

        <UButton
          type="submit"
          label="Change password"
          :loading="loading"
          block
        />
      </UForm>
    </div>

    <template #footer>
      <p class="text-center text-sm text-muted">
        <ULink
          to="/forgot-password"
          class="text-primary font-medium"
        >
          Send a new link
        </ULink>
      </p>
    </template>
  </UCard>
</template>
