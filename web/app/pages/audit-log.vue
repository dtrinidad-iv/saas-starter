<script setup lang="ts">
import type { SelectItem, TableColumn } from '@nuxt/ui'
import type { AuditActionInfo, AuditLogEntry, Member, Paginated } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Audit log' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const organizations = useOrganizationStore()
const { can } = useOrganizationPermissions()
const canView = computed(() => can('audit_log.view'))

// Select items can't have an empty value, so "no filter" is ALL.
const ALL = 'all'
const DATE = /^\d{4}-\d{2}-\d{2}$/
const ACTION = /^[a-z_]+\.[a-z_]+$/

// Page and filters live in the URL (?page=&action=&actor=&from=&to=) so reload and Back keep them.
const queryString = (key: string) => (typeof route.query[key] === 'string' ? route.query[key] : '')
const fromQuery = () => ({
  page: Math.max(1, Number.parseInt(queryString('page'), 10) || 1),
  action: ACTION.test(queryString('action')) ? queryString('action') : ALL,
  actor: /^\d+$/.test(queryString('actor')) ? queryString('actor') : ALL,
  from: DATE.test(queryString('from')) ? queryString('from') : '',
  to: DATE.test(queryString('to')) ? queryString('to') : '',
})

const initial = fromQuery()
const page = ref(initial.page)
const action = ref(initial.action)
const actor = ref(initial.actor)
const from = ref(initial.from)
const to = ref(initial.to)
const expanded = ref<Record<string, boolean>>({})

// A filter change starts again at page 1, except when the filters come from the URL.
// Sync, so the flag below is still set when this runs.
let syncingFromUrl = false
watch([action, actor, from, to], () => {
  if (!syncingFromUrl) {
    page.value = 1
  }
}, { flush: 'sync' })

// Pin the path: a late update from this (leaving) page must not rewrite the next page's URL.
watch([page, action, actor, from, to], ([p, a, u, f, t]) => {
  expanded.value = {}
  router.replace({
    path: route.path,
    query: {
      page: p > 1 ? String(p) : undefined,
      action: a !== ALL ? a : undefined,
      actor: u !== ALL ? u : undefined,
      from: f || undefined,
      to: t || undefined,
    },
  })
})

// The URL changed from outside (Back/Forward, or the Audit log link): follow it.
watch(() => route.query, () => {
  const next = fromQuery()
  if (next.page === page.value && next.action === action.value && next.actor === actor.value
    && next.from === from.value && next.to === to.value) {
    return
  }
  syncingFromUrl = true
  action.value = next.action
  actor.value = next.actor
  from.value = next.from
  to.value = next.to
  page.value = next.page
  syncingFromUrl = false
})

// A different organization has a different log: start over.
watch(() => organizations.current?.id, () => {
  action.value = ALL
  actor.value = ALL
  from.value = ''
  to.value = ''
  page.value = 1
  expanded.value = {}
})

// Reloaded by refreshNuxtData() when switching organizations, and when the
// permission changes (e.g. switching to an organization where the user lacks it).
const { data: entries, status, error, refresh } = await useAsyncData(
  'audit-log',
  async () => {
    if (!canView.value) {
      return null
    }
    const fetchPage = (p: number) => api<Paginated<AuditLogEntry>>('/api/audit-logs', {
      query: {
        page: p,
        action: action.value !== ALL ? action.value : undefined,
        actor_id: actor.value !== ALL ? actor.value : undefined,
        from: from.value || undefined,
        to: to.value || undefined,
      },
    })
    const result = await fetchPage(page.value)
    // A page past the end (old link): load the last page instead.
    if (result.meta.total > 0 && page.value > result.meta.last_page) {
      page.value = result.meta.last_page
      return fetchPage(result.meta.last_page)
    }
    return result
  },
  { watch: [page, action, actor, from, to, canView] },
)

if (entries.value && entries.value.meta.current_page !== page.value) {
  page.value = entries.value.meta.current_page
}

const { data: actions, error: actionsError } = await useAsyncData(
  'audit-log-actions',
  () => canView.value ? api<{ data: AuditActionInfo[] }>('/api/audit-log/actions').then(response => response.data) : Promise.resolve([]),
  { default: () => [], watch: [canView] },
)

// Current members only; entries by former members still show, they just can't be filtered on.
const { data: members, error: membersError } = await useAsyncData(
  'audit-log-members',
  () => canView.value ? api<{ data: Member[] }>('/api/members').then(response => response.data) : Promise.resolve([]),
  { default: () => [], watch: [canView] },
)

const actionItems = computed<SelectItem[]>(() => {
  const items: SelectItem[] = [{ label: 'All actions', value: ALL }]
  let group = ''
  for (const info of actions.value) {
    if (info.group !== group) {
      group = info.group
      items.push({ type: 'label', label: group })
    }
    items.push({ label: info.label, value: info.value })
  }
  return items
})

const actorItems = computed<SelectItem[]>(() => [
  { label: 'Everyone', value: ALL },
  ...members.value.map(member => ({ label: member.name, value: String(member.id) })),
])

const hasFilters = computed(() => action.value !== ALL || actor.value !== ALL || !!from.value || !!to.value)

function clearFilters() {
  action.value = ALL
  actor.value = ALL
  from.value = ''
  to.value = ''
}

