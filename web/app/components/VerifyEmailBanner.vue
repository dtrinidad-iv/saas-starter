<script setup lang="ts">
const auth = useAuthStore()
const { sending, resend } = useEmailVerification()
</script>

<template>
  <UBanner
    v-if="auth.user && !auth.user.email_verified"
    color="warning"
    icon="i-lucide-mail-warning"
    :title="`Verify ${auth.user.email} to invite people, use AI and manage billing.`"
    :actions="[{ label: 'Resend email', color: 'neutral', variant: 'outline', size: 'xs', loading: sending, onClick: resend }]"
  />
</template>
