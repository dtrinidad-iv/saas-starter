<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import type { LoginPayload } from '~/types/api'

definePageMeta({ layout: 'auth', middleware: 'guest' })
useSeoMeta({ title: 'Log in' })

const auth = useAuthStore()
const afterLogin = useAfterLogin()
const toast = useToast()
const route = useRoute()

const form = useTemplateRef<Form<LoginPayload>>('form')
const state = reactive<LoginPayload>({ email: '', password: '' })
const loading = ref(false)

function validate(state: Partial<LoginPayload>): FormError[] {
  const errors: FormError[] = []
  if (!state.email) errors.push({ name: 'email', message: 'Enter your email.' })
  if (!state.password) errors.push({ name: 'password', message: 'Enter your password.' })
  return errors
}

async function onSubmit(event: FormSubmitEvent<LoginPayload>) {
  loading.value = true
  try {
    await auth.login(event.data)
    await navigateTo(await afterLogin(safeRedirect(route.query.redirect)))
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      form.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not log in', description: errorMessage(error), color: 'error' })
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
        Log in
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
        <template #hint>
          <ULink
            to="/forgot-password"
            class="text-primary text-sm"
          >
            Forgot password?
          </ULink>
        </template>
        <UInput
          v-model="state.password"
          type="password"
          autocomplete="current-password"
          class="w-full"
        />
      </UFormField>

      <UButton
        type="submit"
        label="Log in"
        :loading="loading"
        block
      />
    </UForm>

    <template #footer>
      <p class="text-center text-sm text-muted">
        No account yet?
        <ULink
          :to="{ path: '/register', query: route.query }"
          class="text-primary font-medium"
        >
          Create one
        </ULink>
      </p>
    </template>
  </UCard>
</template>
