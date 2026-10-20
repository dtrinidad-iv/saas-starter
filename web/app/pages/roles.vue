<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent, TableColumn } from '@nuxt/ui'
import type { Permission, PermissionInfo, Role, RolePayload } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Roles' })

const api = useApi()
const organizations = useOrganizationStore()
const toast = useToast()

// Reloaded by refreshNuxtData() when switching organizations.
const { data: roles, refresh: refreshRoles, error: rolesError, status } = await useAsyncData(
  'roles',
  () => api<{ data: Role[] }>('/api/roles').then(response => response.data),
  { default: () => [] },
)

const { data: catalogue, error: catalogueError, refresh: refreshCatalogue } = await useAsyncData(
  'permissions',
  () => api<{ data: PermissionInfo[] }>('/api/permissions').then(response => response.data),
  { default: () => [] },
)

const { role: myRole, isOwner, can, canManageRoles, canChangeRole } = useOrganizationPermissions(roles)

const retry = () => Promise.all([refreshRoles(), refreshCatalogue()])

const labels = computed(() => Object.fromEntries(catalogue.value.map(p => [p.value, p.label])) as Record<Permission, string>)

/** The catalogue by group, in catalogue order. */
const groups = computed(() => {
  const byGroup = new Map<string, PermissionInfo[]>()
  for (const permission of catalogue.value) {
    byGroup.set(permission.group, [...(byGroup.get(permission.group) ?? []), permission])
  }
  return [...byGroup].map(([name, permissions]) => ({ name, permissions }))
})

function permissionSummary(role: Role) {
  if (role.is_owner) {
    return 'All permissions'
  }
  if (!role.permissions.length) {
    return 'No permissions'
  }
  return role.permissions.map(permission => labels.value[permission] ?? permission).join(', ')
}

const columns: TableColumn<Role>[] = [
  { accessorKey: 'name', header: 'Role' },
  { accessorKey: 'permissions', header: 'Permissions' },
  { accessorKey: 'members_count', header: 'Members' },
  { id: 'actions', header: () => h('span', { class: 'sr-only' }, 'Actions') },
]

// Create / edit modal. editing: null = closed, 'new' = creating.
const editing = ref<Role | 'new' | null>(null)
const formOpen = computed({
  get: () => editing.value !== null,
  set: (open: boolean) => {
    if (!open) editing.value = null
  },
})
const form = useTemplateRef<Form<RolePayload>>('form')
const state = reactive<RolePayload>({ name: '', permissions: [] })
const saving = ref(false)

function openForm(role: Role | 'new') {
  state.name = role === 'new' ? '' : role.name
  state.permissions = role === 'new' ? [] : [...role.permissions]
  editing.value = role
}

/** Non-owners can only grant permissions they have themselves. */
function groupItems(permissions: PermissionInfo[]) {
  return permissions.map(permission => ({
    value: permission.value,
    label: permission.label,
    disabled: !isOwner.value && !can(permission.value),
  }))
}

function groupValue(permissions: PermissionInfo[]) {
  return state.permissions.filter(value => permissions.some(permission => permission.value === value))
}

function setGroupValue(permissions: PermissionInfo[], selected: Permission[]) {
  const inGroup = new Set(permissions.map(permission => permission.value))
  state.permissions = [...state.permissions.filter(value => !inGroup.has(value)), ...selected]
}

function validate(value: Partial<RolePayload>): FormError[] {
  return value.name?.trim() ? [] : [{ name: 'name', message: 'Enter a name.' }]
}

