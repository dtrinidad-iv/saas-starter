<script setup lang="ts">
import type { Form, FormError, FormSubmitEvent } from '@nuxt/ui'
import type { ProjectPayload } from '~/types/api'

const props = defineProps<{
  initial?: Partial<ProjectPayload>
  submitLabel: string
  loading?: boolean
  focusOnMount?: boolean
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

// Inline forms focus Name on mount (`autofocus` only works in a freshly opened modal).
// Not in a modal: focusing before the modal records its trigger would make it return
// focus to this input instead of the button that opened it.
const nameInput = useTemplateRef<{ inputRef: HTMLInputElement | null }>('nameInput')
onMounted(() => {
  if (props.focusOnMount) {
    nameInput.value?.inputRef?.focus()
  }
})
</script>

<template>
  <!--
    Validate on input only (plus submit). A server error (e.g. duplicate name) that cleared on
    blur or change would move the buttons up while the mouse is down on Save, and the click would
    be lost. Eager input validation with no delay clears errors while typing instead.
  -->
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
      label="Name"
      name="name"
      eager-validation
    >
      <UInput
        ref="nameInput"
        v-model="state.name"
        :autofocus="!focusOnMount"
        class="w-full"
      />
    </UFormField>

    <UFormField
      label="Description"
      name="description"
      hint="Optional"
      eager-validation
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
