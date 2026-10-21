<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminUserDetail } from '~/types/api'

definePageMeta({ middleware: 'auth' })

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const id = String(route.params.id)

const { data: user, failure, refresh, update } = await useAdminData(`admin-user:${id}`, async () => {
  if (!/^\d+$/.test(id)) {
    throw createError({ statusCode: 404 })
  }
  return (await api<{ data: AdminUserDetail }>(`/api/admin/users/${id}`)).data
})
useSeoMeta({ title: () => `${user.value?.name ?? 'User'} · Admin` })

interface UserAction {
  key: string
  label: string
  icon: string
  title: string
  description: string
  confirmLabel: string
  path: string
  danger: boolean
  done: string
}

// What an admin can do to this user. None for platform admins (yourself included):
// they're managed from the command line.
const actions = computed<UserAction[]>(() => {
  const target = user.value
  if (!target || target.is_platform_admin) return []

  const list: UserAction[] = []
  if (target.two_factor_enabled) {
    list.push({
      key: 'reset-two-factor',
      label: 'Reset two-factor',
      icon: 'i-lucide-shield-off',
      title: 'Reset two-factor?',
      description: `${target.name} will be signed out of every device and can log in with just their password, then set up two-factor again. They get an email about it.`,
      confirmLabel: 'Reset two-factor',
      path: 'two-factor/reset',
      danger: true,
      done: 'Two-factor was reset',
    })
  }
  if (target.devices_count > 0) {
    list.push({
      key: 'sign-out',
      label: 'Sign out everywhere',
      icon: 'i-lucide-log-out',
      title: 'Sign out everywhere?',
      description: `${target.name} will be signed out of all ${target.devices_count} devices and needs to log in again.`,
      confirmLabel: 'Sign out everywhere',
      path: 'sign-out',
      danger: false,
      done: 'Signed out of every device',
    })
  }
  list.push(target.suspended_at
    ? {
        key: 'unsuspend',
        label: 'Unsuspend',
        icon: 'i-lucide-user-check',
        title: 'Unsuspend this user?',
        description: `${target.name} can log in again.`,
        confirmLabel: 'Unsuspend',
        path: 'unsuspend',
        danger: false,
        done: 'Unsuspended',
      }
    : {
        key: 'suspend',
        label: 'Suspend',
        icon: 'i-lucide-user-x',
        title: 'Suspend this user?',
        description: `${target.name} will be signed out of every device and can't log in or reset their password until you unsuspend them. Their organizations and data stay as they are.`,
        confirmLabel: 'Suspend',
        path: 'suspend',
        danger: true,
        done: 'Suspended',
      })
  return list
})

const pending = ref<UserAction | null>(null)
const modalOpen = computed({
  get: () => pending.value !== null,
  set: (open: boolean) => {
    if (!open) pending.value = null
  },
})

// The modal runs the chosen action (it's only shown while one is chosen).
async function runPending(reason: string) {
  const action = pending.value
  if (!action) throw new Error('No action chosen.')
  const response = await api<{ data: AdminUserDetail }>(`/api/admin/users/${id}/${action.path}`, { method: 'POST', body: { reason } })
  return response.data
}

function onDone(updated: AdminUserDetail) {
  update(updated)
  toast.add({ title: `${pending.value?.done ?? 'Done'}: ${updated.name}`, color: 'success' })
}

type Membership = AdminUserDetail['organizations'][number]
const columns: TableColumn<Membership>[] = [
  { accessorKey: 'name', header: 'Organization' },
  { id: 'role', header: 'Role' },
  { accessorKey: 'joined_at', header: 'Joined (UTC)', meta: { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } } },
]

const formatDate = (value: string) =>
  new Date(value).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })
const formatDateTime = (value: string) =>
  new Date(value).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })
</script>

