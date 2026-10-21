<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Paginated, PlatformAuditLogEntry } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Platform log · Admin' })

const api = useApi()
const { page } = useSearchPage()

const { data: entries, failure, status, refresh } = await useAdminData(
  'admin-audit-log',
  () => api<Paginated<PlatformAuditLogEntry>>('/api/admin/audit-log', { query: { page: page.value } }),
  [page],
)

const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<PlatformAuditLogEntry>[] = [
  { accessorKey: 'created_at', header: 'When (UTC)', meta: wideOnly },
  { accessorKey: 'actor', header: 'Who', meta: wideOnly },
  { accessorKey: 'action', header: 'What' },
  { accessorKey: 'ip_address', header: 'IP address', meta: wideOnly },
]

const subjectLink = (entry: PlatformAuditLogEntry) => {
  if (entry.subject?.id == null) return undefined
  if (entry.subject.type === 'user') return `/admin/users/${entry.subject.id}`
  if (entry.subject.type === 'organization') return `/admin/organizations/${entry.subject.id}`
  return undefined
}
const actorName = (entry: PlatformAuditLogEntry) => entry.actor?.name ?? 'Console or former admin'

const formatTime = (value: string) =>
  new Date(value).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })
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
          Platform log
        </h1>
        <p class="text-sm text-muted">
          Which admin looked at which user or organization, and who was made an admin. Only platform admins see this.
        </p>
      </div>

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <UTable
          :data="entries?.data ?? []"
          :columns="columns"
          :loading="status === 'pending'"
        >
          <template #created_at-cell="{ row }">
            <span class="whitespace-nowrap">{{ formatTime(row.original.created_at) }}</span>
          </template>

          <template #actor-cell="{ row }">
            {{ actorName(row.original) }}
          </template>

          <template #action-cell="{ row }">
            <p>
              <span class="font-medium">{{ row.original.action_label }}</span>
              <template v-if="row.original.subject?.label">
                ·
                <ULink
                  v-if="subjectLink(row.original)"
                  :to="subjectLink(row.original)"
                  class="break-all text-highlighted hover:underline"
                >
                  {{ row.original.subject.label }}
                </ULink>
                <span
                  v-else
                  class="break-all"
                >{{ row.original.subject.label }}</span>
              </template>
            </p>
            <p class="text-xs text-muted sm:hidden">
              {{ actorName(row.original) }} · {{ formatTime(row.original.created_at) }} UTC
            </p>
          </template>

          <template #ip_address-cell="{ row }">
            {{ row.original.ip_address ?? '—' }}
          </template>

          <template #empty>
            <p class="py-6 text-center text-sm text-muted">
              Nothing has been recorded yet.
            </p>
          </template>
        </UTable>
      </UCard>

      <div
        v-if="entries && entries.meta.last_page > 1"
        class="flex justify-center"
      >
        <UPagination
          v-model:page="page"
          :total="entries.meta.total"
          :items-per-page="entries.meta.per_page"
        />
      </div>
    </template>
  </div>
</template>
