<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent, TableColumn } from '@nuxt/ui'
import type { Device, User } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Account settings' })

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const { sending: resending, resend } = useEmailVerification()

/** Submit a form to the API: field errors go on the form, anything else to a toast. */
async function submit<T>(form: { setErrors: (errors: FormError[]) => void } | null | undefined, failTitle: string, request: () => Promise<T>): Promise<T | undefined> {
  try {
    return await request()
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      form?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: failTitle, description: errorMessage(error), color: 'error' })
    }
  }
}

// Profile
interface ProfileState { name: string }
const profileForm = useTemplateRef<Form<ProfileState>>('profileForm')
const profileState = reactive<ProfileState>({ name: auth.user?.name ?? '' })
const savingProfile = ref(false)

function validateProfile(state: Partial<ProfileState>): FormError[] {
  return state.name?.trim() ? [] : [{ name: 'name', message: 'Enter your name.' }]
}

async function saveProfile(event: FormSubmitEvent<ProfileState>) {
  savingProfile.value = true
  const response = await submit(profileForm.value, 'Could not save your name', () =>
    api<{ data: User }>('/api/account', { method: 'PATCH', body: event.data }))
  savingProfile.value = false

  if (response) {
    auth.setUser(response.data)
    toast.add({ title: 'Name saved', color: 'success' })
  }
}

// Email
interface EmailState { email: string, current_password: string }
const emailForm = useTemplateRef<Form<EmailState>>('emailForm')
const emailState = reactive<EmailState>({ email: auth.user?.email ?? '', current_password: '' })
const savingEmail = ref(false)

function validateEmail(state: Partial<EmailState>): FormError[] {
  const errors: FormError[] = []
  if (!state.email) errors.push({ name: 'email', message: 'Enter an email address.' })
  if (!state.current_password) errors.push({ name: 'current_password', message: 'Enter your current password.' })
  return errors
}

async function saveEmail(event: FormSubmitEvent<EmailState>) {
  savingEmail.value = true
  const body = { ...event.data, email: event.data.email.trim().toLowerCase() }
  const response = await submit(emailForm.value, 'Could not change your email', () =>
    api<{ data: User }>('/api/account/email', { method: 'PUT', body }))
  savingEmail.value = false

  if (response) {
    const changed = response.data.email !== auth.user?.email
    auth.setUser(response.data)
    emailState.email = response.data.email
    emailState.current_password = ''
    if (changed) {
      await refreshDevices()
    }
    toast.add(changed
      ? { title: 'Email changed', description: `We sent a verification link to ${response.data.email} and signed out your other devices.`, color: 'success' }
      : { title: 'That is already your email', color: 'neutral' })
  }
}

// Password
interface PasswordState { current_password: string, password: string, password_confirmation: string }
const passwordForm = useTemplateRef<Form<PasswordState>>('passwordForm')
const passwordState = reactive<PasswordState>({ current_password: '', password: '', password_confirmation: '' })
const savingPassword = ref(false)

function validatePassword(state: Partial<PasswordState>): FormError[] {
  const errors: FormError[] = []
  if (!state.current_password) errors.push({ name: 'current_password', message: 'Enter your current password.' })
  if (!state.password || state.password.length < 8) errors.push({ name: 'password', message: 'Use at least 8 characters.' })
  if (state.password !== state.password_confirmation) errors.push({ name: 'password_confirmation', message: 'Passwords do not match.' })
  return errors
}

async function savePassword(event: FormSubmitEvent<PasswordState>) {
  savingPassword.value = true
  const done = await submit(passwordForm.value, 'Could not change your password', () =>
    api('/api/account/password', { method: 'PUT', body: event.data }).then(() => true))
  savingPassword.value = false

  if (done) {
    Object.assign(passwordState, { current_password: '', password: '', password_confirmation: '' })
    toast.add({ title: 'Password changed', description: 'Your other devices were signed out.', color: 'success' })
    await refreshDevices()
  }
}

// Devices
const { data: devices, status: devicesStatus, error: devicesError, refresh: refreshDevices } = await useAsyncData(
  'account-devices',
  () => api<{ data: Device[] }>('/api/account/devices').then(response => response.data),
  { default: () => [] },
)

const deviceColumns: TableColumn<Device>[] = [
  { accessorKey: 'name', header: 'Device', meta: { class: { td: 'whitespace-normal' } } },
  // On phones these show under the device name instead.
  { accessorKey: 'ip_address', header: 'IP address', meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } } },
  { accessorKey: 'last_used_at', header: 'Last active', meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } } },
  { id: 'actions', header: () => h('span', { class: 'sr-only' }, 'Actions') },
]

