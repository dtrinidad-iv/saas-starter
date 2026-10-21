<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AdminOrganizationDetail } from '~/types/api'

definePageMeta({ middleware: 'auth' })

const api = useApi()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const id = String(route.params.id)

const { data: organization, failure, refresh, update } = await useAdminData(`admin-organization:${id}`, async () => {
  if (!/^\d+$/.test(id)) {
    throw createError({ statusCode: 404 })
  }
  return (await api<{ data: AdminOrganizationDetail }>(`/api/admin/organizations/${id}`)).data
})
useSeoMeta({ title: () => `${organization.value?.name ?? 'Organization'} · Admin` })

const usd = (value: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value)

const budgetUsed = computed(() => {
  const budget = organization.value?.ai_budget
  if (!budget || budget.limit_usd <= 0) {
    return budget && budget.spent_usd > 0 ? 100 : 0
  }
  return Math.min(100, Math.round((budget.spent_usd / budget.limit_usd) * 100))
})
const budgetColor = computed(() => (budgetUsed.value >= 100 ? 'error' : budgetUsed.value >= 80 ? 'warning' : 'primary'))

const stats = computed(() => organization.value
  ? [
      { label: 'Members', value: organization.value.counts.members },
      { label: 'Projects', value: organization.value.counts.projects },
      { label: 'Pending invitations', value: organization.value.counts.pending_invitations },
    ]
  : [])

type Member = AdminOrganizationDetail['members'][number]
const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<Member>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'email', header: 'Email', meta: wideOnly },
  { id: 'role', header: 'Role' },
  { accessorKey: 'joined_at', header: 'Joined (UTC)', meta: wideOnly },
  { id: 'actions', header: () => h('span', { class: 'sr-only' }, 'Actions'), meta: { class: { td: 'text-right' } } },
]

// Make Owner: for an organization whose owners are gone. Never yourself.
const canMakeOwner = (member: Member) => !member.role.is_owner && member.id !== auth.user?.id
const makingOwner = ref<Member | null>(null)
const ownerModalOpen = computed({
  get: () => makingOwner.value !== null,
  set: (open: boolean) => {
    if (!open) makingOwner.value = null
  },
})

// The modal runs this for the chosen member (it's only shown while one is chosen).
async function makeOwner(reason: string) {
  const member = makingOwner.value
  if (!member) throw new Error('No member chosen.')
  const response = await api<{ data: AdminOrganizationDetail }>(`/api/admin/organizations/${id}/owners`, {
    method: 'POST',
    body: { user_id: member.id, reason },
  })
  return response.data
}

function onOwnerMade(updated: AdminOrganizationDetail) {
  const name = makingOwner.value?.name
  update(updated)
  toast.add({ title: `${name} is now an Owner of ${updated.name}`, color: 'success' })
}

const formatDate = (value: string) =>
  new Date(value).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })
</script>

<template>
  <div class="space-y-6">
    <UButton
      to="/admin/organizations"
      label="Organizations"
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

    <template v-else-if="organization">
      <div>
        <h1 class="text-2xl font-semibold break-words">
          {{ organization.name }}
        </h1>
        <p class="text-sm text-muted">
          Created {{ formatDate(organization.created_at) }}
        </p>
      </div>

      <div class="grid gap-3 lg:grid-cols-2">
        <UCard>
          <p class="text-sm text-muted">
            Plan
          </p>
          <p class="mt-1 flex flex-wrap items-center gap-2 text-lg font-semibold">
            {{ organization.plan.name }}
            <UBadge
              v-if="organization.subscription"
              :label="organization.subscription.status"
              :color="['active', 'trialing'].includes(organization.subscription.status) ? 'success' : 'warning'"
              variant="subtle"
              class="capitalize"
            />
          </p>
          <p class="mt-1 text-sm text-muted">
            <template v-if="organization.subscription?.ends_at">
              Ends {{ formatDate(organization.subscription.ends_at) }}
            </template>
            <template v-else-if="organization.subscription">
              Subscribed since {{ formatDate(organization.subscription.created_at) }}
            </template>
            <template v-else>
              Never subscribed
            </template>
          </p>
        </UCard>

        <UCard>
          <p class="text-sm text-muted">
            AI budget this month
          </p>
          <p class="mt-1 text-lg font-semibold">
            {{ usd(organization.ai_budget.spent_usd) }} of {{ usd(organization.ai_budget.limit_usd) }}
          </p>
          <UProgress
            :model-value="budgetUsed"
            :color="budgetColor"
            class="mt-3"
            :aria-label="`${budgetUsed}% of the monthly AI budget used`"
          />
          <p
            v-if="organization.ai_budget.credits_usd > 0"
            class="mt-2 text-xs text-muted"
          >
            Includes {{ usd(organization.ai_budget.credits_usd) }} of AI credits bought this month.
          </p>
        </UCard>
      </div>

      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <UCard
          v-for="stat in stats"
          :key="stat.label"
        >
          <p class="text-sm text-muted">
            {{ stat.label }}
          </p>
          <p class="mt-1 text-2xl font-semibold">
            {{ stat.value }}
          </p>
        </UCard>
      </div>

      <section class="space-y-3">
        <h2 class="text-lg font-semibold">
          Members
        </h2>
        <UCard :ui="{ body: 'p-0 sm:p-0' }">
          <UTable
            :data="organization.members"
            :columns="columns"
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
            </template>
            <template #email-cell="{ row }">
              <span class="break-all">{{ row.original.email }}</span>
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
            <template #actions-cell="{ row }">
              <UButton
                v-if="canMakeOwner(row.original)"
                label="Make Owner"
                icon="i-lucide-crown"
                color="neutral"
                variant="outline"
                size="sm"
                :aria-label="`Make ${row.original.name} Owner`"
                @click="makingOwner = row.original"
              />
            </template>
            <template #empty>
              <p class="py-6 text-center text-sm text-muted">
                No members.
              </p>
            </template>
          </UTable>
        </UCard>
        <p
          v-if="organization.counts.members > organization.members.length"
          class="text-sm text-muted"
        >
          Showing the first {{ organization.members.length }} of {{ organization.counts.members }} members.
        </p>
      </section>

      <AdminActionModal
        v-if="makingOwner"
        v-model:open="ownerModalOpen"
        :title="`Make ${makingOwner.name} Owner?`"
        :description="`${makingOwner.name} gets every permission in ${organization.name}, including members, roles and billing. They get an email, and the organization's audit log shows a platform admin did it.`"
        confirm-label="Make Owner"
        :action="makeOwner"
        @done="onOwnerMade"
      />
    </template>
  </div>
</template>
