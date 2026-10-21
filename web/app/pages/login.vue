<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import type { RouteLocationRaw } from 'vue-router'
import type { LoginPayload } from '~/types/api'

definePageMeta({ layout: 'auth', middleware: 'guest' })
useSeoMeta({ title: 'Log in' })

const auth = useAuthStore()
const organizations = useOrganizationStore()
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

// Someone in several organizations chooses one first. Not for an invitation link:
// accepting it sets the organization anyway.
async function afterLogin(redirect: string): Promise<RouteLocationRaw> {
  try {
    // The auth plugin only loads organizations on startup, not after this login.
    await organizations.fetch()
  }
  catch (error) {
    console.error('Could not load organizations', error)
    return redirect
  }

  // Already headed to the picker (e.g. it was opened while logged out): don't nest it.
  if (redirect.startsWith('/select-organization')) {
    return redirect
  }

  if (organizations.organizations.length > 1 && !redirect.startsWith('/join/')) {
    return { path: '/select-organization', query: { redirect } }
  }

  return redirect
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
