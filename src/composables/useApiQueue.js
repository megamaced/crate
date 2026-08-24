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
  /**
   * Pending `{ id, args }` entries. Each entry carries the arguments its own
   * start() call supplied, so a batch appended to a running drain is sent with
   * its own arguments — a second start() at a different currency must not price
   * its items in the running batch's currency.
   */
  const pending = []
  /** Promise resolvers waiting for the current drain to complete. */
  const finishWaiters = []
  /**
   * Bumped by reset(). A drain loop captures it on entry and re-checks after
   * every await, so a reset mid-item abandons the old loop instead of letting
   * its in-flight item write counters that the reset has already zeroed.
   */
  let epoch = 0

  const progress = computed(() =>
    total.value === 0 ? 100 : Math.round((done.value / total.value) * 100),
  )

  const running = computed(() => !finished.value && total.value > 0)

  /**
   * Enqueue a batch for processing. If the queue is idle, starts a new
   * drain loop; if one is already running, the new ids are appended and
   * picked up sequentially as part of the same run. The returned
   * promise resolves when the full queue (including any items appended
   * by callers after you) has drained.
   *
   * This prevents the previous silent-drop behaviour where a second
   * start() call mid-run was ignored — two imports in succession would
   * leave the second batch un-enriched with no feedback.
   */
  async function start(itemIds, ...args) {
    if (!itemIds?.length) return

    const waiter = new Promise(resolve => finishWaiters.push(resolve))
    const entries = itemIds.map(id => ({ id, args }))

    if (!finished.value) {
      // Already running: append and extend the progress bar.
      //
      // A cancel may have just been requested while the loop is still
      // finishing its in-flight item (finished is not yet true). If we
      // appended without clearing the flag, drainLoop would break on its
      // next iteration and silently drop this batch. Clear it so the same
      // loop keeps going and picks these ids up.
      if (cancelRequested) {
        cancelRequested = false
        total.value = done.value + itemIds.length
        failed.value = 0
      } else {
        total.value += itemIds.length
      }
      pending.push(...entries)
      return waiter
    }

    // Fresh start.
    total.value = itemIds.length
    done.value = 0
    failed.value = 0
    finished.value = false
    cancelRequested = false
    pending.push(...entries)

    // Fire the drain loop without awaiting here — callers await via `waiter`.
    drainLoop(epoch)
    return waiter
  }

  async function drainLoop(myEpoch) {
    while (pending.length > 0) {
      if (cancelRequested) break
      const entry = pending.shift()
      const ok = await processWithRetry(entry)
      // A reset() during that request owns the counters now; leave them alone
      // and let the run that reset started (if any) report progress.
      if (myEpoch !== epoch) return
      if (!ok) failed.value++
      done.value++
      if (!cancelRequested && pending.length > 0) await sleep(delay)
      if (myEpoch !== epoch) return
    }
    finished.value = true
    // Resolve every caller waiting on this drain in one pass, then clear
    // the list so the next start() begins with a clean waiter set.
    const resolvers = finishWaiters.splice(0)
    for (const r of resolvers) r()
  }

  /** @returns {boolean} true if the item succeeded */
  async function processWithRetry(entry) {
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      if (cancelRequested) return false
      const result = await processOne(entry)
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
  async function processOne(entry) {
    try {
      // __silent: the queue surfaces its own progress / failed counters,
      // so the global axios interceptor must not toast per-item 429s.
      await axios.post(urlFn(entry.id), payloadFn(entry.id, ...entry.args), { __silent: true })
      return 'ok'
    } catch (err) {
      if (err.response?.status === 429) return 'rate-limited'
      return 'error'
    }
  }

  function cancel() {
    cancelRequested = true
    // Drop everything still awaiting processing so the drain loop exits
    // promptly and a subsequent start() doesn't inherit orphan ids.
    pending.length = 0
  }

  function reset() {
    epoch++
    total.value = 0
    done.value = 0
    failed.value = 0
    finished.value = true
    cancelRequested = false
    pending.length = 0
    // Any orphaned waiters (cancelled drain) resolve so awaiters don't hang.
    const resolvers = finishWaiters.splice(0)
    for (const r of resolvers) r()
  }

  function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms))
  }

  return { total, done, failed, finished, progress, running, start, cancel, reset }
}
