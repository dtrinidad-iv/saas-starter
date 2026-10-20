<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Paginated, Project, ProjectPayload } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Projects' })

const api = useApi()
const route = useRoute()
const router = useRouter()
const organizations = useOrganizationStore()
const toast = useToast()
const { can } = useOrganizationPermissions()

// Search and page live in the URL (?search=&page=) so reload and Back keep them.
const queryPage = () => Math.max(1, Number.parseInt(String(route.query.page ?? ''), 10) || 1)
const querySearch = () => (typeof route.query.search === 'string' ? route.query.search.trim() : '')

const page = ref(queryPage())
const search = ref(querySearch())
const debouncedSearch = ref(search.value)
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(searchTimer)
  // Already applied (e.g. set from the URL below): keep the page.
  if (value.trim() === debouncedSearch.value) {
    return
  }
  searchTimer = setTimeout(() => {
    debouncedSearch.value = value.trim()
    page.value = 1
  }, 300)
})

// Pin the path: while the next page loads, this (leaving) page is still mounted and a
// late update must not rewrite the new page's URL.
watch([page, debouncedSearch], ([p, s]) => {
  router.replace({ path: route.path, query: { ...route.query, page: p > 1 ? String(p) : undefined, search: s || undefined } })
})
onBeforeUnmount(() => clearTimeout(searchTimer))

// The URL changed from outside (Back/Forward, or the Projects link): follow it.
watch(() => route.query, () => {
  const [p, s] = [queryPage(), querySearch()]
  if (p !== page.value || s !== debouncedSearch.value) {
    clearTimeout(searchTimer)
    search.value = s
    debouncedSearch.value = s
    page.value = p
  }
})

// A different organization has different projects: start over, and close any
// edit or delete modal still holding the previous organization's project.
watch(() => organizations.current?.id, () => {
  editOpen.value = false
  deleteOpen.value = false
  clearTimeout(searchTimer)
  search.value = ''
  debouncedSearch.value = ''
  page.value = 1
})

// Also reloaded by refreshNuxtData() when switching organizations.
const { data: projects, status, error, refresh } = await useAsyncData(
  'projects',
  async () => {
    const fetchPage = (p: number) => api<Paginated<Project>>('/api/projects', {
      query: { page: p, search: debouncedSearch.value || undefined },
    })
    const result = await fetchPage(page.value)
    // A page past the end (old link, or the last item on the last page was deleted):
    // load the last page instead. Done here so server and browser render the same data.
    if (result.meta.total > 0 && page.value > result.meta.last_page) {
      page.value = result.meta.last_page
      return fetchPage(result.meta.last_page)
    }
    return result
  },
  { watch: [page, debouncedSearch] },
)

// After hydration the browser still has the URL's page; adopt the page the server
// actually loaded (this also fixes the URL).
if (projects.value && projects.value.meta.current_page !== page.value) {
  page.value = projects.value.meta.current_page
}

// On phones only the Project column shows; creator and date move under the name.
const wideOnly = { class: { th: 'hidden sm:table-cell', td: 'hidden sm:table-cell' } }
const columns: TableColumn<Project>[] = [
  { accessorKey: 'name', header: 'Project' },
  { accessorKey: 'creator', header: 'Created by', meta: wideOnly },
  { accessorKey: 'updated_at', header: 'Updated', meta: wideOnly },
  { id: 'actions', header: () => h('span', { class: 'sr-only' }, 'Actions'), meta: { class: { td: 'text-right w-0' } } },
]

const formatDate = (value: string) =>
  new Date(value).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })

// New project modal
const createOpen = ref(false)
const creating = ref(false)
const createForm = useTemplateRef<{ setErrors: (errors: ReturnType<typeof toFormErrors>) => void }>('createForm')

async function create(payload: ProjectPayload) {
  creating.value = true
  try {
    const response = await api<{ data: Project }>('/api/projects', { method: 'POST', body: payload })
    createOpen.value = false
    toast.add({ title: `Created ${response.data.name}`, color: 'success' })
    await navigateTo(`/projects/${response.data.id}`)
  }
  catch (error) {
    const fieldErrors = toFormErrors(error)
    if (fieldErrors.length) {
      createForm.value?.setErrors(fieldErrors)
    }
    else {
      toast.add({ title: 'Could not create project', description: errorMessage(error), color: 'error' })
    }
  }
  finally {
    creating.value = false
  }
}

// Edit and delete from the list: one modal each. The selected project stays set
// until its modal has finished closing, so the content doesn't go blank mid-fade.
const editing = ref<Project | null>(null)
const editOpen = ref(false)
const saving = ref(false)
const editForm = useTemplateRef<{ setErrors: (errors: ReturnType<typeof toFormErrors>) => void }>('editForm')

const deleting = ref<Project | null>(null)
const deleteOpen = ref(false)
const removing = ref(false)

// These modals have no trigger element. The dialog would refocus whatever was
// focused when it opened, but the table reuses row elements, so after a delete
// that button can belong to the next project. Take over its close-focus step:
// back to the row button, or to the search field after a delete.
const searchInput = useTemplateRef<{ inputRef: HTMLInputElement | null }>('searchInput')
let returnFocusTo: HTMLElement | null = null

const modalContent = {
  onCloseAutoFocus(event: Event) {
    event.preventDefault()
    const target = returnFocusTo?.isConnected ? returnFocusTo : searchInput.value?.inputRef
    returnFocusTo = null
    target?.focus()
  },
}

function startEdit(event: Event, project: Project) {
  returnFocusTo = event.currentTarget as HTMLElement
  editing.value = project
  editOpen.value = true
}

