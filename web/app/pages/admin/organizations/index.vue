<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminOrganization, Paginated } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Organizations · Admin' })

const api = useApi()
const { page, search, debouncedSearch } = useSearchPage()

const { data: organizations, failure, status, refresh } = await useAdminData(
  'admin-organizations',
  () => api<Paginated<AdminOrganization>>('/api/admin/organizations', { query: { page: page.value, q: debouncedSearch.value || undefined } }),
  [page, debouncedSearch],
)

const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<AdminOrganization>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'members_count', header: 'Members' },
  { accessorKey: 'plan', header: 'Plan' },
  { accessorKey: 'created_at', header: 'Created (UTC)', meta: wideOnly },
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
          Organizations
        </h1>
        <p class="text-sm text-muted">
          Every organization. Opening one is recorded in the platform log; its members don't see it.
        </p>
      </div>

      <UInput
        v-model="search"
        icon="i-lucide-search"
        placeholder="Search by name"
        aria-label="Search organizations by name"
        :maxlength="100"
        class="w-full sm:max-w-sm"
      />

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <UTable
          :data="organizations?.data ?? []"
          :columns="columns"
          :loading="status === 'pending'"
        >
          <template #name-cell="{ row }">
            <ULink
              :to="`/admin/organizations/${row.original.id}`"
              class="break-all font-medium text-highlighted hover:underline"
            >
              {{ row.original.name }}
            </ULink>
          </template>

          <template #plan-cell="{ row }">
            <UBadge
              :label="row.original.plan"
              :color="row.original.plan === 'free' ? 'neutral' : 'primary'"
              variant="subtle"
              class="capitalize"
            />
          </template>

          <template #created_at-cell="{ row }">
            <span class="whitespace-nowrap">{{ formatDate(row.original.created_at) }}</span>
          </template>

          <template #empty>
            <p class="py-6 text-center text-sm text-muted">
              {{ debouncedSearch ? 'No organizations match this search.' : 'No organizations yet.' }}
            </p>
          </template>
        </UTable>
      </UCard>

      <div
        v-if="organizations && organizations.meta.last_page > 1"
        class="flex justify-center"
      >
        <UPagination
          v-model:page="page"
          :total="organizations.meta.total"
          :items-per-page="organizations.meta.per_page"
        />
      </div>
    </template>
  </div>
</template>
