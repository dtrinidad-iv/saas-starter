<script setup lang="ts">
import type { ComponentPublicInstance } from 'vue'
import type { Project, ProjectPayload } from '~/types/api'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const api = useApi()
const organizations = useOrganizationStore()
const toast = useToast()

const id = computed(() => Number(route.params.id))
const validId = computed(() => /^\d+$/.test(String(route.params.id)))

// Errors come back as data so the page can show "not found" (404 also covers
// another organization's project).
const { data: result, refresh } = await useAsyncData(`project:${route.params.id}`, async () => {
  if (!validId.value) {
    return { ok: false as const, status: 404, message: '' }
  }
  try {
    const response = await api<{ data: Project }>(`/api/projects/${id.value}`)
    return { ok: true as const, project: response.data }
  }
  catch (error) {
    return { ok: false as const, status: isFetchError(error) ? error.statusCode ?? 0 : 0, message: errorMessage(error) }
  }
})

const project = computed(() => (result.value?.ok ? result.value.project : null))
const notFound = computed(() => result.value?.ok === false && result.value.status === 404)
useSeoMeta({ title: () => project.value?.name ?? (notFound.value ? 'Project not found' : 'Project') })

// A server-rendered "not found" page should also be an HTTP 404.
const requestEvent = useRequestEvent()
if (requestEvent && notFound.value) {
  setResponseStatus(requestEvent, 404)
}

// This project belongs to the organization we were in; after switching, go back to the list.
watch(() => organizations.current?.id, (now, before) => {
  if (before !== undefined && now !== before) {
    navigateTo('/projects')
  }
})

const formatDate = (value: string) =>
  new Date(value).toLocaleString('en-US', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })

// Edit
const editing = ref(false)
const saving = ref(false)
const editForm = useTemplateRef<{ setErrors: (errors: ReturnType<typeof toFormErrors>) => void }>('editForm')
const editButton = useTemplateRef<ComponentPublicInstance>('editButton')

// The form that had focus disappears; put keyboard focus back on Edit.
async function closeEdit() {
  editing.value = false
  await nextTick()
  editButton.value?.$el?.focus?.()
}

async function save(payload: ProjectPayload) {
  saving.value = true
  try {
    await api(`/api/projects/${id.value}`, { method: 'PATCH', body: payload })
    toast.add({ title: 'Project saved', color: 'success' })
    await refresh()
    await closeEdit()
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      editForm.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not save project', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    saving.value = false
  }
}

// AI chat
const chatOpen = ref(false)

// Delete
const deleteOpen = ref(false)
const deleting = ref(false)

async function destroy() {
  deleting.value = true
  try {
    await api(`/api/projects/${id.value}`, { method: 'DELETE' })
    toast.add({ title: `Deleted ${project.value?.name}`, color: 'success' })
    await navigateTo('/projects')
  }
  catch (error) {
    toast.add({ title: 'Could not delete project', description: errorMessage(error), color: 'error' })
  }
  finally {
    deleting.value = false
    deleteOpen.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <UButton
      to="/projects"
      label="All projects"
      icon="i-lucide-arrow-left"
      color="neutral"
      variant="ghost"
      class="-ml-2"
    />

    <template v-if="project">
      <UCard v-if="editing">
        <template #header>
          <h1 class="text-xl font-semibold">
            Edit project
          </h1>
        </template>
        <ProjectForm
          ref="editForm"
          :initial="project"
          :project-id="project.id"
          submit-label="Save"
          focus-on-mount
          :loading="saving"
          @submit="save"
          @cancel="closeEdit"
        />
      </UCard>

      <template v-else>
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 class="text-2xl font-semibold">
              {{ project.name }}
            </h1>
            <p class="text-sm text-muted">
              Created by {{ project.creator?.name ?? 'a former member' }} · updated {{ formatDate(project.updated_at) }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <UButton
              v-if="project.can.chat"
              label="Ask AI"
              icon="i-lucide-message-circle"
              color="neutral"
              variant="outline"
              @click="chatOpen = true"
            />
            <UButton
              v-if="project.can.update"
              ref="editButton"
              label="Edit"
              icon="i-lucide-pencil"
              color="neutral"
              variant="outline"
              @click="editing = true"
            />
            <UButton
              v-if="project.can.delete"
              label="Delete"
              icon="i-lucide-trash-2"
              color="error"
              variant="outline"
              @click="deleteOpen = true"
            />
          </div>
        </div>

        <UCard>
          <p
            v-if="project.description"
            class="whitespace-pre-line text-sm"
          >
            {{ project.description }}
          </p>
          <p
            v-else
            class="text-sm text-muted"
          >
            No description.
          </p>
        </UCard>

        <ProjectSummaryCard
          :project-id="project.id"
          :can-generate="project.can.update"
        />

        <ProjectChat
          v-if="project.can.chat"
          v-model:open="chatOpen"
          :project-id="project.id"
          :project-name="project.name"
        />
      </template>

      <!-- Can't be dismissed while the delete is in flight. -->
      <UModal
        v-model:open="deleteOpen"
        title="Delete project?"
        :dismissible="!deleting"
        :close="{ disabled: deleting }"
      >
        <template #body>
          <p class="text-sm">
            <strong>{{ project.name }}</strong> will be deleted for everyone in {{ organizations.current?.name }}. This can't be undone.
          </p>
          <div class="mt-4 flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="ghost"
              :disabled="deleting"
              @click="deleteOpen = false"
            />
            <UButton
              label="Delete"
              color="error"
              :loading="deleting"
              @click="destroy"
            />
          </div>
        </template>
      </UModal>
    </template>

    <UCard v-else>
      <h1 class="text-xl font-semibold">
        {{ notFound ? 'Project not found' : 'Could not load project' }}
      </h1>
      <p class="mt-2 text-sm text-muted">
        {{ notFound
          ? 'It may have been deleted, or it belongs to another organization.'
          : (result && !result.ok ? result.message : '') }}
      </p>
    </UCard>
  </div>
</template>
