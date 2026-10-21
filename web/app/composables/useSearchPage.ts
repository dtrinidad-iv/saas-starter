/**
 * A search box and page number kept in the URL (?q=&page=), so reload and Back
 * keep them. The search applies 300 ms after typing stops and goes back to page 1.
 */
export function useSearchPage() {
  const route = useRoute()
  const router = useRouter()

  const queryPage = () => Math.max(1, Number.parseInt(String(route.query.page ?? ''), 10) || 1)
  const querySearch = () => (typeof route.query.q === 'string' ? route.query.q.trim().slice(0, 100) : '')

  const page = ref(queryPage())
  const search = ref(querySearch())
  const debouncedSearch = ref(search.value)

  let timer: ReturnType<typeof setTimeout> | undefined
  watch(search, (value) => {
    clearTimeout(timer)
    if (value.trim() === debouncedSearch.value) {
      return
    }
    timer = setTimeout(() => {
      debouncedSearch.value = value.trim()
      page.value = 1
    }, 300)
  })
  onBeforeUnmount(() => clearTimeout(timer))

  // Pin the path: a late update from this (leaving) page must not rewrite the next page's URL.
  const path = route.path
  watch([page, debouncedSearch], ([p, q]) => {
    router.replace({ path, query: { page: p > 1 ? String(p) : undefined, q: q || undefined } })
  })

  // The URL changed from outside (Back/Forward, or the sidebar link): follow it.
  watch(() => route.query, () => {
    if (route.path !== path) return
    const [p, q] = [queryPage(), querySearch()]
    if (p !== page.value || q !== debouncedSearch.value) {
      clearTimeout(timer)
      search.value = q
      debouncedSearch.value = q
      page.value = p
    }
  })

  return { page, search, debouncedSearch }
}
