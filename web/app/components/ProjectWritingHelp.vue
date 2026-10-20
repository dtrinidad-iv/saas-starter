<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

// The project form's current text. projectId: editing an existing project (the API
// then checks edit permission on it); without it, the create form.
const props = defineProps<{ name: string, description: string, projectId?: number }>()

const emit = defineEmits<{ accept: [text: string] }>()

type Mode = 'improve' | 'expand' | 'shorten'

const stream = useApiStream()
const mode = ref<Mode | null>(null)

const hasDescription = computed(() => props.description.trim() !== '')
const hasText = computed(() => hasDescription.value || props.name.trim() !== '')
const busy = computed(() => stream.status.value === 'streaming')
// An empty answer would clear the description, so it can't be accepted.
const canAccept = computed(() => stream.status.value === 'done' && stream.text.value.trim() !== '')

function run(selected: Mode) {
  mode.value = selected
  const path = props.projectId ? `/api/projects/${props.projectId}/writing-help` : '/api/projects/writing-help'
  stream.start(path, {
    mode: selected,
    name: props.name.trim() || null,
    description: props.description.trim() || null,
  })
}

const items = computed<DropdownMenuItem[]>(() => [
  { label: 'Improve', icon: 'i-lucide-wand-sparkles', disabled: !hasDescription.value, onSelect: () => run('improve') },
  { label: 'Expand', icon: 'i-lucide-unfold-vertical', disabled: !hasText.value, onSelect: () => run('expand') },
  { label: 'Shorten', icon: 'i-lucide-fold-vertical', disabled: !hasDescription.value, onSelect: () => run('shorten') },
])

function accept() {
  emit('accept', stream.text.value.trim())
  discard()
}

function discard() {
  stream.reset()
  mode.value = null
}

const statusLabel = computed(() => ({
  idle: '',
  streaming: 'Writing…',
  done: canAccept.value
    ? 'Suggestion ready. Accept it to replace the description; nothing is saved until you save the project.'
    : 'The AI had no suggestion. Try again.',
  error: '',
  stopped: 'Stopped.',
})[stream.status.value])
</script>

<template>
  <div class="space-y-2">
    <UDropdownMenu
      :items="items"
      :content="{ align: 'start' }"
      :disabled="busy"
    >
      <UButton
        label="Improve with AI"
        icon="i-lucide-sparkles"
        trailing-icon="i-lucide-chevron-down"
        color="neutral"
        variant="outline"
        size="sm"
        :disabled="busy || !hasText"
      />
    </UDropdownMenu>

    <UCard
      v-if="stream.status.value !== 'idle'"
      :ui="{ body: 'space-y-3 sm:p-4' }"
      :aria-busy="busy"
    >
      <p
        v-if="stream.text.value"
        class="whitespace-pre-wrap text-sm"
      >
        {{ stream.text.value }}
      </p>
      <USkeleton
        v-else-if="busy"
        class="h-4 w-2/3"
      />

      <UAlert
        v-if="stream.error.value"
        color="error"
        variant="subtle"
        icon="i-lucide-circle-alert"
        :description="stream.error.value"
      />
      <p
        class="text-xs text-muted"
        aria-live="polite"
      >
        {{ statusLabel }}
      </p>

      <div class="flex flex-wrap justify-end gap-2">
        <UButton
          v-if="busy"
          label="Stop"
          icon="i-lucide-square"
          color="neutral"
          variant="outline"
          size="sm"
          @click="stream.stop()"
        />
        <template v-else>
          <UButton
            label="Discard"
            color="neutral"
            variant="ghost"
            size="sm"
            @click="discard"
          />
          <UButton
            v-if="mode"
            label="Try again"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="outline"
            size="sm"
            @click="run(mode)"
          />
          <UButton
            v-if="canAccept"
            label="Accept"
            icon="i-lucide-check"
            size="sm"
            @click="accept"
          />
        </template>
      </div>
    </UCard>
  </div>
</template>
