<script setup lang="ts">
import type { ChatMessageRole, ChatMessageStatus, ProjectChatMessage } from '~/types/api'

// The current user's private AI chat about a project, in a slideover. The API saves
// both sides; this keeps a local copy while an answer streams.
const props = defineProps<{ projectId: number, projectName: string }>()

const open = defineModel<boolean>('open', { default: false })

const MAX_LENGTH = 2000

interface Message { id: string, role: ChatMessageRole, content: string, status: ChatMessageStatus }

const api = useApi()
const stream = useApiStream()
const toast = useToast()

const messages = ref<Message[]>([])
const loaded = ref(false)
const loading = ref(false)
const loadError = ref<string | null>(null)
const input = ref('')
const clearOpen = ref(false)
const clearing = ref(false)
let localId = 0

const path = computed(() => `/api/projects/${props.projectId}/chat`)
const busy = computed(() => stream.status.value === 'streaming')
const tooLong = computed(() => input.value.length > MAX_LENGTH)

async function load() {
  loading.value = true
  loadError.value = null
  try {
    const response = await api<{ data: ProjectChatMessage[] }>(path.value)
    messages.value = response.data.map(message => ({ ...message, id: String(message.id) }))
    loaded.value = true
  }
  catch (error) {
    loadError.value = errorMessage(error)
  }
  finally {
    loading.value = false
  }
}

// Load on first open (client only: the slideover is never server-rendered open).
watch(open, (isOpen) => {
  if (isOpen && !loaded.value && !loading.value) {
    load()
  }
}, { immediate: true })

// The answer being written, shown as the last message until it ends.
const pending = ref<Message | null>(null)

const all = computed(() => pending.value
  ? [...messages.value, { ...pending.value, content: stream.text.value }]
  : messages.value)
const byId = computed(() => new Map(all.value.map(message => [message.id, message])))

// Shaped like the AI SDK's UIMessage, which UChatMessages renders; the #content slot
// shows our own message (byId).
const shown = computed(() => all.value.map(message => ({
  id: message.id,
  role: message.role,
  parts: [{ type: 'text' as const, text: message.content }],
})))

const chatStatus = computed(() => {
  if (busy.value) {
    return stream.text.value ? 'streaming' : 'submitted'
  }
  return stream.status.value === 'error' ? 'error' : 'ready'
})

function send() {
  const text = input.value.trim()
  if (!text || busy.value || tooLong.value) {
    return
  }
  messages.value.push({ id: `local-${++localId}`, role: 'user', content: text, status: 'complete' })
  pending.value = { id: `local-${++localId}`, role: 'assistant', content: '', status: 'complete' }
  input.value = ''
  stream.start(path.value, { message: text })
}

// When the answer ends, keep it like the API saved it (a stop keeps the text so far).
watch(() => stream.status.value, (status, before) => {
  if (before !== 'streaming' || status === 'streaming' || !pending.value) {
    return
  }
  const answer = { ...pending.value, content: stream.text.value }
  pending.value = null

  if (status === 'error' && !stream.started.value) {
    // Refused before streaming (validation, budget, rate limit) or unreachable: the API
    // saved nothing, so take the question back into the input.
    const question = messages.value.pop()
    if (question && !input.value) {
      input.value = question.content
    }
    return
  }
  messages.value.push({ ...answer, status: status === 'done' ? 'complete' : status === 'stopped' ? 'stopped' : 'failed' })
})

async function clear() {
  clearing.value = true
  try {
    await api(path.value, { method: 'DELETE' })
    stream.reset()
    messages.value = []
    clearOpen.value = false
  }
  catch (error) {
    toast.add({ title: 'Could not clear the chat', description: errorMessage(error), color: 'error' })
  }
  finally {
    clearing.value = false
  }
}

function statusNote(message: Message | undefined) {
  if (message?.role !== 'assistant' || message.status === 'complete') {
    return ''
  }
  if (message.status === 'stopped') {
    return 'Stopped.'
  }
  return message.content.trim() ? 'This answer failed part-way.' : 'This answer failed. Try asking again.'
}
</script>

<template>
  <USlideover
    v-model:open="open"
    title="Ask AI"
    :description="`About ${projectName}. Only you see this chat. AI answers can be wrong.`"
    :ui="{ body: 'flex flex-col gap-4 p-0 sm:p-0', footer: 'flex-col items-stretch gap-2' }"
  >
    <template #body>
      <div class="flex-1 overflow-y-auto px-4 py-4 sm:px-6">
        <div
          v-if="loading && !loaded"
          class="space-y-3"
          aria-busy="true"
        >
          <USkeleton class="ml-auto h-10 w-2/3" />
          <USkeleton class="h-16 w-5/6" />
        </div>

        <UAlert
          v-else-if="loadError && !loaded"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Could not load the chat"
          :description="loadError"
          :actions="[{ label: 'Try again', color: 'neutral', variant: 'outline', onClick: load }]"
        />

        <div
          v-else-if="!shown.length"
          class="flex h-full flex-col items-center justify-center gap-2 text-center text-sm text-muted"
        >
          <UIcon
            name="i-lucide-message-circle-question"
            class="size-8"
          />
          <p>Ask about this project's goals, scope, risks or next steps.</p>
          <p class="text-xs">
            The AI only knows this project's name, description and approved summary, and can't change anything.
          </p>
        </div>

        <UChatMessages
          v-else
          :messages="shown"
          :status="chatStatus"
          :assistant="{ side: 'left', variant: 'naked', avatar: { icon: 'i-lucide-sparkles' } }"
          should-auto-scroll
        >
          <template #content="{ message }">
            <p class="whitespace-pre-wrap">
              {{ byId.get(message.id)?.content }}
            </p>
            <p
              v-if="statusNote(byId.get(message.id))"
              class="mt-1 text-xs text-muted"
            >
              {{ statusNote(byId.get(message.id)) }}
            </p>
          </template>
        </UChatMessages>
      </div>
    </template>

    <template #footer>
      <UAlert
        v-if="stream.error.value"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        :description="stream.error.value"
      />
      <p
        class="sr-only"
        aria-live="polite"
      >
        {{ busy ? 'Writing an answer…' : '' }}
      </p>

      <UChatPrompt
        v-model="input"
        placeholder="Ask about this project…"
        aria-label="Your question"
        :maxlength="MAX_LENGTH"
        :maxrows="6"
        :autofocus="false"
        :disabled="!loaded"
        :error="tooLong ? new Error(`At most ${MAX_LENGTH} characters.`) : undefined"
        @submit="send"
      >
        <UChatPromptSubmit
          :status="chatStatus === 'error' ? 'ready' : chatStatus"
          :disabled="!busy && (!input.trim() || tooLong || !loaded)"
          :aria-label="busy ? 'Stop the answer' : 'Send'"
          @stop="stream.stop()"
        />
      </UChatPrompt>

      <div class="flex justify-end">
        <UButton
          label="Clear chat"
          icon="i-lucide-eraser"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="busy || !messages.length"
          @click="clearOpen = true"
        />
      </div>

      <UModal
        v-model:open="clearOpen"
        title="Clear this chat?"
        :dismissible="!clearing"
        :close="{ disabled: clearing }"
      >
        <template #body>
          <p class="text-sm">
            Your messages about this project are deleted. This can't be undone.
          </p>
          <div class="mt-4 flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="ghost"
              :disabled="clearing"
              @click="clearOpen = false"
            />
            <UButton
              label="Clear"
              color="error"
              :loading="clearing"
              @click="clear"
            />
          </div>
        </template>
      </UModal>
    </template>
  </USlideover>
</template>
