/**
 * The `?redirect=` target after login, limited to paths on this site.
 * Anything else (absolute URLs, `//evil.com`, `/\evil.com`) falls back to `/`,
 * so the login page can't be used as an open redirect.
 */
export function safeRedirect(value: unknown): string {
  if (typeof value !== 'string' || !value.startsWith('/')) {
    return '/'
  }
  if (value.startsWith('//') || value.startsWith('/\\')) {
    return '/'
  }

  return value
}
