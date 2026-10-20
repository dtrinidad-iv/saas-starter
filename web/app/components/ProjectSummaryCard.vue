<script setup lang="ts">
import type { ProjectSummary } from '~/types/api'

// canGenerate: the project's can.update (the API uses the same rule), needed
// before any summary exists.
const props = defineProps<{ projectId: number, canGenerate: boolean }>()

const api = useApi()
const toast = useToast()

const POLL_INTERVAL_MS = 3000
const POLL_LIMIT_MS = 60_000

const { data: result, error: loadError, refresh } = await useAsyncData(
  `project-summary:${props.projectId}`,
  () => api<{ data: ProjectSummary | null, approved: ProjectSummary | null }>(`/api/projects/${props.projectId}/summary`),
)

// The latest summary (any status), and the approved one, which stays the
// project's summary until a newer one is approved.
const summary = computed(() => result.value?.data ?? null)
const approved = computed(() => result.value?.approved ?? null)

// While a summary is generating, check again every few seconds, for up to a minute.
const pollTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const pollStartedAt = ref<number | null>(null)
const stillWorking = ref(false)

function stopPolling() {
  if (pollTimer.value) {
    clearTimeout(pollTimer.value)
  }
  pollTimer.value = null
}

function schedulePoll() {
  stopPolling()
  if (summary.value?.status !== 'pending') {
    pollStartedAt.value = null
    stillWorking.value = false
    return
  }
  pollStartedAt.value ??= Date.now()
  if (Date.now() - pollStartedAt.value > POLL_LIMIT_MS) {
    stillWorking.value = true
    return
  }
  pollTimer.value = setTimeout(async () => {
    await refresh()
    schedulePoll()
  }, POLL_INTERVAL_MS)
}

onMounted(schedulePoll)
onBeforeUnmount(stopPolling)

const busy = ref<'generate' | 'approve' | 'reject' | null>(null)

async function generate() {
  busy.value = 'generate'
  try {
    await api(`/api/projects/${props.projectId}/summary`, { method: 'POST' })
    await refresh()
    pollStartedAt.value = null
    schedulePoll()
  }
  catch (error) {
    toast.add({
      title: 'Could not generate a summary',
      description: isFetchError(error) && error.statusCode === 429
        ? 'Too many AI requests for this organization. Try again in a minute.'
        : errorMessage(error),
      color: 'error',
    })
  }
  finally {
    busy.value = null
  }
}

async function review(decision: 'approve' | 'reject') {
  if (!summary.value) {
    return
  }
  busy.value = decision
  try {
    await api(`/api/projects/${props.projectId}/summaries/${summary.value.id}/${decision}`, { method: 'POST' })
    await refresh()
    toast.add({ title: decision === 'approve' ? 'Summary approved' : 'Summary rejected', color: 'success' })
  }
  catch (error) {
    toast.add({ title: 'Could not update the summary', description: errorMessage(error), color: 'error' })
    // Someone else may have reviewed or replaced it (409): show the current state.
    await refresh()
  }
  finally {
    busy.value = null
  }
}

const badge = computed(() => {
  switch (summary.value?.status) {
    case 'pending': return { label: 'Generating', color: 'info' as const }
    case 'draft': return { label: 'AI draft, needs review', color: 'warning' as const }
    case 'approved': return { label: 'Approved', color: 'success' as const }
    case 'rejected': return { label: 'Rejected', color: 'neutral' as const }
    case 'failed': return { label: 'Failed', color: 'error' as const }
    default: return null
  }
})

const showsText = computed(() => summary.value?.status === 'draft' || summary.value?.status === 'approved')
// Shown under a newer summary that isn't approved (yet).
const previousApproved = computed(() => approved.value && approved.value.id !== summary.value?.id ? approved.value : null)
// Regenerate hides while a draft waits for review, and while generating (unless it's taking too long).
const showGenerate = computed(() => !loadError.value
  && (summary.value ? summary.value.can.regenerate : props.canGenerate)
  && summary.value?.status !== 'draft'
  && (summary.value?.status !== 'pending' || stillWorking.value))
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <h2 class="font-semibold">
            AI summary
          </h2>
          <UBadge
            v-if="badge"
            :label="badge.label"
            :color="badge.color"
            variant="subtle"
            size="sm"
          />
        </div>
        <UButton
          v-if="showGenerate"
          :label="summary ? 'Regenerate' : 'Generate summary'"
          icon="i-lucide-sparkles"
          color="neutral"
          variant="outline"
          size="sm"
          :loading="busy === 'generate'"
          :disabled="busy !== null"
          @click="generate"
        />
      </div>
    </template>

    <!-- Announces status changes (generating → draft) to screen readers. -->
    <div
      aria-live="polite"
      class="space-y-3"
    >
      <p
        v-if="loadError"
        class="text-sm text-muted"
      >
        Could not load the summary.
      </p>

      <p
        v-else-if="!summary"
        class="text-sm text-muted"
      >
        No summary yet.
      </p>

      <template v-else-if="summary.status === 'pending'">
        <p class="text-sm text-muted">
          {{ stillWorking ? 'Still working on it. Check back in a little while.' : 'Generating a summary…' }}
        </p>
        <USkeleton class="h-4 w-full" />
        <USkeleton class="h-4 w-2/3" />
      </template>

      <template v-else-if="showsText">
        <p class="whitespace-pre-line text-sm">
          {{ summary.summary }}
        </p>
        <ul
          v-if="summary.key_points.length"
          class="list-disc space-y-1 pl-5 text-sm"
        >
          <li
            v-for="(point, index) in summary.key_points"
            :key="index"
          >
            {{ point }}
          </li>
        </ul>
        <p
          v-if="summary.status === 'approved'"
          class="text-xs text-muted"
        >
          Approved by {{ summary.reviewed_by?.name ?? 'a former member' }}
        </p>
        <p
          v-else
          class="text-xs text-muted"
        >
          Written by AI. Check it before approving; it may contain mistakes.
        </p>
      </template>

      <p
        v-else-if="summary.status === 'rejected'"
        class="text-sm text-muted"
      >
        The last summary was rejected.
      </p>

      <UAlert
        v-else-if="summary.status === 'failed'"
        :description="summary.error ?? 'The summary could not be generated.'"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
      />

      <div
        v-if="previousApproved"
        class="space-y-2 border-t border-default pt-3"
      >
        <h3 class="text-sm font-medium">
          Current approved summary
        </h3>
        <p class="whitespace-pre-line text-sm">
          {{ previousApproved.summary }}
        </p>
        <ul
          v-if="previousApproved.key_points.length"
          class="list-disc space-y-1 pl-5 text-sm"
        >
          <li
            v-for="(point, index) in previousApproved.key_points"
            :key="index"
          >
            {{ point }}
          </li>
        </ul>
        <p class="text-xs text-muted">
          Approved by {{ previousApproved.reviewed_by?.name ?? 'a former member' }}
        </p>
      </div>
    </div>

    <template
      v-if="summary?.status === 'draft' && summary.can.review"
      #footer
    >
      <div class="flex justify-end gap-2">
        <UButton
          label="Reject"
          color="neutral"
          variant="ghost"
          :loading="busy === 'reject'"
          :disabled="busy !== null"
          @click="review('reject')"
        />
        <UButton
          label="Approve"
          icon="i-lucide-check"
          :loading="busy === 'approve'"
          :disabled="busy !== null"
          @click="review('approve')"
        />
      </div>
    </template>
  </UCard>
</template>
