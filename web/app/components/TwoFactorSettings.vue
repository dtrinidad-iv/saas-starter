<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import { renderSVG } from 'uqr'
import type { TwoFactorSetup } from '~/types/api'

// The two-factor card on the account page: turn on (password, scan, code, recovery
// codes), new recovery codes, turn off.
const emit = defineEmits<{
  // Turning it on signed out the other devices.
  enabled: []
}>()

const api = useApi()
const auth = useAuthStore()
const toast = useToast()

const enabled = computed(() => auth.user?.two_factor_enabled ?? false)

type Step = 'password' | 'setup' | 'codes'
type Purpose = 'enable' | 'disable' | 'regenerate'

const open = ref(false)
const step = ref<Step>('password')
const purpose = ref<Purpose>('enable')

const passwordForm = useTemplateRef<Form<{ current_password: string }>>('passwordForm')
const passwordState = reactive({ current_password: '' })
const setup = ref<TwoFactorSetup | null>(null)
const pin = ref<number[]>([])
const pinInput = useTemplateRef<{ inputsRef: { $el: HTMLElement }[] }>('pinInput')

// After a wrong code: empty boxes, typing again from the first one.
async function clearPin() {
  pin.value = []
  await nextTick()
  pinInput.value?.inputsRef[0]?.$el.focus()
}
const codeError = ref<string | null>(null)
const recoveryCodes = ref<string[]>([])
const loading = ref(false)

