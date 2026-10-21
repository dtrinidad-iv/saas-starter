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
// Set when the password was right and two-factor is on: the second step shows.
const challenge = ref<string | null>(null)
const expiredMessage = ref<string | null>(null)

async function finish() {
  await navigateTo(await afterLogin(safeRedirect(route.query.redirect)))
}

function restart(message: string | null = null) {
  challenge.value = null
  state.password = ''
  expiredMessage.value = message
}

function validate(state: Partial<LoginPayload>): FormError[] {
  const errors: FormError[] = []
  if (!state.email) errors.push({ name: 'email', message: 'Enter your email.' })
  if (!state.password) errors.push({ name: 'password', message: 'Enter your password.' })
  return errors
}

async function onSubmit(event: FormSubmitEvent<LoginPayload>) {
  loading.value = true
  expiredMessage.value = null
  try {
    challenge.value = await auth.login(event.data)
    if (!challenge.value) {
      await finish()
    }
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
        {{ challenge ? 'Two-factor authentication' : 'Log in' }}
      </h1>
    </template>

    <TwoFactorChallengeForm
      v-if="challenge"
      :challenge="challenge"
      @success="finish"
      @expired="restart"
      @back="restart()"
    />

    <UForm
      v-else
      ref="form"
      :state="state"
      :validate="validate"
      class="space-y-4"
      @submit="onSubmit"
    >
      <UAlert
        v-if="expiredMessage"
        color="warning"
        variant="subtle"
        icon="i-lucide-clock"
        :title="expiredMessage"
        role="alert"
      />

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

    <template
      v-if="!challenge"
      #footer
    >
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
