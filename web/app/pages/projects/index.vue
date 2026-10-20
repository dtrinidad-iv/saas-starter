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

// A different organization has different projects: start over.
watch(() => organizations.current?.id, () => {
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
  </div>
</template>
