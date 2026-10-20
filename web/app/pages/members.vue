<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent, TableColumn } from '@nuxt/ui'
import type { Invitation, Member, Role } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Members' })

const api = useApi()
const auth = useAuthStore()
const organizations = useOrganizationStore()
const toast = useToast()

// Reloaded by refreshNuxtData() when switching organizations.
const { data: roles, refresh: refreshRoles, error: rolesError } = await useAsyncData(
  'roles',
  () => api<{ data: Role[] }>('/api/roles').then(response => response.data),
  { default: () => [] },
)

const { canManageInvitations, invitableRoles, assignableRoles, canRemove, canRevoke, isSelf } = useOrganizationPermissions(roles)

const roleItems = (list: Role[]) => list.map(role => ({ label: role.name, value: role.id }))

const { data: members, refresh: refreshMembers, error: membersError } = await useAsyncData(
  'members',
  () => api<{ data: Member[] }>('/api/members').then(response => response.data),
  { default: () => [] },
)

const { data: invitations, refresh: refreshInvitations } = await useAsyncData(
  'invitations',
  () => canManageInvitations.value
    ? api<{ data: Invitation[] }>('/api/invitations').then(response => response.data)
    : Promise.resolve([]),
  { default: () => [], watch: [canManageInvitations] },
)

const formatDate = (value: string) =>
  new Date(value).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })

// Members table
const memberColumns: TableColumn<Member>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'role', header: 'Role' },
  { accessorKey: 'joined_at', header: 'Joined' },
  { id: 'actions', header: () => h('span', { class: 'sr-only' }, 'Actions') },
]

async function changeRole(member: Member, roleId: number) {
  if (roleId === member.role.id) {
    return
  }
  try {
    const response = await api<{ data: Member }>(`/api/members/${member.id}`, { method: 'PATCH', body: { role_id: roleId } })
    toast.add({ title: `${member.name} now has the ${response.data.role.name} role`, color: 'success' })
    if (isSelf(member)) {
      await auth.fetchMe() // my own permissions changed
    }
  }
  catch (error) {
    toast.add({ title: 'Could not change role', description: errorMessage(error), color: 'error' })
  }
  await Promise.all([refreshMembers(), refreshRoles()])
}

// Remove / leave confirmation
const pendingRemoval = ref<Member | null>(null)
const removing = ref(false)
const confirmOpen = computed({
  get: () => pendingRemoval.value !== null,
  set: (open: boolean) => {
    if (!open) pendingRemoval.value = null
  },
})

async function confirmRemoval() {
  const member = pendingRemoval.value
  if (!member) return

  removing.value = true
  try {
    await api(`/api/members/${member.id}`, { method: 'DELETE' })
    pendingRemoval.value = null

    if (isSelf(member)) {
      toast.add({ title: `You left ${organizations.current?.name ?? 'the organization'}`, color: 'success' })
      // The API moved us to another organization (or none).
      await auth.fetchMe()
      await organizations.fetch()
      await navigateTo('/')
      return
    }

    toast.add({ title: `${member.name} was removed`, color: 'success' })
    await refreshMembers()
  }
  catch (error) {
    toast.add({ title: 'Could not remove', description: errorMessage(error), color: 'error' })
  }
  finally {
    removing.value = false
  }
}

// Invite form
interface InviteState { email: string, role_id: number | undefined }
const inviteForm = useTemplateRef<Form<InviteState>>('inviteForm')
const inviteState = reactive<InviteState>({ email: '', role_id: undefined })

// Default to the invitable role that can do the least.
watch(invitableRoles, (list) => {
  if (!list.some(role => role.id === inviteState.role_id)) {
    inviteState.role_id = list
      .filter(role => !role.is_owner)
      .sort((a, b) => a.permissions.length - b.permissions.length)[0]?.id ?? list[0]?.id
  }
}, { immediate: true })
const inviting = ref(false)

function validateInvite(state: Partial<InviteState>): FormError[] {
  const errors: FormError[] = []
  if (!state.email) {
    errors.push({ name: 'email', message: 'Enter an email address.' })
  }
  if (!state.role_id) {
    errors.push({ name: 'role_id', message: 'Choose a role.' })
  }
  return errors
}

async function onInvite(event: FormSubmitEvent<InviteState>) {
  inviting.value = true
  try {
    await api('/api/invitations', { method: 'POST', body: event.data })
    toast.add({ title: `Invitation sent to ${event.data.email}`, color: 'success' })
    inviteState.email = ''
    await refreshInvitations()
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      inviteForm.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not send invitation', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    inviting.value = false
  }
}

