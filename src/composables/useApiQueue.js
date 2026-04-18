/**
 * Generic API queue factory.
 *
 * Creates a module-level singleton queue that processes a list of item IDs
 * sequentially, with exponential-backoff retry on 429 and cancellation support.
 *
 * @param {(id: number) => string}             urlFn      — returns the POST URL for a given item ID
 * @param {(id: number, ...args: any[]) => Object} [payloadFn] — returns the POST body (default: empty object)
 * @param {{ delay?: number, retryDelay?: number, maxRetries?: number }} [opts]
 */
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'

export function createApiQueue(urlFn, payloadFn = () => ({}), opts = {}) {
  const delay = opts.delay ?? 1500
  const retryDelay = opts.retryDelay ?? 10000
  const maxRetries = opts.maxRetries ?? 4

  const total = ref(0)
  const done = ref(0)
  const failed = ref(0)
  const finished = ref(true)
  let cancelRequested = false
  /** Live args — updated via `updateArgs()` and read fresh per item. */
  let liveArgs = []

  const progress = computed(() =>
    total.value === 0 ? 100 : Math.round((done.value / total.value) * 100),
  )

  const running = computed(() => !finished.value && total.value > 0)

  async function start(itemIds, ...args) {
    if (!itemIds?.length) return
    if (!finished.value) return

    total.value = itemIds.length
    done.value = 0
    failed.value = 0
    finished.value = false
    cancelRequested = false
    liveArgs = args

    for (const id of itemIds) {
      if (cancelRequested) break
      const ok = await processWithRetry(id)
      if (!ok) failed.value++
      done.value++
      if (!cancelRequested) await sleep(delay)
    }

    finished.value = true
  }

  /**
   * Update the extra arguments passed to `payloadFn` for subsequent items.
   * Allows mid-run changes (e.g. currency switch) without restarting.
   */
  function updateArgs(...args) {
    liveArgs = args
  }

  /** @returns {boolean} true if the item succeeded */
  async function processWithRetry(id) {
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      if (cancelRequested) return false
      const result = await processOne(id)
      if (result === 'ok') return true
      if (result === 'rate-limited' && attempt < maxRetries) {
        // Exponential backoff: retryDelay * 2^attempt
        await sleep(retryDelay * Math.pow(2, attempt))
        continue
      }
      return false
    }
    return false
  }

  /** @returns {'ok' | 'rate-limited' | 'error'} */
  async function processOne(id) {
    try {
      await axios.post(urlFn(id), payloadFn(id, ...liveArgs))
      return 'ok'
    } catch (err) {
      if (err.response?.status === 429) return 'rate-limited'
      return 'error'
    }
  }

  function cancel() {
    cancelRequested = true
  }

  function reset() {
    total.value = 0
    done.value = 0
    failed.value = 0
    finished.value = true
    cancelRequested = false
    liveArgs = []
  }

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms))
  }

  return { total, done, failed, finished, progress, running, start, cancel, reset, updateArgs }
}