const formatDateTime = (value: string) =>
  `${new Date(value).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })} UTC`

const pendingRevoke = ref<Device | null>(null)
const revoking = ref(false)
const revokeOpen = computed({
  get: () => pendingRevoke.value !== null,
  set: (open: boolean) => {
    if (!open) pendingRevoke.value = null
  },
})

async function confirmRevoke() {
  const device = pendingRevoke.value
  if (!device) return

  revoking.value = true
  try {
    await api(`/api/account/devices/${device.id}`, { method: 'DELETE' })
    pendingRevoke.value = null

    if (device.is_current) {
      // The token is gone already: just clean up here.
      auth.clearSession()
      await navigateTo('/login')
      return
    }

    toast.add({ title: `${device.name} was signed out`, color: 'success' })
    await refreshDevices()
  }
  catch (error) {
    toast.add({ title: 'Could not sign out the device', description: errorMessage(error), color: 'error' })
  }
  finally {
    revoking.value = false
  }
}

// Delete account
interface DeleteState { password: string }
const deleteForm = useTemplateRef<Form<DeleteState>>('deleteForm')
const deleteState = reactive<DeleteState>({ password: '' })
const deleteOpen = ref(false)
const deleting = ref(false)
// Organizations where the user is the last owner (from the API's 422).
const deleteBlocked = ref<string | null>(null)

watch(deleteOpen, (open) => {
  if (!open) {
    deleteState.password = ''
    deleteBlocked.value = null
  }
})

function validateDelete(state: Partial<DeleteState>): FormError[] {
  return state.password ? [] : [{ name: 'password', message: 'Enter your password.' }]
}