async function revoke(invitation: Invitation) {
  try {
    await api(`/api/invitations/${invitation.id}`, { method: 'DELETE' })
    toast.add({ title: `Invitation for ${invitation.email} revoked`, color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Could not revoke invitation', description: errorMessage(error), color: 'error' })
  }
  await refreshInvitations()
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-semibold">
        Members
      </h1>
      <p class="text-sm text-muted">
        People in {{ organizations.current?.name ?? 'this organization' }}.
      </p>
    </div>

    <UAlert
      v-if="membersError"
      color="error"
      variant="subtle"
      title="Could not load members"
      :description="errorMessage(membersError)"
    />

    <!-- Without roles, role selects and the invite form can't offer anything. -->
    <UAlert
      v-if="rolesError"
      color="error"
      variant="subtle"
      title="Could not load roles"
      description="Changing roles and inviting are unavailable until they load."
    >
      <template #actions>
        <UButton
          label="Retry"
          color="neutral"
          variant="outline"
          size="sm"
          @click="() => refreshRoles()"
        />
      </template>
    </UAlert>

    <UCard :ui="{ body: 'p-0 sm:p-0' }">
      <UTable
        :data="members"
        :columns="memberColumns"
      >
        <template #name-cell="{ row }">
          <div>
            <p class="font-medium">
              {{ row.original.name }}
              <span
                v-if="isSelf(row.original)"
                class="text-muted font-normal"
              >(you)</span>
            </p>
            <p class="text-muted text-xs">
              {{ row.original.email }}
            </p>
          </div>
        </template>

        <template #role-cell="{ row }">
          <USelect
            v-if="assignableRoles(row.original).length"
            :model-value="row.original.role.id"
            :items="roleItems(assignableRoles(row.original))"
            size="sm"
            class="w-36"
            :aria-label="`Role of ${row.original.name}`"
            @update:model-value="(roleId) => changeRole(row.original, roleId as number)"
          />
          <UBadge
            v-else
            :label="row.original.role.name"
            variant="subtle"
          />
        </template>

        <template #joined_at-cell="{ row }">
          {{ formatDate(row.original.joined_at) }}
        </template>

        <template #actions-cell="{ row }">
          <div class="flex justify-end">
            <UButton
              v-if="isSelf(row.original)"
              label="Leave"
              color="neutral"
              variant="ghost"
              size="sm"
              @click="pendingRemoval = row.original"
            />
            <UButton
              v-else-if="canRemove(row.original)"
              label="Remove"
              color="error"
              variant="ghost"
              size="sm"
              @click="pendingRemoval = row.original"
            />
          </div>
        </template>
      </UTable>
    </UCard>

    <UCard v-if="canManageInvitations">
      <template #header>
        <h2 class="font-medium">
          Invite someone
        </h2>
      </template>

      <UForm
        ref="inviteForm"
        :state="inviteState"
        :validate="validateInvite"
        class="flex flex-col gap-3 sm:flex-row sm:items-start"
        @submit="onInvite"
      >
        <UFormField
          name="email"
          class="flex-1"
        >
          <UInput
            v-model="inviteState.email"
            type="email"
            placeholder="name@example.com"
            class="w-full"
          />
        </UFormField>
        <UFormField name="role_id">
          <USelect
            v-model="inviteState.role_id"
            :items="roleItems(invitableRoles)"
            placeholder="Role"
            aria-label="Role"
            class="w-full sm:w-36"
          />
        </UFormField>
        <UButton
          type="submit"
          label="Send invitation"
          :loading="inviting"
        />
      </UForm>

      <template v-if="invitations.length">
        <USeparator class="my-4" />
        <h3 class="mb-2 text-sm font-medium">
          Pending invitations
        </h3>
        <ul class="divide-y divide-default">
          <li
            v-for="invitation in invitations"
            :key="invitation.id"
            class="flex items-center justify-between gap-4 py-2 text-sm"
          >
            <div>
              <p>
                {{ invitation.email }}
                <UBadge
                  :label="invitation.role?.name ?? 'Role deleted'"
                  :color="invitation.role ? 'primary' : 'warning'"
                  variant="subtle"
                  size="sm"
                  class="ml-1"
                />
                <UBadge
                  v-if="invitation.is_expired"
                  label="Expired"
                  color="warning"
                  variant="subtle"
                  size="sm"
                  class="ml-1"
                />
              </p>
              <p class="text-muted text-xs">
                Invited by {{ invitation.invited_by?.name ?? 'a former member' }}
                · {{ invitation.is_expired ? 'expired' : 'expires' }} {{ formatDate(invitation.expires_at) }}
              </p>
            </div>
            <UButton
              v-if="canRevoke(invitation)"
              label="Revoke"
              color="neutral"
              variant="ghost"
              size="sm"
              @click="revoke(invitation)"
            />
          </li>
        </ul>
      </template>
    </UCard>

    <UModal
      v-model:open="confirmOpen"
      :title="pendingRemoval && isSelf(pendingRemoval) ? 'Leave organization?' : 'Remove member?'"
    >
      <template #body>
        <p
          v-if="pendingRemoval && isSelf(pendingRemoval)"
          class="text-sm"
        >
          You will lose access to {{ organizations.current?.name }}. You'll need a new invitation to come back.
        </p>
        <p
          v-else-if="pendingRemoval"
          class="text-sm"
        >
          {{ pendingRemoval.name }} will lose access to {{ organizations.current?.name }}.
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="ghost"
            @click="pendingRemoval = null"
          />
          <UButton
            :label="pendingRemoval && isSelf(pendingRemoval) ? 'Leave' : 'Remove'"
            color="error"
            :loading="removing"
            @click="confirmRemoval"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
