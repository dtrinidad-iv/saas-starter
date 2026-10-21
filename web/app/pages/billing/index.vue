<script setup lang="ts">
import type { BillingOverview, BillingPlan, CreditPack } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Billing' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const organizations = useOrganizationStore()
const toast = useToast()
const { can } = useOrganizationPermissions()
const canManage = computed(() => can('billing.manage'))

// Reloaded by refreshNuxtData() when switching organizations, and when the permission changes.
const { data: billing, error, refresh } = await useAsyncData(
  'billing',
  async () => (canManage.value ? (await api<{ data: BillingOverview }>('/api/billing')).data : null),
  { watch: [canManage] },
)

// Fixed locale and time zone so server and browser render the same text.
const usd = (value: number) => value.toLocaleString('en-US', { style: 'currency', currency: 'USD' })
const date = (value: string) => new Date(value).toLocaleDateString('en-US', { dateStyle: 'long', timeZone: 'UTC' })

const subscription = computed(() => billing.value?.subscription ?? null)
const onPaidPlan = computed(() => billing.value?.plan.is_paid ?? false)
const pastDue = computed(() => subscription.value?.status === 'past_due')

// Back from Stripe Checkout (?checkout=success|credits|canceled). The webhook changes the
// plan or adds the credits, maybe before this page loads, maybe a moment later: refetch a
// few times until it shows.
const returned = ref<'success' | 'credits' | 'canceled' | null>(null)
const waiting = ref(false)
// The credits before leaving for Checkout, to tell when the new ones arrived.
const CREDITS_KEY = 'billing:credits-before-checkout'
let polling = false

function readCreditsBefore(): number | null {
  try {
    const value = sessionStorage.getItem(CREDITS_KEY)
    sessionStorage.removeItem(CREDITS_KEY)
    return value === null ? null : Number(value)
  }
  catch {
    return null
  }
}

onMounted(async () => {
  const value = route.query.checkout
  if (value !== 'success' && value !== 'credits' && value !== 'canceled') {
    return
  }
  returned.value = value
  // Drop it from the URL so a reload doesn't show it again.
  router.replace({ path: route.path })

  if (value === 'canceled') {
    return
  }

  // Upgrading is only offered on the free plan, so "on a paid plan now" means it went through.
  const creditsBefore = value === 'credits' ? readCreditsBefore() : null
  const done = () => (value === 'success'
    ? billing.value?.plan.is_paid === true
    : creditsBefore !== null && (billing.value?.ai_budget.credits_usd ?? 0) > creditsBefore)

  polling = true
  waiting.value = true
  for (let attempt = 0; attempt < 6 && polling && !done(); attempt++) {
    await new Promise(resolve => setTimeout(resolve, 2000))
    if (polling) {
      await refresh()
    }
  }
  waiting.value = false
  polling = false
})

function stopPolling() {
  polling = false
  waiting.value = false
}
onBeforeUnmount(stopPolling)
// The message is about the organization that paid: drop it after switching.
watch(() => organizations.current?.id, () => {
  stopPolling()
  returned.value = null
})

// Back from Stripe without paying, the browser may restore this page as it was (loading buttons).
onMounted(() => window.addEventListener('pageshow', resetRedirect))
onBeforeUnmount(() => window.removeEventListener('pageshow', resetRedirect))
function resetRedirect(event: PageTransitionEvent) {
  if (event.persisted) {
    redirecting.value = null
  }
}

const returnAlert = computed(() => {
  switch (returned.value) {
    case 'success':
      return waiting.value
        ? { color: 'info' as const, icon: 'i-lucide-loader-circle', title: 'Payment received', description: 'Updating your plan. This takes a few seconds.' }
        : { color: 'success' as const, icon: 'i-lucide-circle-check', title: 'Payment received', description: onPaidPlan.value ? `You're on ${billing.value?.plan.name}.` : 'Your plan will update shortly. Reload the page in a minute.' }
    case 'credits':
      return waiting.value
        ? { color: 'info' as const, icon: 'i-lucide-loader-circle', title: 'Payment received', description: 'Adding your AI credits. This takes a few seconds.' }
        : { color: 'success' as const, icon: 'i-lucide-circle-check', title: 'Payment received', description: 'Your AI credits are in this month\'s budget (or will be within a minute, once Stripe confirms the payment).' }
    case 'canceled':
      return { color: 'neutral' as const, icon: 'i-lucide-info', title: 'Checkout canceled', description: 'Nothing was charged.' }
    default:
      return null
  }
})

