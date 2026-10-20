/**
 * The one way to call the Laravel API. Attaches the bearer token and
 * clears the session when the API says the token is no longer valid.
 *
 * Call it synchronously at the top of a setup function or store action,
 * not after an `await`, so it can still reach the Nuxt app context.
 *
 * `handleUnauthorized: false` leaves a 401 to the caller (logout cleans up itself).
 */
export function useApi({ handleUnauthorized = true }: { handleUnauthorized?: boolean } = {}) {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  const nuxtApp = useNuxtApp()

  return $fetch.create({
    baseURL: config.public.apiBase,
    headers: { Accept: 'application/json' },

    onRequest({ options }) {
      if (auth.token) {
        options.headers.set('Authorization', `Bearer ${auth.token}`)
      }
    },

    async onResponseError({ response }) {
      if (response.status !== 401 || !handleUnauthorized) {
        return
      }

      auth.clearSession()

      // On the server, the auth middleware does the redirect on this same request.
      if (import.meta.client) {
        await nuxtApp.runWithContext(() => navigateTo('/login'))
      }
    },
  })
}

export type ApiStreamStatus = 'idle' | 'streaming' | 'done' | 'error' | 'stopped'

/**
 * Streams a POST to the API as server-sent events (delta {text}, done, error
 * {message}; see the API's AiEventStream). Same bearer token and 401 handling as
 * useApi. Uses fetch, not EventSource, because EventSource can't send headers.
 *
 * Call it synchronously at the top of a setup function. A stream still running
 * when the component unmounts is stopped.
 */
export function useApiStream() {
  const config = useRuntimeConfig()
  const auth = useAuthStore()
  const nuxtApp = useNuxtApp()

  const text = ref('')
  const status = ref<ApiStreamStatus>('idle')
  const error = ref<string | null>(null)
  let controller: AbortController | null = null

  function fail(message: string) {
    status.value = 'error'
    error.value = message
  }

  // Checks `signal` after each await: a stream stopped meanwhile (Discard, a new run) shows nothing.
  async function failForResponse(response: Response, signal: AbortSignal) {
    if (response.status === 401) {
      auth.clearSession()
      await nuxtApp.runWithContext(() => navigateTo('/login'))
      return fail('Your session has ended. Please log in again.')
    }

    const body = await response.json().catch(() => null) as { message?: string, errors?: Record<string, string[]> } | null
    const firstError = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined

    if (signal.aborted) {
      return
    }
    const fallback = response.status === 429
      ? 'Too many AI requests for this organization. Try again in a minute.'
      : 'Something went wrong. Please try again.'
    fail(firstError ?? body?.message ?? fallback)
  }

  /** Handles one event; returns true when the stream is finished. */
  function handle(block: string): boolean {
    const event = block.match(/^event: (.*)$/m)?.[1]
    const data = block.split('\n').filter(line => line.startsWith('data:')).map(line => line.slice(5).trimStart()).join('\n')
    let payload: { text?: string, message?: string }
    try {
      payload = JSON.parse(data)
    }
    catch {
      return false
    }

    if (event === 'delta') {
      text.value += payload.text ?? ''
    }
    else if (event === 'done') {
      status.value = 'done'
      return true
    }
    else if (event === 'error') {
      fail(payload.message ?? 'The AI request failed.')
      return true
    }
    return false
  }

  async function start(path: string, body: Record<string, unknown>) {
    stop()
    const current = new AbortController()
    controller = current
    text.value = ''
    error.value = null
    status.value = 'streaming'

    try {
      const response = await fetch(config.public.apiBase.replace(/\/$/, '') + path, {
        method: 'POST',
        headers: {
          'Accept': 'text/event-stream',
          'Content-Type': 'application/json',
          ...(auth.token ? { Authorization: `Bearer ${auth.token}` } : {}),
        },
        body: JSON.stringify(body),
        signal: current.signal,
      })

      if (!response.ok || !response.body) {
        return await failForResponse(response, current.signal)
      }

      const reader = response.body.pipeThrough(new TextDecoderStream()).getReader()
      let buffer = ''
      while (true) {
        const { value, done } = await reader.read()
        if (done) {
          break
        }
        buffer += value.replaceAll('\r\n', '\n')
        let end
        while ((end = buffer.indexOf('\n\n')) !== -1) {
          const finished = handle(buffer.slice(0, end))
          buffer = buffer.slice(end + 2)
          if (finished) {
            await reader.cancel()
            return
          }
        }
      }
      fail('The answer was cut off. Try again.')
    }
    catch {
      if (current.signal.aborted) {
        return
      }
      fail('Cannot reach the server. Is the API running?')
    }
    finally {
      if (controller === current) {
        controller = null
      }
    }
  }

  /** Stops a running stream (the API stops the AI call too); keeps the text so far. */
  function stop() {
    controller?.abort()
    controller = null
    if (status.value === 'streaming') {
      status.value = 'stopped'
    }
  }

  function reset() {
    stop()
    text.value = ''
    error.value = null
    status.value = 'idle'
  }

  if (getCurrentScope()) {
    onScopeDispose(stop)
  }

  return { text: readonly(text), status: readonly(status), error: readonly(error), start, stop, reset }
}