async function deleteAccount(event: FormSubmitEvent<DeleteState>) {
  deleting.value = true
  deleteBlocked.value = null
  try {
    await api('/api/account', { method: 'DELETE', body: event.data })
    deleteOpen.value = false
    auth.clearSession()
    toast.add({ title: 'Your account was deleted', color: 'success' })
    await navigateTo('/login')
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    const blocked = fieldErrors.find(fieldError => fieldError.name === 'account')
    if (blocked) {
      deleteBlocked.value = blocked.message ?? null
    }
    else if (fieldErrors.length) {
      deleteForm.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not delete your account', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold">
        Account settings
      </h1>
      <p class="text-sm text-muted">
        Your own profile, login, two-factor and devices, in every organization.
      </p>
    </div>

    <UPageCard
      title="Profile"
      description="The name other members see."
      variant="subtle"
    >
      <UForm
        ref="profileForm"
        :state="profileState"
        :validate="validateProfile"
        class="flex flex-col gap-4 sm:flex-row sm:items-start"
        @submit="saveProfile"
      >
        <UFormField
          label="Name"
          name="name"
          class="sm:max-w-sm sm:flex-1"
        >
          <UInput
            v-model="profileState.name"
            autocomplete="name"
            class="w-full"
          />
        </UFormField>
        <UButton
          type="submit"
          label="Save"
          :loading="savingProfile"
          class="self-start sm:mt-6"
        />
      </UForm>
    </UPageCard>

    <UPageCard
      title="Email"
      description="You log in with it, and invitations are sent to it. A new address needs to be verified, and changing it signs you out on every other device."
      variant="subtle"
    >
      <UAlert
        v-if="auth.user && !auth.user.email_verified"
        color="warning"
        variant="subtle"
        icon="i-lucide-mail-warning"
        :title="`${auth.user.email} is not verified yet`"
        description="Use the link we emailed you. Until then you can't invite people, use AI or manage billing."
      >
        <template #actions>
          <UButton
            label="Resend email"
            color="neutral"
            variant="outline"
            size="sm"
            :loading="resending"
            @click="resend"
          />
        </template>
      </UAlert>

      <UForm
        ref="emailForm"
        :state="emailState"
        :validate="validateEmail"
        class="grid gap-4 sm:max-w-sm"
        @submit="saveEmail"
      >
        <UFormField
          label="Email"
          name="email"
        >
          <UInput
            v-model="emailState.email"
            type="email"
            autocomplete="email"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="Current password"
          name="current_password"
        >
          <UInput
            v-model="emailState.current_password"
            type="password"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
        <UButton
          type="submit"
          label="Change email"
          :loading="savingEmail"
          class="justify-self-start"
        />
      </UForm>
    </UPageCard>

    <UPageCard
      title="Password"
      description="Changing it signs you out on every other device."
      variant="subtle"
    >
      <UForm
        ref="passwordForm"
        :state="passwordState"
        :validate="validatePassword"
        class="grid gap-4 sm:max-w-sm"
        @submit="savePassword"
      >
        <UFormField
          label="Current password"
          name="current_password"
        >
          <UInput
            v-model="passwordState.current_password"
            type="password"
            autocomplete="current-password"
            class="w-full"
          />
        </UFormField>
        <UFormField
          label="New password"
          name="password"
        >
          <UInput
            v-model="passwordState.password"
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
            v-model="passwordState.password_confirmation"
            type="password"
            autocomplete="new-password"
            class="w-full"
          />
        </UFormField>
        <UButton
          type="submit"
          label="Change password"
          :loading="savingPassword"
          class="justify-self-start"
        />
      </UForm>
    </UPageCard>

    <TwoFactorSettings @enabled="refreshDevices" />

    <UPageCard
      title="Devices"
      description="Where you're logged in. Sign out a device you don't recognize, then change your password."
      variant="subtle"
      class="min-w-0"
      :ui="{ container: 'min-w-0' }"
    >
      <UAlert
        v-if="devicesError"
        color="error"
        variant="subtle"
        title="Could not load your devices"
        :description="errorMessage(devicesError)"
      >
        <template #actions>
          <UButton
            label="Retry"
            color="neutral"
            variant="outline"
            size="sm"
            @click="() => refreshDevices()"
          />
        </template>
      </UAlert>

      <UTable
        v-else
        :data="devices"
        :columns="deviceColumns"
        :loading="devicesStatus === 'pending'"
        empty="No devices."
      >
        <template #name-cell="{ row }">
          <span class="font-medium">{{ row.original.name }}</span>
          <UBadge
            v-if="row.original.is_current"
            label="This device"
            color="primary"
            variant="subtle"
            size="sm"
            class="ms-2"
          />
          <p class="text-xs text-muted sm:hidden">
            {{ row.original.last_used_at ? formatDateTime(row.original.last_used_at) : 'Not used yet' }}
            <template v-if="row.original.ip_address">
              · {{ row.original.ip_address }}
            </template>
          </p>
        </template>
        <template #ip_address-cell="{ row }">
          {{ row.original.ip_address ?? '—' }}
        </template>
        <template #last_used_at-cell="{ row }">
          {{ row.original.last_used_at ? formatDateTime(row.original.last_used_at) : 'Not used yet' }}
        </template>
        <template #actions-cell="{ row }">
          <div class="flex justify-end">
            <UButton
              :label="row.original.is_current ? 'Log out' : 'Sign out'"
              color="neutral"
              variant="ghost"
              size="sm"
              :aria-label="`Sign out ${row.original.name}${row.original.is_current ? ' (this device)' : ''}`"
              @click="pendingRevoke = row.original"
            />
          </div>
        </template>
      </UTable>
    </UPageCard>

    <UPageCard
      title="Delete account"
      description="Deletes your account and the organizations where you're the only member, with their data. You leave your other organizations. This can't be undone."
      variant="subtle"
      highlight
      highlight-color="error"
    >
      <UButton
        label="Delete account"
        color="error"
        variant="outline"
        class="self-start"
        @click="deleteOpen = true"
      />
    </UPageCard>

    <UModal
      v-model:open="revokeOpen"
      :title="pendingRevoke?.is_current ? 'Log out of this device?' : `Sign out ${pendingRevoke?.name}?`"
      :description="pendingRevoke?.is_current ? 'You will need to log in again here.' : 'That device will need to log in again.'"
    >
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Cancel"
            @click="revokeOpen = false"
          />
          <UButton
            color="error"
            :label="pendingRevoke?.is_current ? 'Log out' : 'Sign out'"
            :loading="revoking"
            @click="confirmRevoke"
          />
        </div>
      </template>
    </UModal>

    <UModal
      v-model:open="deleteOpen"
      title="Delete your account?"
      description="Enter your password to confirm. This can't be undone."
    >
      <template #body>
        <UForm
          ref="deleteForm"
          :state="deleteState"
          :validate="validateDelete"
          class="space-y-4"
          @submit="deleteAccount"
        >
          <UAlert
            v-if="deleteBlocked"
            color="error"
            variant="subtle"
            icon="i-lucide-shield-alert"
            title="You can't delete your account yet"
            :description="deleteBlocked"
            role="alert"
          />

          <UFormField
            label="Password"
            name="password"
          >
            <UInput
              v-model="deleteState.password"
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
              @click="deleteOpen = false"
            />
            <UButton
              type="submit"
              color="error"
              label="Delete account"
              :loading="deleting"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
