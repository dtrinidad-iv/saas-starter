import type { FormError } from '@nuxt/ui'
import type { FetchError } from 'ofetch'

interface LaravelErrorBody {
  message?: string
  code?: string
  errors?: Record<string, string[]>
}

export function isFetchError(error: unknown): error is FetchError<LaravelErrorBody> {
  return typeof error === 'object' && error !== null && 'statusCode' in error
}

/** Laravel 422 response -> Nuxt UI form errors, one per field. */
export function toFormErrors(error: unknown): FormError[] {
  if (!isFetchError(error) || error.statusCode !== 422 || !error.data?.errors) {
    return []
  }

  return Object.entries(error.data.errors).map(([name, messages]) => ({
    name,
    message: messages[0] ?? 'Invalid value.',
  }))
}

/** Best human-readable message for a failed API call. */
export function errorMessage(error: unknown): string {
  if (isFetchError(error)) {
    if (error.statusCode === 429) {
      return 'Too many attempts. Please wait a minute and try again.'
    }
    if (isEmailUnverified(error)) {
      return 'Verify your email address to use this feature. Use the link we emailed you, or resend it from the banner at the top.'
    }
    if (error.data?.message) {
      return error.data.message
    }
    if (!error.statusCode) {
      return 'Cannot reach the server. Is the API running?'
    }
  }

  return 'Something went wrong. Please try again.'
}

/** The API refused because the user hasn't verified their email (the 'verified' middleware). */
export function isEmailUnverified(error: unknown): boolean {
  return isFetchError(error) && error.statusCode === 403 && error.data?.code === 'email_unverified'
}