<template>
  <div class="space-y-6">
    <UButton
      to="/admin/users"
      label="Users"
      icon="i-lucide-arrow-left"
      color="neutral"
      variant="link"
      class="-ms-2.5"
    />

    <AdminGate
      v-if="failure"
      :failure="failure"
      @retry="() => refresh()"
    />

    <template v-else-if="user">
      <div>
        <h1 class="text-2xl font-semibold break-words">
          {{ user.name }}
        </h1>
        <p class="text-sm break-all text-muted">
          {{ user.email }}
        </p>
        <div class="mt-2 flex flex-wrap gap-1">
          <UBadge
            :label="user.email_verified ? 'Email verified' : 'Email not verified'"
            :color="user.email_verified ? 'success' : 'warning'"
            variant="subtle"
          />
          <UBadge
            :label="user.two_factor_enabled ? 'Two-factor on' : 'Two-factor off'"
            :color="user.two_factor_enabled ? 'success' : 'neutral'"
            variant="subtle"
          />
          <UBadge
            v-if="user.is_platform_admin"
            label="Platform admin"
            color="primary"
            variant="subtle"
          />
          <UBadge
            v-if="user.suspended_at"
            :label="`Suspended ${formatDate(user.suspended_at)}`"
            color="error"
            variant="subtle"
          />
        </div>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <UCard>
          <p class="text-sm text-muted">
            Joined
          </p>
          <p class="mt-1 font-medium">
            {{ formatDate(user.created_at) }}
          </p>
        </UCard>
        <UCard>
          <p class="text-sm text-muted">
            Last active (UTC)
          </p>
          <p class="mt-1 font-medium">
            {{ user.last_active_at ? formatDateTime(user.last_active_at) : 'Never' }}
          </p>
        </UCard>
        <UCard>
          <p class="text-sm text-muted">
            Signed-in devices
          </p>
          <p class="mt-1 font-medium">
            {{ user.devices_count }}
          </p>
        </UCard>
      </div>

      <section class="space-y-3">
        <h2 class="text-lg font-semibold">
          Actions
        </h2>
        <UCard>
          <p
            v-if="user.id === auth.user?.id"
            class="text-sm text-muted"
          >
            This is you. Change your own account on the Account page; admin is managed from the command line.
          </p>
          <p
            v-else-if="user.is_platform_admin"
            class="text-sm text-muted"
          >
            Platform admins are managed from the command line. Remove admin with <code>admin:revoke</code> first.
          </p>
          <div
            v-else
            class="flex flex-wrap gap-2"
          >
            <UButton
              v-for="action in actions"
              :key="action.key"
              :label="action.label"
              :icon="action.icon"
              :color="action.danger ? 'error' : 'neutral'"
              variant="outline"
              @click="pending = action"
            />
          </div>
        </UCard>
      </section>

      <section class="space-y-3">
        <h2 class="text-lg font-semibold">
          Organizations
        </h2>
        <UCard :ui="{ body: 'p-0 sm:p-0' }">
          <UTable
            :data="user.organizations"
            :columns="columns"
          >
            <template #name-cell="{ row }">
              <ULink
                :to="`/admin/organizations/${row.original.id}`"
                class="break-all font-medium text-highlighted hover:underline"
              >
                {{ row.original.name }}
              </ULink>
            </template>
            <template #role-cell="{ row }">
              <UBadge
                :label="row.original.role.name"
                :color="row.original.role.is_owner ? 'primary' : 'neutral'"
                variant="subtle"
              />
            </template>
            <template #joined_at-cell="{ row }">
              <span class="whitespace-nowrap">{{ formatDate(row.original.joined_at) }}</span>
            </template>
            <template #empty>
              <p class="py-6 text-center text-sm text-muted">
                Not in any organization.
              </p>
            </template>
          </UTable>
        </UCard>
      </section>

      <AdminActionModal
        v-if="pending"
        v-model:open="modalOpen"
        :title="pending.title"
        :description="pending.description"
        :confirm-label="pending.confirmLabel"
        :action="runPending"
        :danger="pending.danger"
        @done="onDone"
      />
    </template>
  </div>
</template>
