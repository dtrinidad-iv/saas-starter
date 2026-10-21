<script setup lang="ts">
import type { FormError, FormSubmitEvent, TableColumn } from '@nuxt/ui'
import type { AiBudget, AiUsageReport, AiUsageTotals } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'AI usage' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const organizations = useOrganizationStore()
const toast = useToast()
const { can } = useOrganizationPermissions()
const canView = computed(() => can('ai_usage.view'))

const MONTH = /^\d{4}-\d{2}$/
// Fixed to UTC, like the API, so server and browser agree on "this month".
const currentMonth = () => new Date().toISOString().slice(0, 7)

// The month lives in the URL (?month=) so reload and Back keep it.
const queryMonth = () => (typeof route.query.month === 'string' && MONTH.test(route.query.month) ? route.query.month : currentMonth())
const month = ref(queryMonth())

watch(month, (value) => {
  // The month input can be cleared: fall back to this month instead of showing nothing.
  if (!MONTH.test(value)) {
    month.value = currentMonth()
    return
  }
  router.replace({ path: route.path, query: { month: value !== currentMonth() ? value : undefined } })
})
watch(() => route.query.month, () => {
  month.value = queryMonth()
})

// Reloaded by refreshNuxtData() when switching organizations, and when the permission changes.
const { data: report, status, error, refresh } = await useAsyncData(
  'ai-usage',
  async () => {
    if (!canView.value || !MONTH.test(month.value)) {
      return null
    }
    return (await api<{ data: AiUsageReport }>('/api/ai-usage', { query: { month: month.value } })).data
  },
  { watch: [month, canView] },
)

