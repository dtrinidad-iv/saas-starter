<script setup lang="ts">
import type { DropdownMenuItem, Form, FormError, FormSubmitEvent } from '@nuxt/ui'

const organizations = useOrganizationStore()
const toast = useToast()

const switching = ref(false)

const items = computed<DropdownMenuItem[][]>(() => [
  [{ label: 'Organizations', type: 'label' }],
  organizations.organizations.map(organization => ({
    label: organization.name,
    description: organization.role.name,
    type: 'checkbox' as const,
    checked: organization.id === organizations.current?.id,
    onSelect: () => switchTo(organization.id),
  })),
  [{ label: 'Create organization', icon: 'i-lucide-plus', onSelect: () => { createOpen.value = true } }],
])

// Refresh the list each time the menu opens, so membership changes show up.
function onOpen(open: boolean) {
  if (open) {
    organizations.fetch().catch(error => toast.add({ title: 'Could not load organizations', description: errorMessage(error), color: 'error' }))
  }
}

async function switchTo(organizationId: number) {
  if (organizationId === organizations.current?.id || switching.value) {
    return
  }

  switching.value = true
  try {
    await organizations.switchTo(organizationId)
  }
  catch (error) {
    toast.add({ title: 'Could not switch organization', description: errorMessage(error), color: 'error' })
    // Membership may have changed; show the server's view.
    await organizations.fetch().catch(() => {})
  }
  finally {
    switching.value = false
  }
}

// Create organization modal
const createOpen = ref(false)
const creating = ref(false)
const createForm = useTemplateRef<Form<{ name: string }>>('createForm')
const createState = reactive({ name: '' })

function validateCreate(state: Partial<{ name: string }>): FormError[] {
  return state.name?.trim() ? [] : [{ name: 'name', message: 'Enter a name.' }]
}

async function onCreate(event: FormSubmitEvent<{ name: string }>) {
  creating.value = true
  try {
    await organizations.create(event.data.name.trim())
    createOpen.value = false
    createState.name = ''
    toast.add({ title: 'Organization created', color: 'success' })
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      createForm.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not create organization', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    creating.value = false
  }
}
</script>

<template>
  <div>
    <UDropdownMenu
      :items="items"
      :content="{ align: 'start' }"
      @update:open="onOpen"
    >
      <UButton
        color="neutral"
        variant="outline"
        :label="organizations.current?.name ?? 'No organization'"
        :loading="switching"
        trailing-icon="i-lucide-chevrons-up-down"
        class="max-w-56"
        :ui="{ label: 'truncate' }"
      />
    </UDropdownMenu>

    <UModal
      v-model:open="createOpen"
      title="Create organization"
    >
      <template #body>
        <UForm
          ref="createForm"
          :state="createState"
          :validate="validateCreate"
          class="space-y-4"
          @submit="onCreate"
        >
          <UFormField
            label="Name"
            name="name"
          >
            <UInput
              v-model="createState.name"
              autofocus
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              label="Cancel"
              @click="createOpen = false"
            />
            <UButton
              type="submit"
              label="Create"
              :loading="creating"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
