/**
 * Module-level singleton so the "Shared with me" content is loaded once and
 * shared across the landing, the per-category subpages, the playlists subpage
 * and the left-nav (which reads the computed category/playlist lists to decide
 * which children to render). Follows the pattern of useSettings.js — reactive
 * state lives at module scope, the exported composable just returns it.
 */
import { ref, computed } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { CATEGORY_LABELS } from '../utils/categoryFormats.js'

// Standard category order used across the app (mirrors HomeView's display
// order and useSettings' ALL_CATEGORIES).
const CATEGORY_ORDER = ['music', 'film', 'book', 'game', 'comic']

const albums = ref([])
const playlists = ref([])
const libraries = ref([])
const categories = ref([])
const loading = ref(false)
const error = ref(null)

/** Tag a raw item row with the owner/permission metadata of its wrapping share. */
function tagShared(item, share) {
  return {
    ...item,
    sharedByUser: share.sharedByUser,
    shareId: share.shareId,
    canWrite: share.canWrite === true,
  }
}

/**
 * Map of category → deduped list of shared media items. An item can arrive via
 * a whole-library share, a category share and/or a single-album share; we key
 * by item id within each category and, when the same item shows up more than
 * once, keep the copy that grants write access.
 */
const itemsByCategory = computed(() => {
  const byCat = {}

  const add = (item, cat) => {
    if (!cat) return
    if (!byCat[cat]) byCat[cat] = new Map()
    const existing = byCat[cat].get(item.id)
    // Prefer the writable copy when the same item is shared multiple ways.
    if (!existing || (item.canWrite === true && existing.canWrite !== true)) {
      byCat[cat].set(item.id, item)
    }
  }

  // Single-album shares — already carry category/sharedByUser/canWrite.
  for (const item of albums.value) {
    add({ ...item, canWrite: item.canWrite === true }, item.category)
  }
  // Category shares — every item belongs to the share's category.
  for (const share of categories.value) {
    for (const item of share.items ?? []) {
      add(tagShared(item, share), share.category)
    }
  }
  // Whole-library shares — items span every category the owner has.
  for (const share of libraries.value) {
    for (const item of share.items ?? []) {
      add(tagShared(item, share), item.category)
    }
  }

  // Materialise the Maps into arrays.
  const out = {}
  for (const [cat, map] of Object.entries(byCat)) {
    out[cat] = [...map.values()]
  }
  return out
})

/**
 * Categories that have at least one shared item, in the app's standard order,
 * each with its display label, count and item list. Drives both the landing
 * sections and the dynamic nav children.
 */
const sharedCategories = computed(() => {
  const map = itemsByCategory.value
  return CATEGORY_ORDER
    .filter(cat => (map[cat]?.length ?? 0) > 0)
    .map(cat => ({
      category: cat,
      label: CATEGORY_LABELS[cat] ?? cat,
      count: map[cat].length,
      items: map[cat],
    }))
})

/** Shared playlists — each already carries sharedByUser/canWrite from the API. */
const sharedPlaylists = computed(() => playlists.value)

/**
 * Flat, newest-first slice across every shared item for the landing's
 * "Recently shared" row. Uses updatedAt/createdAt when present.
 */
const recentShared = computed(() => {
  const all = []
  for (const list of Object.values(itemsByCategory.value)) {
    all.push(...list)
  }
  const stamp = i => i.updatedAt ?? i.createdAt ?? ''
  return all
    .sort((a, b) => String(stamp(b)).localeCompare(String(stamp(a))))
    .slice(0, 12)
})

/**
 * Owners who have granted write access covering a category — i.e. via a
 * read/write whole-library share or a read/write share of that category.
 * A single writable album does not qualify (you can't create new items from
 * it). Returned newest distinct owner list; the category subpage adds into
 * the first one's collection.
 */
function writeOwnersForCategory(category) {
  const owners = []
  const seen = new Set()
  const push = uid => {
    if (uid && !seen.has(uid)) { seen.add(uid); owners.push(uid) }
  }
  for (const share of libraries.value) {
    if (share.canWrite === true) push(share.sharedByUser)
  }
  for (const share of categories.value) {
    if (share.canWrite === true && share.category === category) push(share.sharedByUser)
  }
  return owners
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/share/with-me'))
    const data = res.data.ocs?.data ?? {}
    albums.value     = data.albums     ?? []
    playlists.value  = data.playlists  ?? []
    libraries.value  = data.libraries  ?? []
    categories.value = data.categories ?? []
  } catch (e) {
    console.error('Failed to load shared items', e)
    error.value = e
  } finally {
    loading.value = false
  }
}

export function useSharedContent() {
  return {
    loading,
    error,
    itemsByCategory,
    sharedCategories,
    sharedPlaylists,
    recentShared,
    writeOwnersForCategory,
    load,
  }
}
