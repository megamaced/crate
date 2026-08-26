/**
 * Debounced Nextcloud user search, shared by every sharing dialog.
 */
import { ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

/**
 * @param {{ minLength?: number, debounceMs?: number }} [opts]
 * @returns {{
 *   query: import('vue').Ref<string>,
 *   searching: import('vue').Ref<boolean>,
 *   results: import('vue').Ref<Array<Object>>,
 *   onQueryInput: () => void,
 *   reset: () => void,
 *   dispose: () => void,
 * }}
 */
export function useUserSearch({ minLength = 2, debounceMs = 300 } = {}) {
  const query = ref('')
  const searching = ref(false)
  const results = ref([])

  let timeout = null
  /** Controller of the newest request; anything older has been superseded. */
  let controller = null

  function onQueryInput() {
    clearTimeout(timeout)
    results.value = []
    if (query.value.trim().length < minLength) return
    timeout = setTimeout(doSearch, debounceMs)
  }

  async function doSearch() {
    controller?.abort()
    const mine = new AbortController()
    controller = mine
    searching.value = true
    try {
      const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/users/search'), {
        params: { q: query.value.trim() },
        signal: mine.signal,
      })
      if (controller !== mine) return
      results.value = res.data.ocs?.data ?? []
    } catch (e) {
      // A superseded request owns none of this state any more: letting it clear
      // `searching` while the live request is still out flashes "No users
      // found." between keystrokes.
      if (controller !== mine) return
      if (e.name === 'CanceledError' || e.code === 'ERR_CANCELED') return
      console.error('User search failed', e)
      showError('User search failed')
    }
    if (controller === mine) searching.value = false
  }

  /** Clear the field — after a share has been made, or a user picked. */
  function reset() {
    clearTimeout(timeout)
    timeout = null
    query.value = ''
    results.value = []
  }

  /** Drop the pending timer and in-flight request (dialog closed, unmount). */
  function dispose() {
    clearTimeout(timeout)
    timeout = null
    controller?.abort()
    controller = null
    searching.value = false
  }

  return { query, searching, results, onQueryInput, reset, dispose }
}
