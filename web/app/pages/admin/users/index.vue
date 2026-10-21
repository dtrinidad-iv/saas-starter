<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminUser, Paginated } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Users · Admin' })

const api = useApi()
const { page, search, debouncedSearch } = useSearchPage()

const { data: users, failure, status, refresh } = await useAdminData(
  'admin-users',
  () => api<Paginated<AdminUser>>('/api/admin/users', { query: { page: page.value, q: debouncedSearch.value || undefined } }),
  [page, debouncedSearch],
)

// On phones only Name shows; email and the rest move under it.
const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<AdminUser>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'email', header: 'Email', meta: wideOnly },
  { id: 'security', header: 'Status', meta: wideOnly },
  { accessorKey: 'organizations_count', header: 'Organizations', meta: wideOnly },
  { accessorKey: 'created_at', header: 'Joined (UTC)', meta: wideOnly },
]

const formatDate = (value: string) =>
  new Date(value).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })
</script>

<template>
  <div class="space-y-6">
    <AdminGate
      v-if="failure"
      :failure="failure"
      @retry="() => refresh()"
    />

    <template v-else>
      <div>
        <h1 class="text-2xl font-semibold">
          Users
        </h1>
        <p class="text-sm text-muted">
          Everyone with an account. Opening a user is recorded in the platform log.
        </p>
      </div>

      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Search by name or email"
        aria-label="Search users by name or email"
        :maxlength="100"
        class="w-full sm:max-w-sm"
      />

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <UTable
          :data="users?.data ?? []"
          :columns="columns"
          :loading="status === 'pending'"
        >
          <template #name-cell="{ row }">
            <ULink
              :to="`/admin/users/${row.original.id}`"
              class="font-medium text-highlighted hover:underline"
            >
              {{ row.original.name }}
            </ULink>
            <p class="break-all text-xs text-muted sm:hidden">
              {{ row.original.email }}
            </p>
            <AdminUserBadges
              :user="row.original"
              class="mt-1 sm:hidden"
            />
          </template>

          <template #email-cell="{ row }">
            <span class="break-all">{{ row.original.email }}</span>
          </template>

          <template #security-cell="{ row }">
            <AdminUserBadges :user="row.original" />
          </template>

          <template #created_at-cell="{ row }">
            <span class="whitespace-nowrap">{{ formatDate(row.original.created_at) }}</span>
          </template>

          <template #empty>
            <p class="py-6 text-center text-sm text-muted">
              {{ debouncedSearch ? 'No users match this search.' : 'No users yet.' }}
            </p>
          </template>
        </UTable>
      </UCard>

      <div
        v-if="users && users.meta.last_page > 1"
        class="flex justify-center"
      >
        <UPagination
          v-model:page="page"
          :total="users.meta.total"
          :items-per-page="users.meta.per_page"
        />
      </div>
    </template>
  </div>
</template>
