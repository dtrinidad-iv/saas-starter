<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Invoice, InvoiceList, InvoiceStatus } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Invoices' })

const api = useApi()
const toast = useToast()
const organizations = useOrganizationStore()
const { can } = useOrganizationPermissions()
const canManage = computed(() => can('billing.manage'))

// The first page. Reloaded by refreshNuxtData() when switching organizations, and
// when the permission changes.
const { data: firstPage, status, error, refresh } = await useAsyncData(
  'billing-invoices',
  async () => (canManage.value ? await api<InvoiceList>('/api/billing/invoices') : null),
  { watch: [canManage] },
)

// Pages after the first, from "Load more". Start over whenever the first page reloads.
const more = ref<Invoice[]>([])
const cursor = ref<string | null>(null)
const loadingMore = ref(false)
watch(firstPage, (page) => {
  more.value = []
  cursor.value = page?.meta.next_cursor ?? null
}, { immediate: true })

const invoices = computed(() => [...(firstPage.value?.data ?? []), ...more.value])

async function loadMore() {
  // Still the previous organization's cursor while the first page reloads.
  if (!cursor.value || status.value === 'pending') {
    return
  }
  const organizationId = organizations.current?.id
  const startingAfter = cursor.value
  loadingMore.value = true
  try {
    const page = await api<InvoiceList>('/api/billing/invoices', { query: { starting_after: startingAfter } })
    // Switched organizations or reloaded meanwhile: this page no longer follows the list.
    if (organizations.current?.id !== organizationId || cursor.value !== startingAfter) {
      return
    }
    more.value.push(...page.data)
    cursor.value = page.meta.next_cursor
  }
  catch (error) {
    toast.add({ title: 'Could not load more invoices', description: errorMessage(error), color: 'error' })
  }
  finally {
    loadingMore.value = false
  }
}

// Fixed locale and time zone so server and browser render the same text.
const date = (value: string) => new Date(value).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })
function amount(invoice: Invoice) {
  const format = new Intl.NumberFormat('en-US', { style: 'currency', currency: invoice.currency.toUpperCase() })
  // Stripe amounts are in the smallest unit: cents for USD, whole yen for JPY.
  return format.format(invoice.total / 10 ** (format.resolvedOptions().maximumFractionDigits ?? 2))
}

const statusBadge: Record<InvoiceStatus, { label: string, color: 'success' | 'warning' | 'neutral' }> = {
  paid: { label: 'Paid', color: 'success' },
  open: { label: 'Due', color: 'warning' },
  void: { label: 'Void', color: 'neutral' },
  uncollectible: { label: 'Uncollectible', color: 'neutral' },
}

// On phones Number and Description are hidden.
const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<Invoice>[] = [
  { accessorKey: 'created_at', header: 'Date' },
  { accessorKey: 'number', header: 'Number', meta: wideOnly },
  { accessorKey: 'description', header: 'Description', meta: wideOnly },
  { accessorKey: 'total', header: 'Amount' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'links', header: () => h('span', { class: 'sr-only' }, 'Links'), meta: { class: { td: 'text-right' } } },
]
</script>

<template>
  <div class="space-y-6">
    <div>
      <UButton
        to="/billing"
        label="Billing"
        icon="i-lucide-arrow-left"
        color="neutral"
        variant="link"
        class="-ms-2.5 mb-1"
      />
      <h1 class="text-2xl font-semibold">
        Invoices
      </h1>
      <p class="text-sm text-muted">
        Subscription and AI credit invoices of {{ organizations.current?.name ?? 'this organization' }}, from Stripe.
      </p>
    </div>

    <UAlert
      v-if="!canManage"
      color="neutral"
      variant="subtle"
      icon="i-lucide-lock"
      title="You don't have access to invoices"
      description="Ask an owner of this organization for a role with the &quot;Manage the plan, payment details and invoices&quot; permission."
    />

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      title="Could not load invoices"
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

    <template v-else>
      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <UTable
          :data="invoices"
          :columns="columns"
          :loading="status === 'pending'"
        >
          <template #created_at-cell="{ row }">
            <span class="whitespace-nowrap">{{ date(row.original.created_at) }}</span>
          </template>

          <template #number-cell="{ row }">
            {{ row.original.number ?? '—' }}
          </template>

          <template #description-cell="{ row }">
            {{ row.original.description ?? '—' }}
          </template>

          <template #total-cell="{ row }">
            <span class="whitespace-nowrap tabular-nums">{{ amount(row.original) }}</span>
          </template>

          <template #status-cell="{ row }">
            <UBadge
              :label="statusBadge[row.original.status]?.label ?? row.original.status"
              :color="statusBadge[row.original.status]?.color ?? 'neutral'"
              variant="subtle"
            />
          </template>

          <template #links-cell="{ row }">
            <div class="flex justify-end gap-1">
              <UButton
                v-if="row.original.hosted_url"
                :to="row.original.hosted_url"
                target="_blank"
                rel="noopener noreferrer"
                external
                :label="row.original.status === 'open' ? 'Pay' : 'View'"
                :color="row.original.status === 'open' ? 'primary' : 'neutral'"
                :variant="row.original.status === 'open' ? 'solid' : 'ghost'"
                size="sm"
                :aria-label="`${row.original.status === 'open' ? 'Pay' : 'View'} invoice ${row.original.number ?? date(row.original.created_at)} (opens Stripe)`"
              />
              <UButton
                v-if="row.original.pdf_url"
                :to="row.original.pdf_url"
                target="_blank"
                rel="noopener noreferrer"
                external
                label="PDF"
                icon="i-lucide-download"
                color="neutral"
                variant="ghost"
                size="sm"
                :aria-label="`Download invoice ${row.original.number ?? date(row.original.created_at)} as PDF`"
              />
            </div>
          </template>

          <template #empty>
            <!-- Drafts are left out, so a page can be empty with more after it. -->
            <p class="py-6 text-center text-sm text-muted">
              {{ cursor ? 'No finished invoices here. Load more to see older ones.' : 'No invoices yet. They appear here after the first payment.' }}
            </p>
          </template>
        </UTable>
      </UCard>

      <div
        v-if="cursor && status !== 'pending'"
        class="flex justify-center"
      >
        <UButton
          label="Load more"
          color="neutral"
          variant="outline"
          :loading="loadingMore"
          @click="loadMore"
        />
      </div>
    </template>
  </div>
</template>
