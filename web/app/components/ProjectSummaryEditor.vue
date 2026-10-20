<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import type { ProjectSummaryEdits } from '~/types/api'

// Same limits as the API (ProjectSummaryPrompt).
const MAX_SUMMARY_LENGTH = 600
const MAX_KEY_POINTS = 5
const MAX_KEY_POINT_LENGTH = 160

const props = defineProps<{ initial: ProjectSummaryEdits, loading?: boolean }>()

const emit = defineEmits<{
  submit: [edits: ProjectSummaryEdits]
  cancel: []
}>()

const form = useTemplateRef<Form<ProjectSummaryEdits>>('form')
const state = reactive<ProjectSummaryEdits>({
  summary: props.initial.summary,
  key_points: [...props.initial.key_points],
})

function validate(state: Partial<ProjectSummaryEdits>): FormError[] {
  const errors: FormError[] = []
  if (!state.summary?.trim()) {
    errors.push({ name: 'summary', message: 'Enter a summary.' })
  }
  state.key_points?.forEach((point, index) => {
    if (!point.trim()) {
      errors.push({ name: `key_points.${index}`, message: 'Enter a point or remove it.' })
    }
  })
  return errors
}

function onSubmit(event: FormSubmitEvent<ProjectSummaryEdits>) {
  emit('submit', {
    summary: event.data.summary.trim(),
    key_points: event.data.key_points.map(point => point.trim()),
  })
}

function addPoint() {
  state.key_points.push('')
  // Focus the new input once it's rendered.
  nextTick(() => document.getElementById(`summary-point-${state.key_points.length - 1}`)?.focus())
}

function removePoint(index: number) {
  state.key_points.splice(index, 1)
  // Errors are keyed by index, so they no longer match the inputs.
  form.value?.clear()
}

// Lets the card show the API's validation errors (422) on the fields.
defineExpose({ setErrors: (errors: FormError[]) => form.value?.setErrors(errors) })
</script>

<template>
  <UForm
    ref="form"
    :state="state"
    :validate="validate"
    :validate-on="['input']"
    :validate-on-input-delay="0"
    class="space-y-4"
    @submit="onSubmit"
  >
    <UFormField
      label="Summary"
      name="summary"
      :help="`${state.summary.length} / ${MAX_SUMMARY_LENGTH}`"
      eager-validation
    >
      <UTextarea
        v-model="state.summary"
        :rows="3"
        :maxlength="MAX_SUMMARY_LENGTH"
        autoresize
        autofocus
        class="w-full"
      />
    </UFormField>

    <fieldset>
      <legend class="mb-2 text-sm font-medium">
        Key points
      </legend>
      <!-- Shows list-level errors from the API (e.g. too many points). -->
      <UFormField
        name="key_points"
        :ui="{ container: 'space-y-2' }"
      >
        <UFormField
          v-for="(_, index) in state.key_points"
          :key="index"
          :name="`key_points.${index}`"
          eager-validation
        >
          <div class="flex gap-2">
            <UInput
              :id="`summary-point-${index}`"
              v-model="state.key_points[index]"
              :maxlength="MAX_KEY_POINT_LENGTH"
              :aria-label="`Key point ${index + 1}`"
              class="w-full"
            />
            <UButton
              icon="i-lucide-x"
              color="neutral"
              variant="ghost"
              :aria-label="`Remove key point ${index + 1}`"
              @click="removePoint(index)"
            />
          </div>
        </UFormField>
        <UButton
          v-if="state.key_points.length < MAX_KEY_POINTS"
          label="Add point"
          icon="i-lucide-plus"
          color="neutral"
          variant="link"
          size="sm"
          class="px-0"
          @click="addPoint"
        />
      </UFormField>
    </fieldset>

    <div class="flex justify-end gap-2">
      <UButton
        label="Cancel"
        color="neutral"
        variant="ghost"
        :disabled="loading"
        @click="emit('cancel')"
      />
      <UButton
        type="submit"
        label="Save and approve"
        icon="i-lucide-check"
        :loading="loading"
      />
    </div>
  </UForm>
</template>
