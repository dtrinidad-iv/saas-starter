<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import type { RegisterPayload } from '~/types/api'

definePageMeta({ layout: 'auth', middleware: 'guest' })
useSeoMeta({ title: 'Create account' })

const auth = useAuthStore()
const toast = useToast()

const form = useTemplateRef<Form<RegisterPayload>>('form')
const state = reactive<RegisterPayload>({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const loading = ref(false)

// Quick checks for instant feedback; the API's Form Request is the real validation.
function validate(state: Partial<RegisterPayload>): FormError[] {
  const errors: FormError[] = []
  if (!state.name) errors.push({ name: 'name', message: 'Enter your name.' })
  if (!state.email) errors.push({ name: 'email', message: 'Enter your email.' })
  if (!state.password || state.password.length < 8) {
    errors.push({ name: 'password', message: 'Use at least 8 characters.' })
  }
  if (state.password !== state.password_confirmation) {
    errors.push({ name: 'password_confirmation', message: 'Passwords do not match.' })
  }
  return errors
}

async function onSubmit(event: FormSubmitEvent<RegisterPayload>) {
  loading.value = true
  try {
    await auth.register(event.data)
    await navigateTo('/')
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      form.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not create account', description: errorMessage(error), color: 'error' })
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
        Create your account
      </h1>
    </template>

    <UForm
      ref="form"
      :state="state"
      :validate="validate"
      class="space-y-4"
      @submit="onSubmit"
    >
      <UFormField
        label="Name"
        name="name"
      >
        <UInput
          v-model="state.name"
          autocomplete="name"
          class="w-full"
        />
      </UFormField>

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

      <UFormField
        label="Password"
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
        label="Confirm password"
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
        label="Create account"
        :loading="loading"
        block
      />
    </UForm>

    <template #footer>
      <p class="text-center text-sm text-muted">
        Already have an account?
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
