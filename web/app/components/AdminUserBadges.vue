<script setup lang="ts">
import type { AdminUser } from '~/types/api'

// The short status of a user in the admin list: only what's worth noticing.
defineProps<{ user: Pick<AdminUser, 'email_verified' | 'two_factor_enabled' | 'is_platform_admin' | 'suspended_at'> }>()
</script>

<template>
  <div class="flex flex-wrap gap-1">
    <UBadge
      v-if="user.suspended_at"
      label="Suspended"
      color="error"
      variant="subtle"
    />
    <UBadge
      v-if="!user.email_verified"
      label="Unverified"
      color="warning"
      variant="subtle"
    />
    <UBadge
      v-if="user.two_factor_enabled"
      label="2FA"
      color="success"
      variant="subtle"
    />
    <UBadge
      v-if="user.is_platform_admin"
      label="Admin"
      color="primary"
      variant="subtle"
    />
  </div>
</template>