// Checkout and the portal are Stripe pages: the API returns their URL.
const redirecting = ref<string | null>(null)

async function goTo(key: string, path: string, body?: Record<string, string>) {
  redirecting.value = key
  try {
    const { data } = await api<{ data: { url: string } }>(path, { method: 'POST', body })
    await navigateTo(data.url, { external: true })
  }
  catch (error) {
    toast.add({ title: 'Could not open billing', description: errorMessage(error), color: 'error' })
    redirecting.value = null
  }
}

const subscribe = (plan: BillingPlan) => goTo(plan.key, '/api/billing/checkout', { plan: plan.key })
function buyCredits(pack: CreditPack) {
  try {
    sessionStorage.setItem(CREDITS_KEY, String(billing.value?.ai_budget.credits_usd ?? 0))
  }
  catch {
    // Without it the return message just doesn't wait for the credits.
  }
  return goTo(pack.key, '/api/billing/credits/checkout', { pack: pack.key })
}
const openPortal = () => goTo('portal', '/api/billing/portal')

function planButton(plan: BillingPlan) {
  const current = billing.value?.plan.key === plan.key
  if (current && plan.is_paid) {
    return { label: 'Manage billing', color: 'neutral' as const, variant: 'outline' as const, block: true, loading: redirecting.value === 'portal', disabled: redirecting.value !== null, onClick: openPortal }
  }
  if (!current && plan.is_paid && !onPaidPlan.value) {
    return { label: `Upgrade to ${plan.name}`, block: true, loading: redirecting.value === plan.key, disabled: redirecting.value !== null, onClick: () => subscribe(plan) }
  }
  return undefined
}

