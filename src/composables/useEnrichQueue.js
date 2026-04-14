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
  total.value = itemIds.length
  done.value = 0
  failed.value = 0
  finished.value = false
  cancelRequested = false

  for (const id of itemIds) {
    if (cancelRequested) break
    try {
      await axios.post(generateOcsUrl(`/apps/crate/api/v1/media/${id}/enrich`))
    } catch {
      failed.value++
    }
    done.value++
    if (!cancelRequested) await sleep(1000)
  }

  finished.value = true
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
