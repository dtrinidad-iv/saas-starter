<script setup lang="ts">
definePageMeta({ middleware: 'auth' })
useSeoMeta({ title: 'Dashboard' })

const auth = useAuthStore()
const organizations = useOrganizationStore()

const memberSince = computed(() =>
  // Fixed locale and time zone so server and browser render the same text.
  auth.user
    ? new Date(auth.user.created_at).toLocaleDateString('en-US', { dateStyle: 'medium', timeZone: 'UTC' })
    : '',
)
</script>

<template>
  <div
    v-if="auth.user"
    class="space-y-6"
  >
    <h1 class="text-2xl font-semibold">
      Welcome, {{ auth.user.name }}
    </h1>

    <UCard>
      <template #header>
        <h2 class="font-medium">
          Current organization
        </h2>
      </template>

      <dl
        v-if="organizations.current"
        class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm"
      >
        <dt class="text-muted">
          Name
        </dt>
        <dd>{{ organizations.current.name }}</dd>
        <dt class="text-muted">
          Your role
        </dt>
        <dd>
          <UBadge
            :label="organizations.current.role"
            variant="subtle"
            class="capitalize"
          />
        </dd>
      </dl>
      <p
        v-else
        class="text-sm text-muted"
      >
        You are not in an organization. Create one from the switcher in the header.
      </p>
    </UCard>

    <UCard>
      <template #header>
        <h2 class="font-medium">
          Your account
        </h2>
      </template>

      <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
        <dt class="text-muted">
          Name
        </dt>
        <dd>{{ auth.user.name }}</dd>
        <dt class="text-muted">
          Email
        </dt>
        <dd>{{ auth.user.email }}</dd>
        <dt class="text-muted">
          Member since
        </dt>
        <dd>{{ memberSince }}</dd>
      </dl>
    </UCard>
  </div>
</template>