const budget = computed(() => billing.value?.ai_budget ?? null)
// Own budget set to $0: credits wouldn't buy any AI (the API refuses them).
const aiOff = computed(() => budget.value !== null && !budget.value.is_default && budget.value.limit_usd === 0)
// Credits last until the end of the UTC month they're bought in.
const monthEnd = computed(() => {
  const now = new Date()
  return date(new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() + 1, 0)).toISOString())
})
const budgetUsed = computed(() => {
  const value = budget.value
  if (!value || value.limit_usd <= 0) {
    return 100
  }
  return Math.min(100, Math.round((value.spent_usd / value.limit_usd) * 100))
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold">
        Billing
      </h1>
      <p class="text-sm text-muted">
        Plan, payment details and AI credits of {{ organizations.current?.name ?? 'this organization' }}.
      </p>
    </div>

    <UAlert
      v-if="!canManage"
      color="neutral"
      variant="subtle"
      icon="i-lucide-lock"
      title="You don't have access to billing"
      description="Ask an owner of this organization for a role with the &quot;Manage the plan, payment details and invoices&quot; permission."
    />

    <UAlert
      v-else-if="error"
      color="error"
      variant="subtle"
      title="Could not load billing"
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

    <template v-else-if="billing">
      <UAlert
        v-if="returnAlert"
        :color="returnAlert.color"
        variant="subtle"
        :icon="returnAlert.icon"
        :title="returnAlert.title"
        :description="returnAlert.description"
        :ui="{ icon: waiting ? 'animate-spin' : '' }"
        role="status"
      />

      <UAlert
        v-if="subscription?.on_grace_period && subscription.ends_at"
        color="warning"
        variant="subtle"
        icon="i-lucide-calendar-x"
        :title="`${billing.plan.name} ends on ${date(subscription.ends_at)}`"
        description="The subscription is canceled. You can renew it in Manage billing until then."
      />
      <UAlert
        v-else-if="pastDue"
        color="warning"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        title="The last payment failed"
        description="Stripe is retrying the card. Update the payment method in Manage billing to keep your plan."
      />

      <section
        aria-labelledby="plans-heading"
        class="space-y-3"
      >
        <div class="flex flex-wrap items-end justify-between gap-3">
          <h2
            id="plans-heading"
            class="text-lg font-medium"
          >
            Plan
          </h2>
          <div
            v-if="billing.has_billing_account"
            class="flex flex-wrap items-center gap-3"
          >
            <p
              v-if="billing.payment_method"
              class="text-sm text-muted"
            >
              <span class="capitalize">{{ billing.payment_method.type }}</span>
              <template v-if="billing.payment_method.last_four">
                ending in {{ billing.payment_method.last_four }}
              </template>
            </p>
            <UButton
              to="/billing/invoices"
              label="Invoices"
              icon="i-lucide-receipt-text"
              color="neutral"
              variant="ghost"
              size="sm"
            />
            <UButton
              label="Payment details"
              icon="i-lucide-external-link"
              color="neutral"
              variant="ghost"
              size="sm"
              :loading="redirecting === 'portal'"
              :disabled="redirecting !== null"
              @click="openPortal"
            />
          </div>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
          <UPricingPlan
            v-for="plan in billing.plans"
            :key="plan.key"
            :title="plan.name"
            :price="plan.display_price"
            :badge="plan.key === billing.plan.key ? { label: 'Current plan', color: 'primary', variant: 'subtle' } : undefined"
            :highlight="plan.key === billing.plan.key"
            :features="[
              `${usd(plan.ai_monthly_budget_usd)} of AI each month`,
              'AI summaries, writing help and project chat',
              ...(plan.is_paid ? ['Cancel anytime; it runs until the paid period ends'] : []),
            ]"
            :button="planButton(plan)"
          />
        </div>
      </section>

      <section
        aria-labelledby="credits-heading"
        class="space-y-3"
      >
        <div>
          <h2
            id="credits-heading"
            class="text-lg font-medium"
          >
            AI credits
          </h2>
          <p class="text-sm text-muted">
            Need more AI this month? Credits add to this month's budget on any plan and expire at the end of the month (UTC).
          </p>
        </div>
        <div class="grid gap-4 md:grid-cols-2">
          <UPricingPlan
            v-for="pack in billing.credit_packs"
            :key="pack.key"
            orientation="horizontal"
            :title="pack.name"
            :price="pack.display_price"
            billing-cycle="one time"
            :description="`Adds ${usd(pack.amount_usd)} to the AI budget until ${monthEnd} (end of the month, UTC).`"
            :button="{
              label: !pack.available ? 'Not available yet' : aiOff ? 'AI is turned off' : 'Buy',
              loading: redirecting === pack.key,
              disabled: !pack.available || aiOff || redirecting !== null,
              onClick: () => buyCredits(pack),
            }"
          />
        </div>
      </section>

      <UCard v-if="budget">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-medium">
              AI budget this month
            </h2>
            <p class="text-sm text-muted">
              <template v-if="budget.limit_usd > 0">
                {{ usd(budget.spent_usd) }} of {{ usd(budget.limit_usd) }} used
                <template v-if="budget.credits_usd > 0">
                  ({{ usd(budget.limit_usd - budget.credits_usd) }} from {{ budget.own_limit_usd !== null && budget.own_limit_usd < budget.plan_limit_usd ? 'your own budget' : 'the plan' }}, {{ usd(budget.credits_usd) }} in credits)
                </template>.
              </template>
              <template v-else>
                AI is turned off for this organization.
              </template>
            </p>
          </div>
          <UButton
            v-if="can('ai_usage.view')"
            to="/ai-usage"
            label="View AI usage"
            color="neutral"
            variant="outline"
            size="sm"
          />
        </div>
        <UProgress
          :model-value="budgetUsed"
          :color="budgetUsed >= 100 ? 'error' : budgetUsed >= 80 ? 'warning' : 'primary'"
          class="mt-4"
          :aria-label="`${budgetUsed}% of this month's AI budget used`"
        />
      </UCard>
    </template>

    <div
      v-else
      class="space-y-4"
      aria-busy="true"
    >
      <USkeleton class="h-6 w-32" />
      <div class="grid gap-4 md:grid-cols-2">
        <USkeleton class="h-64" />
        <USkeleton class="h-64" />
      </div>
    </div>
  </div>
</template>
