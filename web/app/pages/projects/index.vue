<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Paginated, Project, ProjectPayload } from '~/types/api'

definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Projects' })

const api = useApi()
const organizations = useOrganizationStore()
const toast = useToast()

const page = ref(1)
const search = ref('')
const debouncedSearch = ref('')
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    debouncedSearch.value = value.trim()
    page.value = 1
  }, 300)
})

// A different organization has different projects: start from page 1.
watch(() => organizations.current?.id, () => {
  page.value = 1
})

// Also reloaded by refreshNuxtData() when switching organizations.
const { data: projects, status, error, refresh } = await useAsyncData(
  'projects',
  () => api<Paginated<Project>>('/api/projects', {
    query: { page: page.value, search: debouncedSearch.value || undefined },
  }),
  { watch: [page, debouncedSearch] },
)

const columns: TableColumn<Project>[] = [
  { accessorKey: 'name', header: 'Project' },
  { accessorKey: 'creator', header: 'Created by' },
  { accessorKey: 'updated_at', header: 'Updated' },
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
      <UButton
        label="New project"
        icon="i-lucide-plus"
        @click="createOpen = true"
      />
    </div>

    <UInput
      v-model="search"
      placeholder="Search projects"
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
        <template #name-cell="{ row }">
          <NuxtLink
            :to="`/projects/${row.original.id}`"
            class="block"
          >
            <p class="font-medium hover:underline">
              {{ row.original.name }}
            </p>
            <p
              v-if="row.original.description"
              class="text-muted line-clamp-1 max-w-md text-xs"
            >
              {{ row.original.description }}
            </p>
          </NuxtLink>
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

    <UModal
      v-model:open="createOpen"
      title="New project"
    >
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
</template>
