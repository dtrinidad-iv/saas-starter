<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import type { ProjectPayload } from '~/types/api'

const props = defineProps<{
  initial?: Partial<ProjectPayload>
  submitLabel: string
  loading?: boolean
}>()

const emit = defineEmits<{
  submit: [payload: ProjectPayload]
  cancel: []
}>()

// In the form, description is always a string; empty becomes null on submit.
interface FormState { name: string, description: string }

const form = useTemplateRef<Form<FormState>>('form')
const state = reactive<FormState>({
  name: props.initial?.name ?? '',
  description: props.initial?.description ?? '',
})

function validate(state: Partial<FormState>): FormError[] {
  return state.name?.trim() ? [] : [{ name: 'name', message: 'Enter a name.' }]
}

function onSubmit(event: FormSubmitEvent<FormState>) {
  emit('submit', {
    name: event.data.name.trim(),
    description: event.data.description.trim() || null,
  })
}

// Lets the parent show the API's validation errors (e.g. duplicate name) on the fields.
defineExpose({ setErrors: (errors: FormError[]) => form.value?.setErrors(errors) })
</script>

<template>
  <UForm
    ref="form"
    :state="state"
    :validate="validate"
    class="space-y-4"
    @submit="onSubmit"
  >
    <UFormField
      label="Name"
      name="name"
    >
      <UInput
        v-model="state.name"
        autofocus
        class="w-full"
      />
    </UFormField>

    <UFormField
      label="Description"
      name="description"
      hint="Optional"
    >
      <UTextarea
        v-model="state.description"
        :rows="4"
        autoresize
        class="w-full"
      />
    </UFormField>

    <div class="flex justify-end gap-2">
      <UButton
        label="Cancel"
        color="neutral"
        variant="ghost"
        @click="emit('cancel')"
      />
      <UButton
        type="submit"
        :label="submitLabel"
        :loading="loading"
      />
    </div>
  </UForm>
</template>