// Fixed locale so server and browser render the same text.
const usd = (value: number) => {
  // Small amounts (most single calls) need more than cents to be visible.
  const digits = value > 0 && value < 0.01 ? 4 : 2
  return value.toLocaleString('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: digits, maximumFractionDigits: digits })
}
const count = (value: number) => value.toLocaleString('en-US')

const FEATURES: Record<string, string> = {
  project_summary: 'Project summaries',
  project_writing: 'Writing help',
  project_chat: 'Project chat',
}

const budget = computed<AiBudget | null>(() => report.value?.budget ?? null)
const budgetUsed = computed(() => {
  const value = budget.value
  if (!value || value.limit_usd <= 0) {
    return 100
  }
  return Math.min(100, Math.round((value.spent_usd / value.limit_usd) * 100))
})
const budgetColor = computed(() => (budgetUsed.value >= 100 ? 'error' : budgetUsed.value >= 80 ? 'warning' : 'primary'))

const stats = computed(() => {
  const totals = report.value?.totals
  return totals
    ? [
        { label: 'Calls', value: count(totals.calls) },
        { label: 'Failed', value: count(totals.failed) },
        { label: 'Tokens (in / out)', value: `${count(totals.input_tokens)} / ${count(totals.output_tokens)}` },
        { label: 'Cost', value: usd(totals.cost_usd) },
      ]
    : []
})

// On phones only the name and cost show.
const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const numberColumns: TableColumn<AiUsageTotals>[] = [
  { accessorKey: 'calls', header: 'Calls', cell: ({ row }) => count(row.original.calls), meta: wideOnly },
  { accessorKey: 'failed', header: 'Failed', cell: ({ row }) => count(row.original.failed), meta: wideOnly },
  {
    id: 'tokens',
    header: 'Tokens (in / out)',
    cell: ({ row }) => `${count(row.original.input_tokens)} / ${count(row.original.output_tokens)}`,
    meta: wideOnly,
  },
  { accessorKey: 'cost_usd', header: 'Cost', cell: ({ row }) => usd(row.original.cost_usd) },
]
const featureColumns = [
  { accessorKey: 'feature', header: 'Feature', cell: ({ row }) => FEATURES[row.original.feature] ?? row.original.feature },
  ...numberColumns,
] as TableColumn<AiUsageReport['by_feature'][number]>[]
const userColumns = [
  { id: 'user', header: 'Who', cell: ({ row }) => row.original.user?.name ?? 'No user (background work)' },
  ...numberColumns,
] as TableColumn<AiUsageReport['by_user'][number]>[]
const dayColumns = [
  { accessorKey: 'day', header: 'Day (UTC)' },
  ...numberColumns,
] as TableColumn<AiUsageReport['by_day'][number]>[]

// Budget modal
const budgetOpen = ref(false)
const savingBudget = ref(false)
const budgetState = reactive({ amount: '' })

function openBudget() {
  budgetState.amount = budget.value?.own_limit_usd != null ? String(budget.value.own_limit_usd) : ''
  budgetOpen.value = true
}

function validateBudget(state: Partial<typeof budgetState>): FormError[] {
  const amount = state.amount?.trim() ?? ''
  if (amount === '') {
    return []
  }
  const value = Number(amount)
  if (!Number.isFinite(value) || value < 0 || !/^\d+(\.\d{1,2})?$/.test(amount)) {
    return [{ name: 'amount', message: 'Enter an amount of 0 or more with up to 2 decimals.' }]
  }
  // The plan's budget is the ceiling (the API refuses more).
  const max = budget.value?.plan_limit_usd ?? Infinity
  return value <= max ? [] : [{ name: 'amount', message: `Your plan allows up to ${usd(max)} a month. Upgrade the plan to raise it.` }]
}

async function saveBudget(event: FormSubmitEvent<typeof budgetState>) {
  const amount = event.data.amount.trim()
  savingBudget.value = true
  try {
    await api('/api/ai-budget', { method: 'PUT', body: { monthly_budget_usd: amount === '' ? null : Number(amount) } })
    budgetOpen.value = false
    toast.add({ title: 'Budget updated', color: 'success' })
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Could not update the budget', description: errorMessage(error), color: 'error' })
  }
  finally {
    savingBudget.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">
          AI usage
        </h1>
        <p class="text-sm text-muted">
          AI calls and cost in {{ organizations.current?.name ?? 'this organization' }}.
        </p>
      </div>
      <UFormField
        v-if="canView"
        label="Month"
      >
        <UInput
          v-model="month"
          type="month"
          :max="currentMonth()"
        />
      </UFormField>
    </div>

    <UAlert
      v-if="!canView"
      color="neutral"
      variant="subtle"
      icon="i-lucide-lock"
      title="You don't have access to AI usage"
      description="Ask an owner of this organization for a role with the &quot;View AI usage and cost&quot; permission."
    />

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      title="Could not load AI usage"
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

    <template v-else-if="report">
      <UCard v-if="budget">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-medium">
              Budget this month
            </h2>
            <p class="text-sm text-muted">
              <template v-if="budget.limit_usd > 0">
                {{ usd(budget.spent_usd) }} of {{ usd(budget.limit_usd) }} used{{ budget.own_limit_usd !== null && budget.own_limit_usd < budget.plan_limit_usd ? ` (your own limit; the plan allows ${usd(budget.plan_limit_usd)})` : ' (plan budget)' }}.
                <template v-if="budget.credits_usd > 0">
                  Includes {{ usd(budget.credits_usd) }} of AI credits bought this month.
                </template>
                AI requests stop when it's used up.
              </template>
              <template v-else>
                AI is turned off for this organization (budget set to {{ usd(0) }}).
              </template>
            </p>
          </div>
          <UButton
            v-if="budget.can_manage"
            label="Change budget"
            color="neutral"
            variant="outline"
            size="sm"
            @click="openBudget"
          />
        </div>
        <UProgress
          :model-value="budgetUsed"
          :color="budgetColor"
          class="mt-4"
          :aria-label="`${budgetUsed}% of the monthly AI budget used`"
        />
      </UCard>

      <!-- Dimmed while another month loads, so old numbers don't pass for the new month. -->
      <div
        class="grid grid-cols-2 gap-3 transition-opacity lg:grid-cols-4"
        :class="{ 'opacity-50': status === 'pending' }"
        :aria-busy="status === 'pending'"
      >
        <UCard
          v-for="stat in stats"
          :key="stat.label"
        >
          <p class="text-sm text-muted">
            {{ stat.label }}
          </p>
          <p class="mt-1 text-xl font-semibold">
            {{ stat.value }}
          </p>
        </UCard>
      </div>

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
          <h2 class="font-medium">
            By feature
          </h2>
        </template>
        <UTable
          :data="report.by_feature"
          :columns="featureColumns"
          :loading="status === 'pending'"
          :empty="`No AI calls in ${report.month}.`"
        />
      </UCard>

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
          <h2 class="font-medium">
            By person
          </h2>
        </template>
        <UTable
          :data="report.by_user"
          :columns="userColumns"
          :loading="status === 'pending'"
          :empty="`No AI calls in ${report.month}.`"
        />
      </UCard>

      <UCard :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
          <h2 class="font-medium">
            By day
          </h2>
        </template>
        <UTable
          :data="report.by_day"
          :columns="dayColumns"
          :loading="status === 'pending'"
          :empty="`No AI calls in ${report.month}.`"
        />
      </UCard>
    </template>

    <UModal
      v-model:open="budgetOpen"
      title="Monthly AI budget"
      :description="`Up to ${usd(budget?.plan_limit_usd ?? 0)} on your plan. Leave empty to use the plan's budget; 0 turns AI off for this organization.`"
    >
      <template #body>
        <UForm
          :state="budgetState"
          :validate="validateBudget"
          class="space-y-4"
          @submit="saveBudget"
        >
          <UFormField
            label="Budget in USD"
            name="amount"
          >
            <UInput
              v-model="budgetState.amount"
              inputmode="decimal"
              placeholder="Plan budget"
              autofocus
              class="w-full"
            >
              <template #leading>
                <span class="text-muted">$</span>
              </template>
            </UInput>
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Cancel"
              @click="budgetOpen = false"
            />
            <UButton
              type="submit"
              label="Save"
              :loading="savingBudget"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
