/**
 * Module-level singleton so market-value fetch state persists across component
 * mount/unmount cycles (e.g. navigating away while the queue runs).
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

async function start(itemIds, currency = 'GBP') {
  if (!itemIds?.length) return
  if (!finished.value) return

  total.value = itemIds.length
  done.value = 0
  failed.value = 0
  finished.value = false
  cancelRequested = false

  for (const id of itemIds) {
    if (cancelRequested) break
    const ok = await fetchOne(id, currency)
    if (!ok) {
      // Rate-limited — back off 10 s then retry once
      await sleep(10000)
      await fetchOne(id, currency)
    }
    done.value++
    if (!cancelRequested) await sleep(1500)
  }

  finished.value = true
}

async function fetchOne(id, currency) {
  try {
    await axios.post(
      generateOcsUrl(`/apps/crate/api/v1/media/${id}/market-value`),
      { currency },
    )
    return true
  } catch (err) {
    const status = err.response?.status
    if (status === 429) return false
    failed.value++
    return true
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

export function useMarketValueQueue() {
  return { total, done, failed, finished, progress, running, start, cancel, reset }
}
