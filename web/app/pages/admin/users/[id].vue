<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminUserDetail } from '~/types/api'

definePageMeta({ middleware: 'auth' })

const api = useApi()
const route = useRoute()
const id = String(route.params.id)

const { data: user, failure, refresh } = await useAdminData(`admin-user:${id}`, async () => {
  if (!/^\d+$/.test(id)) {
    throw createError({ statusCode: 404 })
  }
  return (await api<{ data: AdminUserDetail }>(`/api/admin/users/${id}`)).data
})
useSeoMeta({ title: () => `${user.value?.name ?? 'User'} · Admin` })

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
    </template>
  </div>
</template>
