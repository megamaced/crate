/**
 * Module-level singleton so enrichment state persists across component
 * mount/unmount cycles (e.g. the ImportModal is closed while the queue runs).
 */
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const total = ref(0)
const done = ref(0)
const failed = ref(0)
const finished = ref(true)
let cancelRequested = false

const progress = computed(() =>
  total.value === 0 ? 100 : Math.round((done.value / total.value) * 100),
)

const running = computed(() => !finished.value && total.value > 0)

async function start(itemIds) {
  if (!itemIds?.length) return
  // Prevent two queues running concurrently (e.g. two imports both triggering
  // auto-enrich at the same time — doubling the Discogs request rate).
  if (!finished.value) return

  total.value = itemIds.length
  done.value = 0
  failed.value = 0
  finished.value = false
  cancelRequested = false

  for (const id of itemIds) {
    if (cancelRequested) break
    const ok = await enrichOne(id)
    // On rate-limit, back off 10 s then retry once before giving up.
    if (!ok) {
      await sleep(10000)
      await enrichOne(id)
    }
    done.value++
    if (!cancelRequested) await sleep(1500)
  }

  finished.value = true
}

/**
 * Fire a single enrich request. Returns true on success, false on failure.
 * On HTTP 429 the caller should back off and retry.
 */
async function enrichOne(id) {
  try {
    await axios.post(generateOcsUrl(`/apps/crate/api/v1/media/${id}/enrich`))
    return true
  } catch (err) {
    const status = err.response?.status
    if (status === 429) {
      // Signal to caller: rate-limited, should back off.
      return false
    }
    failed.value++
    return true // non-retriable failure, count as done
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
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

export function useEnrichQueue() {
  return { total, done, failed, finished, progress, running, start, cancel, reset }
}