function startDelete(event: Event, project: Project) {
  returnFocusTo = event.currentTarget as HTMLElement
  deleting.value = project
  deleteOpen.value = true
}

async function save(payload: ProjectPayload) {
  if (!editing.value) {
    return
  }
  saving.value = true
  try {
    await api(`/api/projects/${editing.value.id}`, { method: 'PATCH', body: payload })
    toast.add({ title: `Saved ${payload.name}`, color: 'success' })
    editOpen.value = false
    await refresh()
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

async function destroy() {
  if (!deleting.value) {
    return
  }
  const project = deleting.value
  removing.value = true
  try {
    await api(`/api/projects/${project.id}`, { method: 'DELETE' })
    toast.add({ title: `Deleted ${project.name}`, color: 'success' })
    // The table reuses row elements, so the old button may now belong to the next
    // project; land on the search field instead.
    returnFocusTo = null
    deleteOpen.value = false
    // Also moves back a page if this was the last project on it.
    await refresh()
  }
  catch (error) {
    toast.add({ title: 'Could not delete project', description: errorMessage(error), color: 'error' })
  }
  finally {
    removing.value = false
  }
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold">
          Projects
        </h1>
        <p class="text-sm text-muted">
          In {{ organizations.current?.name ?? 'this organization' }}.
        </p>
      </div>
      <!-- The button is the modal's trigger, so focus returns to it when the modal closes. -->
      <UModal
        v-if="can('projects.create')"
        v-model:open="createOpen"
        title="New project"
      >
        <UButton
          label="New project"
          icon="i-lucide-plus"
        />

        <template #body>
          <ProjectForm
            ref="createForm"
            submit-label="Create project"
            :loading="creating"
            @submit="create"
            @cancel="createOpen = false"
          />
        </template>
      </UModal>
    </div>

    <UInput
      ref="searchInput"
      v-model="search"
      placeholder="Search projects"
      aria-label="Search projects"
      type="search"
      icon="i-lucide-search"
      class="w-full sm:w-72"
    />

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      title="Could not load projects"
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
        :data="projects?.data ?? []"
        :columns="columns"
        :loading="status === 'pending'"
      >
        <!-- Only the name is the link, so its accessible name is just the project name. -->
        <template #name-cell="{ row }">
          <NuxtLink
            :to="`/projects/${row.original.id}`"
            class="font-medium hover:underline"
          >
            {{ row.original.name }}
          </NuxtLink>
          <p
            v-if="row.original.description"
            class="text-muted line-clamp-1 max-w-md text-xs"
          >
            {{ row.original.description }}
          </p>
          <p class="text-muted text-xs sm:hidden">
            by {{ row.original.creator?.name ?? 'Former member' }} · {{ formatDate(row.original.updated_at) }}
          </p>
        </template>

        <template #creator-cell="{ row }">
          {{ row.original.creator?.name ?? 'Former member' }}
        </template>

        <template #updated_at-cell="{ row }">
          {{ formatDate(row.original.updated_at) }}
        </template>

        <!-- View for everyone; Edit and Delete only when the API says this user may. -->
        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UTooltip text="View">
              <UButton
                :to="`/projects/${row.original.id}`"
                icon="i-lucide-eye"
                color="neutral"
                variant="ghost"
                size="sm"
                :aria-label="`View ${row.original.name}`"
              />
            </UTooltip>
            <UTooltip
              v-if="row.original.can.update"
              text="Edit"
            >
              <UButton
                icon="i-lucide-pencil"
                color="neutral"
                variant="ghost"
                size="sm"
                :aria-label="`Edit ${row.original.name}`"
                @click="startEdit($event, row.original)"
              />
            </UTooltip>
            <UTooltip
              v-if="row.original.can.delete"
              text="Delete"
            >
              <UButton
                icon="i-lucide-trash-2"
                color="error"
                variant="ghost"
                size="sm"
                :aria-label="`Delete ${row.original.name}`"
                @click="startDelete($event, row.original)"
              />
            </UTooltip>
          </div>
        </template>

        <template #empty>
          <p class="py-6 text-center text-sm text-muted">
            {{ debouncedSearch ? `No projects match "${debouncedSearch}".` : 'No projects yet. Create the first one.' }}
          </p>
        </template>
      </UTable>
    </UCard>

    <div
      v-if="projects && projects.meta.last_page > 1"
      class="flex justify-center"
    >
      <UPagination
        v-model:page="page"
        :total="projects.meta.total"
        :items-per-page="projects.meta.per_page"
      />
    </div>

    <UModal
      v-model:open="editOpen"
      title="Edit project"
      :dismissible="!saving"
      :close="{ disabled: saving }"
      :content="modalContent"
      @after:leave="editing = null"
    >
      <template #body>
        <ProjectForm
          v-if="editing"
          :key="editing.id"
          ref="editForm"
          :initial="editing"
          submit-label="Save"
          :loading="saving"
          @submit="save"
          @cancel="saving || (editOpen = false)"
        />
      </template>
    </UModal>

    <!-- Can't be dismissed while the delete is in flight. -->
    <UModal
      v-model:open="deleteOpen"
      title="Delete project?"
      :dismissible="!removing"
      :close="{ disabled: removing }"
      :content="modalContent"
      @after:leave="deleting = null"
    >
      <template #body>
        <p class="text-sm">
          <strong>{{ deleting?.name }}</strong> will be deleted for everyone in {{ organizations.current?.name }}, including its AI summaries. This can't be undone.
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <UButton
            label="Cancel"
            color="neutral"
            variant="ghost"
            :disabled="removing"
            @click="deleteOpen = false"
          />
          <UButton
            label="Delete"
            color="error"
            :loading="removing"
            @click="destroy"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
