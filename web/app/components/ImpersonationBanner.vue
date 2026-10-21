<script setup lang="ts">
// Shown on every page while a platform admin views the app as a user (read-only).
const auth = useAuthStore()
const stopping = ref(false)

// In the browser's time zone, so only once mounted (the server's could differ).
const mounted = ref(false)
onMounted(() => {
  mounted.value = true
})
const title = computed(() => {
  const viewing = `Viewing as ${auth.user?.name} (read-only)`
  const expiresAt = auth.user?.impersonated_by?.expires_at
  return mounted.value && expiresAt
    ? `${viewing} · ends at ${new Date(expiresAt).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' })}`
    : viewing
})

async function stop() {
  stopping.value = true
  try {
    await auth.stopImpersonation()
  }
  finally {
    stopping.value = false
  }
}
</script>

<template>
  <UBanner
    v-if="auth.isImpersonating"
    color="info"
    icon="i-lucide-eye"
    :title="title"
    :ui="{ title: 'whitespace-normal' }"
    :actions="[{ label: 'Stop viewing', color: 'neutral', variant: 'outline', size: 'xs', loading: stopping, onClick: stop }]"
  />
</template>