// Drawn in the browser, so the secret never goes to a QR service.
const qrSrc = computed(() => setup.value
  ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(renderSVG(setup.value.otpauth_uri, { border: 2 }))}`
  : '')
const groupedSecret = computed(() => setup.value?.secret.match(/.{1,4}/g)?.join(' ') ?? '')

const titles: Record<Step, Record<Purpose, string>> = {
  password: { enable: 'Turn on two-factor authentication', disable: 'Turn off two-factor authentication?', regenerate: 'New recovery codes' },
  setup: { enable: 'Scan the QR code', disable: '', regenerate: '' },
  codes: { enable: 'Save your recovery codes', disable: '', regenerate: 'Save your recovery codes' },
}
const descriptions: Record<Purpose, string> = {
  enable: 'Enter your password to start.',
  disable: "Enter your password. Logging in will only need it, which makes your account easier to take over.",
  regenerate: 'Enter your password. Your old recovery codes stop working.',
}

const title = computed(() => titles[step.value][purpose.value])
const description = computed(() => {
  if (step.value === 'password' && purpose.value === 'disable' && auth.user?.is_platform_admin) {
    return `${descriptions.disable} You also stop being a platform admin until someone grants it again.`
  }
  if (step.value === 'password') return descriptions[purpose.value]
  if (step.value === 'setup') return 'Use an authenticator app such as Google Authenticator, 1Password or Authy, then enter the 6-digit code it shows.'
  return "Each code logs you in once if you lose your phone. They won't be shown again: store them somewhere safe, like a password manager."
})

function start(next: Purpose) {
  purpose.value = next
  step.value = 'password'
  passwordState.current_password = ''
  setup.value = null
  pin.value = []
  codeError.value = null
  recoveryCodes.value = []
  open.value = true
}

watch(open, (isOpen) => {
  if (!isOpen) {
    // Nothing secret stays in memory once the modal is closed.
    setup.value = null
    recoveryCodes.value = []
    passwordState.current_password = ''
  }
})

function validatePassword(state: Partial<{ current_password: string }>): FormError[] {
  return state.current_password ? [] : [{ name: 'current_password', message: 'Enter your password.' }]
}

async function submitPassword(event: FormSubmitEvent<{ current_password: string }>) {
  loading.value = true
  try {
    if (purpose.value === 'enable') {
      setup.value = (await api<{ data: TwoFactorSetup }>('/api/account/two-factor', { method: 'POST', body: event.data })).data
      step.value = 'setup'
    }
    else if (purpose.value === 'regenerate') {
      recoveryCodes.value = (await api<{ data: { recovery_codes: string[] } }>('/api/account/two-factor/recovery-codes', { method: 'POST', body: event.data })).data.recovery_codes
      step.value = 'codes'
    }
    else {
      await api('/api/account/two-factor', { method: 'DELETE', body: event.data })
      setEnabled(false)
      open.value = false
      toast.add({ title: 'Two-factor authentication is off', color: 'success' })
    }
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.some(fieldError => fieldError.name === 'two_factor')) {
      // Turned on or off in another tab: this card was out of date.
      open.value = false
      await auth.fetchMe()
      toast.add({ title: fieldErrors[0]?.message ?? 'Two-factor changed elsewhere', color: 'neutral' })
    }
    else if (fieldErrors.length) {
      passwordForm.value?.setErrors(fieldErrors.map(fieldError => ({ ...fieldError, name: 'current_password' })))
    }
    else {
      toast.add({ title: 'Something went wrong', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    loading.value = false
  }
}

async function confirm() {
  // @complete and the submit button can both fire.
  if (loading.value || step.value !== 'setup') return
  const code = pin.value.join('')
  if (code.length !== 6) {
    codeError.value = 'Enter the 6-digit code.'
    return
  }

  loading.value = true
  codeError.value = null
  try {
    recoveryCodes.value = (await api<{ data: { recovery_codes: string[] } }>('/api/account/two-factor/confirm', { method: 'POST', body: { code } })).data.recovery_codes
    step.value = 'codes'
    setEnabled(true)
    emit('enabled')
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      codeError.value = fieldErrors[0]?.message ?? "That code isn't right."
      await clearPin()
    }
    else {
      toast.add({ title: 'Could not turn on two-factor', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    loading.value = false
  }
}

function setEnabled(value: boolean) {
  if (auth.user) {
    auth.setUser({ ...auth.user, two_factor_enabled: value })
  }
}

async function copy(text: string, what: string) {
  try {
    await navigator.clipboard.writeText(text)
    toast.add({ title: `${what} copied`, color: 'success' })
  }
  catch {
    toast.add({ title: `Could not copy the ${what.toLowerCase()}`, description: 'Select it and copy it by hand.', color: 'error' })
  }
}

function download() {
  const text = `SaaS Starter recovery codes for ${auth.user?.email}\n\n${recoveryCodes.value.join('\n')}\n`
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }))
  const link = document.createElement('a')
  link.href = url
  link.download = 'recovery-codes.txt'
  link.click()
  // Revoking at once can cancel the download in some browsers.
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

function finish() {
  open.value = false
  if (purpose.value === 'enable') {
    toast.add({ title: 'Two-factor authentication is on', description: 'Your other devices were signed out.', color: 'success' })
  }
}
</script>

<template>
  <UPageCard
    title="Two-factor authentication"
    description="Ask for a code from an authenticator app on your phone when you log in, so a stolen password isn't enough."
    variant="subtle"
  >
    <div
      v-if="enabled"
      class="flex flex-wrap items-center gap-2"
    >
      <UBadge
        label="On"
        color="success"
        variant="subtle"
        icon="i-lucide-shield-check"
      />
      <UButton
        label="New recovery codes"
        color="neutral"
        variant="outline"
        @click="start('regenerate')"
      />
      <UButton
        label="Turn off"
        color="error"
        variant="ghost"
        @click="start('disable')"
      />
    </div>
    <UButton
      v-else
      label="Turn on"
      icon="i-lucide-shield"
      class="self-start"
      @click="start('enable')"
    />

    <UModal
      v-model:open="open"
      :title="title"
      :description="description"
      :dismissible="step !== 'codes'"
      :close="step !== 'codes'"
    >
      <template #body>
        <UForm
          v-if="step === 'password'"
          ref="passwordForm"
          :state="passwordState"
          :validate="validatePassword"
          class="space-y-4"
          @submit="submitPassword"
        >
          <UFormField
            label="Password"
            name="current_password"
          >
            <UInput
              v-model="passwordState.current_password"
              type="password"
              autocomplete="current-password"
              autofocus
              class="w-full"
            />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Cancel"
              @click="open = false"
            />
            <UButton
              type="submit"
              :color="purpose === 'disable' ? 'error' : 'primary'"
              :label="purpose === 'disable' ? 'Turn off' : 'Continue'"
              :loading="loading"
            />
          </div>
        </UForm>

        <form
          v-else-if="step === 'setup' && setup"
          class="space-y-4"
          @submit.prevent="confirm"
        >
          <div class="flex flex-col items-center gap-3 sm:flex-row sm:items-start">
            <img
              :src="qrSrc"
              alt="QR code for your authenticator app"
              class="size-44 shrink-0 rounded-md bg-white p-1"
            >
            <div class="min-w-0 space-y-2 text-sm">
              <p class="text-muted">
                Can't scan it? Enter this key in the app instead:
              </p>
              <code class="block break-all rounded-md bg-elevated px-2 py-1 font-mono text-sm">{{ groupedSecret }}</code>
              <UButton
                label="Copy key"
                icon="i-lucide-copy"
                color="neutral"
                variant="outline"
                size="sm"
                @click="copy(setup.secret, 'Key')"
              />
            </div>
          </div>

          <UFormField
            label="Code from the app"
            name="code"
            :error="codeError ?? undefined"
          >
            <UPinInput
          ref="pinInput"
              v-model="pin"
              :length="6"
              type="number"
              otp
              placeholder="○"
              aria-label="Code from the app"
              @complete="confirm"
            />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Cancel"
              @click="open = false"
            />
            <UButton
              type="submit"
              label="Turn on"
              :loading="loading"
            />
          </div>
        </form>

        <div
          v-else-if="step === 'codes'"
          class="space-y-4"
        >
          <ul
            class="grid grid-cols-2 gap-2 rounded-md bg-elevated p-3 font-mono text-sm"
            aria-label="Recovery codes"
          >
            <li
              v-for="code in recoveryCodes"
              :key="code"
            >
              {{ code }}
            </li>
          </ul>
          <div class="flex flex-wrap justify-between gap-2">
            <div class="flex gap-2">
              <UButton
                label="Copy"
                icon="i-lucide-copy"
                color="neutral"
                variant="outline"
                @click="copy(recoveryCodes.join('\n'), 'Recovery codes')"
              />
              <UButton
                label="Download"
                icon="i-lucide-download"
                color="neutral"
                variant="outline"
                @click="download"
              />
            </div>
            <UButton
              label="I've saved them"
              @click="finish"
            />
          </div>
        </div>
      </template>
    </UModal>
  </UPageCard>
</template>