async function onSubmit(event: FormSubmitEvent<RolePayload>) {
  const target = editing.value
  if (!target) return

  saving.value = true
  try {
    const body = { name: event.data.name.trim(), permissions: event.data.permissions }
    if (target === 'new') {
      await api('/api/roles', { method: 'POST', body })
      toast.add({ title: `Role ${body.name} created`, color: 'success' })
    }
    else {
      await api(`/api/roles/${target.id}`, { method: 'PATCH', body })
      toast.add({ title: `Role ${body.name} saved`, color: 'success' })
    }
    editing.value = null
    await refreshRoles()
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      form.value?.setErrors(fieldErrors.map(fieldError => ({
        ...fieldError,
        name: fieldError.name?.startsWith('permissions') ? 'permissions' : fieldError.name,
      })))
    }
    else {
      toast.add({ title: 'Could not save role', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    saving.value = false
  }
}

// Delete confirmation
const pendingDelete = ref<Role | null>(null)
const deleting = ref(false)
const confirmOpen = computed({
  get: () => pendingDelete.value !== null,
  set: (open: boolean) => {
    if (!open) pendingDelete.value = null
  },
})

async function confirmDelete() {
  const role = pendingDelete.value
  if (!role) return

  deleting.value = true
  try {
    await api(`/api/roles/${role.id}`, { method: 'DELETE' })
    pendingDelete.value = null
    toast.add({ title: `Role ${role.name} deleted`, color: 'success' })
    await refreshRoles()
  }
  catch (error) {
    // e.g. 422: still used by members or pending invitations.
    toast.add({ title: 'Could not delete role', description: errorMessage(error), color: 'error' })
  }
  finally {
    deleting.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">
          Roles
        </h1>
        <p class="text-sm text-muted">
          What people in {{ organizations.current?.name ?? 'this organization' }} can do.
        </p>
      </div>
      <UButton
        v-if="canManageRoles"
        label="New role"
        icon="i-lucide-plus"
        :disabled="!catalogue.length"
        @click="openForm('new')"
      />
    </div>

    <UAlert
      v-if="rolesError || catalogueError"
      color="error"
      variant="subtle"
      title="Could not load roles"
      :description="errorMessage(rolesError ?? catalogueError)"
    >
      <template #actions>
        <UButton
          label="Retry"
          color="neutral"
          variant="outline"
          size="sm"
          @click="retry"
        />
      </template>
    </UAlert>

    <UCard
      v-if="!rolesError"
      :ui="{ body: 'p-0 sm:p-0' }"
    >
      <UTable
        :data="roles"
        :columns="columns"
        :loading="status === 'pending'"
        :ui="{ td: 'align-top' }"
      >
        <template #name-cell="{ row }">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="font-medium">{{ row.original.name }}</span>
            <UBadge
              v-if="row.original.is_owner"
              label="Built-in"
              color="neutral"
              variant="subtle"
              size="sm"
            />
            <UBadge
              v-if="row.original.id === myRole?.id"
              label="Your role"
              variant="subtle"
              size="sm"
            />
          </div>
        </template>

        <template #permissions-cell="{ row }">
          <p class="max-w-md text-sm whitespace-normal text-muted">
            {{ permissionSummary(row.original) }}
          </p>
        </template>

        <template #actions-cell="{ row }">
          <div
            v-if="canChangeRole(row.original) && catalogue.length"
            class="flex justify-end gap-1"
          >
            <UTooltip text="Edit">
              <UButton
                icon="i-lucide-pencil"
                color="neutral"
                variant="ghost"
                size="sm"
                :aria-label="`Edit ${row.original.name}`"
                @click="openForm(row.original)"
              />
            </UTooltip>
            <UTooltip text="Delete">
              <UButton
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                size="sm"
                :aria-label="`Delete ${row.original.name}`"
                @click="pendingDelete = row.original"
              />
            </UTooltip>
          </div>
        </template>

        <template #empty>
          <p class="py-6 text-center text-sm text-muted">
            No roles yet.
          </p>
        </template>
      </UTable>
    </UCard>

    <UModal
      v-model:open="formOpen"
      :title="editing === 'new' ? 'New role' : 'Edit role'"
    >
      <template #body>
        <UForm
          ref="form"
          :state="state"
          :validate="validate"
          class="space-y-5"
          @submit="onSubmit"
        >
          <UFormField
            label="Name"
            name="name"
          >
            <UInput
              v-model="state.name"
              autofocus
              maxlength="50"
              class="w-full"
            />
          </UFormField>

          <UFormField
            name="permissions"
            :help="isOwner ? undefined : 'You can only grant permissions you have yourself.'"
          >
            <div class="space-y-4">
              <UCheckboxGroup
                v-for="group in groups"
                :key="group.name"
                :legend="group.name"
                :items="groupItems(group.permissions)"
                :model-value="groupValue(group.permissions)"
                @update:model-value="(selected) => setGroupValue(group.permissions, selected as Permission[])"
              />
            </div>
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Cancel"
              @click="editing = null"
            />
            <UButton
              type="submit"
              :label="editing === 'new' ? 'Create role' : 'Save'"
              :loading="saving"
            />
          </div>
        </UForm>
      </template>
    </UModal>

    <UModal
      v-model:open="confirmOpen"
      title="Delete role?"
    >
      <template #body>
        <p
          v-if="pendingDelete"
          class="text-sm"
        >
          The {{ pendingDelete.name }} role will be deleted. Roles that members or pending invitations use can't be deleted.
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="ghost"
            @click="pendingDelete = null"
          />
          <UButton
            label="Delete"
            color="error"
            :loading="deleting"
            @click="confirmDelete"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