// On phones only the expand toggle and What column show; time and who move under it.
const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<AuditLogEntry>[] = [
  { id: 'expand', header: () => h('span', { class: 'sr-only' }, 'Details'), meta: { class: { td: 'w-0' } } },
  { accessorKey: 'created_at', header: 'When (UTC)', meta: wideOnly },
  { accessorKey: 'actor', header: 'Who', meta: wideOnly },
  { accessorKey: 'action', header: 'What' },
]

// UTC so the server and browser render the same text.
const formatTime = (value: string) =>
  new Date(value).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })

const formatField = (key: string) => {
  const text = key.replaceAll('_', ' ')
  return text.charAt(0).toUpperCase() + text.slice(1)
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '—'
  }
  if (Array.isArray(value)) {
    return value.length ? value.map(formatValue).join(', ') : '—'
  }
  if (typeof value === 'boolean') {
    return value ? 'Yes' : 'No'
  }
  if (typeof value === 'object') {
    return JSON.stringify(value)
  }
  return String(value)
}

const hasDetails = (entry: AuditLogEntry) =>
  Object.keys(entry.changes).length > 0 || Object.keys(entry.metadata).length > 0 || !!entry.ip_address
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold">
        Audit log
      </h1>
      <p class="text-sm text-muted">
        Who did what in {{ organizations.current?.name ?? 'this organization' }}.
      </p>
    </div>

    <UAlert
      v-if="!canView"
      color="neutral"
      variant="subtle"
      icon="i-lucide-lock"
      title="You don't have access to the audit log"
      description="Ask an owner of this organization for a role with the &quot;View the audit log&quot; permission."
    />

    <template v-else>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <UFormField label="Action">
          <USelect
            v-model="action"
            :items="actionItems"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Who">
          <USelect
            v-model="actor"
            :items="actorItems"
            class="w-full"
          />
        </UFormField>
        <UFormField label="From">
          <UInput
            v-model="from"
            type="date"
            :max="to || undefined"
            class="w-full"
          />
        </UFormField>
        <UFormField label="To">
          <UInput
            v-model="to"
            type="date"
            :min="from || undefined"
            class="w-full"
          />
        </UFormField>
      </div>

      <p
        v-if="actionsError || membersError"
        class="text-sm text-warning"
      >
        Some filter options could not be loaded. Reload the page to try again.
      </p>

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        title="Could not load the audit log"
        :description="errorMessage(error)"
      >
        <template #actions>
          <UButton
            label="Retry"
            color="neutral"
            variant="outline"
            size="sm"
            @click="() => refresh()"
          />
        </template>
      </UAlert>

      <UCard
        v-else
        :ui="{ body: 'p-0 sm:p-0' }"
      >
        <UTable
          v-model:expanded="expanded"
          :data="entries?.data ?? []"
          :columns="columns"
          :loading="status === 'pending'"
          :ui="{ tr: 'data-[expanded=true]:bg-elevated/50' }"
        >
          <template #expand-cell="{ row }">
            <UButton
              v-if="hasDetails(row.original)"
              color="neutral"
              variant="ghost"
              size="sm"
              icon="i-lucide-chevron-down"
              square
              :aria-label="`Details: ${row.original.action_label}${row.original.subject?.label ? ` ${row.original.subject.label}` : ''}`"
              :aria-expanded="row.getIsExpanded()"
              :ui="{ leadingIcon: ['transition-transform', row.getIsExpanded() ? 'rotate-180' : ''] }"
              @click="row.toggleExpanded()"
            />
          </template>

          <template #created_at-cell="{ row }">
            <span class="whitespace-nowrap">{{ formatTime(row.original.created_at) }}</span>
          </template>

          <template #actor-cell="{ row }">
            {{ row.original.actor?.name ?? 'System or former member' }}
          </template>

          <!-- All text is rendered escaped: names and values are user content. -->
          <template #action-cell="{ row }">
            <p>
              <span class="font-medium">{{ row.original.action_label }}</span>
              <template v-if="row.original.subject?.label">
                · <span class="break-all">{{ row.original.subject.label }}</span>
              </template>
            </p>
            <p class="text-muted text-xs sm:hidden">
              {{ row.original.actor?.name ?? 'System or former member' }} · {{ formatTime(row.original.created_at) }} UTC
            </p>
          </template>

          <template #expanded="{ row }">
            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
              <template
                v-for="(change, field) in row.original.changes"
                :key="`change-${field}`"
              >
                <dt class="text-muted">
                  {{ formatField(String(field)) }}
                </dt>
                <dd class="break-words whitespace-pre-wrap">
                  <span class="text-muted line-through">{{ formatValue(change.old) }}</span>
                  → {{ formatValue(change.new) }}
                </dd>
              </template>
              <template
                v-for="(value, key) in row.original.metadata"
                :key="`meta-${key}`"
              >
                <dt class="text-muted">
                  {{ formatField(String(key)) }}
                </dt>
                <dd class="break-words">
                  {{ formatValue(value) }}
                </dd>
              </template>
              <template v-if="row.original.ip_address">
                <dt class="text-muted">
                  IP address
                </dt>
                <dd>{{ row.original.ip_address }}</dd>
              </template>
            </dl>
          </template>

          <template #empty>
            <div class="py-6 text-center text-sm text-muted">
              <p>{{ hasFilters ? 'No entries match these filters.' : 'Nothing has been recorded yet.' }}</p>
              <UButton
                v-if="hasFilters"
                label="Clear filters"
                color="neutral"
                variant="link"
                @click="clearFilters"
              />
            </div>
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
