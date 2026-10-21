/**
 * Resend the email verification link, with a toast either way.
 * Call it synchronously at the top of a setup function.
 */
export function useEmailVerification() {
  const api = useApi()
  const auth = useAuthStore()
  const toast = useToast()
  const sending = ref(false)

  async function resend() {
    sending.value = true
    try {
      const response = await api<{ message: string, email_verified: boolean }>('/api/email/verification-notification', { method: 'POST' })
      if (response.email_verified) {
        // Verified in another tab or device meanwhile.
        await auth.fetchMe()
        toast.add({ title: response.message, color: 'success' })
        return
      }
      toast.add({ title: 'Verification email sent', description: `Check ${auth.user?.email ?? 'your inbox'} for the link.`, color: 'success' })
    }
    catch (error) {
      toast.add({ title: 'Could not send the email', description: errorMessage(error), color: 'error' })
    }
    finally {
      sending.value = false
    }
  }

  return { sending, resend }
}
