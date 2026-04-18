<template>
  <NcContent app-name="crate">
    <NcAppNavigation>
      <template #list>
        <NcAppNavigationItem
          name="Home"
          :active="view === 'home'"
          href="#/"
          @click="switchView('home')"
        />
        <NcAppNavigationItem
          name="Collection"
          :active="view === 'collection'"
          href="#/collection"
          @click="switchView('collection')"
        />
        <NcAppNavigationItem
          name="Wishlist"
          :active="view === 'wishlist'"
          href="#/wishlist"
          @click="switchView('wishlist')"
        />
        <NcAppNavigationItem
          name="Playlists"
          :active="view === 'playlists' || view === 'playlist-detail'"
          href="#/playlists"
          @click="switchView('playlists')"
        />
        <NcAppNavigationItem
          name="Shared with me"
          :active="view === 'shared'"
          href="#/shared"
          @click="switchView('shared')"
        />
      </template>
      <template #footer>
        <NcAppNavigationSettings
          name="Settings"
          @click="settingsOpen = true"
        />
      </template>
    </NcAppNavigation>

    <SettingsPanel v-model:open="settingsOpen" />

    <NcAppContent>
      <!-- Item detail view -->
      <ItemDetailView
        v-if="view === 'detail' && selectedItem"
        :item="selectedItem"
        :has-token="hasDiscogsToken"
        @back="goBack"
        @edit="openEdit"
        @delete="confirmDelete"
        @enriched="handleEnriched"
        @add-to-playlist="openAddToPlaylist"
        @share="openShareAlbum"
      />

      <!-- Playlist detail view -->
      <PlaylistDetailView
        v-else-if="view === 'playlist-detail' && selectedPlaylist"
        :playlist="selectedPlaylist"
        @back="goBack"
        @detail="showDetail"
        @delete="handleDeletePlaylist"
        @share="openSharePlaylist"
        @updated="handlePlaylistUpdated"
      />

      <!-- Playlists view -->
      <PlaylistsView
        v-else-if="view === 'playlists'"
        ref="playlistsView"
        @open="showPlaylistDetail"
      />

      <!-- Shared with me view -->
      <SharedView
        v-else-if="view === 'shared'"
        ref="sharedView"
        @detail="showDetail"
        @playlist="showPlaylistDetail"
      />

      <!-- Home / landing view -->
      <HomeView
        v-else-if="view === 'home'"
        ref="homeView"
        @add="openAdd"
        @detail="showDetail"
      />

      <!-- Collection / Wishlist -->
      <CollectionView
        v-else
        :items="items"
        :loading="loading"
        :status="view === 'wishlist' ? 'wanted' : 'owned'"
        @add="openAdd"
        @import="importOpen = true"
        @detail="showDetail"
        @edit="openEdit"
        @delete="confirmDelete"
      />
    </NcAppContent>

    <ImportModal
      :show="importOpen"
      :has-token="hasDiscogsToken"
      @close="importOpen = false"
      @imported="handleImported"
    />

    <AddEditModal
      :show="modalOpen"
      :item="editingItem"
      :default-status="view === 'wishlist' ? 'wanted' : 'owned'"
      @close="closeModal"
      @save="saveItem"
    />

    <NcDialog
      v-if="deletingItem"
      name="Delete item"
      :open="!!deletingItem"
      @closing="deletingItem = null"
    >
      <p>Delete <strong>{{ deletingItem.artist }} — {{ deletingItem.title }}</strong>? This cannot be undone.</p>
      <template #actions>
        <NcButton
          variant="tertiary"
          @click="deletingItem = null"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="error"
          @click="deleteItem"
        >
          Delete
        </NcButton>
      </template>
    </NcDialog>

    <AddToPlaylistModal
      :show="showAddToPlaylist"
      :item="addToPlaylistItem"
      @close="showAddToPlaylist = false"
    />

    <ShareModal
      :show="showShareModal"
      :target="shareTarget"
      @close="showShareModal = false"
    />

    <!-- Floating progress chip (visible when modal is closed but a queue is running) -->
    <Transition name="eq-chip">
      <div
        v-if="activeQueue && !importOpen"
        class="enrich-chip"
      >
        <span class="enrich-chip__text">{{ activeQueue.label }} {{ activeQueue.queue.done.value }} / {{ activeQueue.queue.total.value }}</span>
        <div class="enrich-chip__bar-wrap">
          <div
            class="enrich-chip__bar"
            :style="{ width: activeQueue.queue.progress.value + '%' }"
          />
        </div>
        <button
          class="enrich-chip__cancel"
          title="Stop"
          @click="activeQueue.queue.cancel()"
        >
          ✕
        </button>
      </div>
    </Transition>
  </NcContent>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import {
  NcContent, NcAppContent, NcAppNavigation, NcAppNavigationItem,
  NcAppNavigationSettings, NcButton, NcDialog,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import AddEditModal from './components/AddEditModal.vue'
import AddToPlaylistModal from './components/AddToPlaylistModal.vue'
import CollectionView from './components/CollectionView.vue'
import HomeView from './components/HomeView.vue'
import ImportModal from './components/ImportModal.vue'
import ItemDetailView from './components/ItemDetailView.vue'
import PlaylistDetailView from './components/PlaylistDetailView.vue'
import PlaylistsView from './components/PlaylistsView.vue'
import SettingsPanel from './components/SettingsPanel.vue'
import ShareModal from './components/ShareModal.vue'
import SharedView from './components/SharedView.vue'
import { useEnrichQueue } from './composables/useEnrichQueue.js'
import { useMarketValueQueue } from './composables/useMarketValueQueue.js'
import { useSettings } from './composables/useSettings.js'

const enrich = useEnrichQueue()
const market = useMarketValueQueue()
const { autoEnrichOnClick } = useSettings()

const activeQueue = computed(() => {
  if (enrich.running.value) return { queue: enrich, label: 'Enriching albums' }
  if (market.running.value) return { queue: market, label: 'Fetching market rates' }
  return null
})

// ── hash routing ──────────────────────────────────────────────────────────────
// Counter-based suppression: each programmatic setHash increments this; the
// corresponding hashchange event decrements it and returns early.  Using a
// counter (not a boolean + setTimeout) avoids async timing races.
let pendingHashSets = 0
function setHash(hash) {
  pendingHashSets++
  window.location.hash = hash
}

function hashForView(v, id = null) {
  if (v === 'detail' && id) return `#/detail/${id}`
  if (v === 'playlist-detail' && id) return `#/playlists/${id}`
  if (v === 'collection') return '#/collection'
  if (v === 'wishlist') return '#/wishlist'
  if (v === 'playlists') return '#/playlists'
  if (v === 'shared') return '#/shared'
  return '#/'
}

function parseHash() {
  const parts = (window.location.hash || '#/').replace(/^#\//, '').split('/')
  if (parts[0] === 'detail' && parts[1]) return { view: 'detail', itemId: parseInt(parts[1], 10), playlistId: null }
  if (parts[0] === 'playlists' && parts[1]) return { view: 'playlist-detail', itemId: null, playlistId: parseInt(parts[1], 10) }
  if (parts[0] === 'playlists') return { view: 'playlists', itemId: null, playlistId: null }
  if (parts[0] === 'collection') return { view: 'collection', itemId: null, playlistId: null }
  if (parts[0] === 'wishlist') return { view: 'wishlist', itemId: null, playlistId: null }
  if (parts[0] === 'shared') return { view: 'shared', itemId: null, playlistId: null }
  return { view: 'home', itemId: null, playlistId: null }
}

// ── state ─────────────────────────────────────────────────────────────────────
const view = ref('home')
const previousView = ref('home')
const selectedItem = ref(null)
const savedScrollTop = ref(0)
const settingsOpen = ref(false)
const items = ref([])
const loading = ref(false)
const homeView = ref(null)

const modalOpen = ref(false)
const editingItem = ref(null)
const deletingItem = ref(null)
const importOpen = ref(false)
const hasDiscogsToken = ref(false)

// playlist + sharing state
const selectedPlaylist = ref(null)
const playlistsView = ref(null)
const sharedView = ref(null)
const addToPlaylistItem = ref(null)
const showAddToPlaylist = ref(false)
const shareTarget = ref(null)
const showShareModal = ref(false)

// ── init ──────────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'))
    hasDiscogsToken.value = res.data.ocs?.data?.hasToken ?? false
  } catch { /* ignore */ }

  // Restore view from URL hash (supports page refresh and direct links)
  await restoreFromHash()

  window.addEventListener('beforeunload', handleBeforeUnload)
  window.addEventListener('hashchange', handleHashChange)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
  window.removeEventListener('hashchange', handleHashChange)
})

async function restoreFromHash() {
  const { view: v, itemId, playlistId } = parseHash()
  if (v === 'detail' && itemId) {
    try {
      const res = await axios.get(generateOcsUrl(`/apps/crate/api/v1/media/${itemId}`))
      const item = res.data.ocs?.data
      if (item) {
        selectedItem.value = item
        previousView.value = 'collection'
        view.value = 'detail'
        return
      }
    } catch { /* fall through to home */ }
  } else if (v === 'playlist-detail' && playlistId) {
    try {
      const res = await axios.get(generateOcsUrl(`/apps/crate/api/v1/playlists/${playlistId}`))
      const playlist = res.data.ocs?.data
      if (playlist) {
        selectedPlaylist.value = playlist
        previousView.value = 'playlists'
        view.value = 'playlist-detail'
        return
      }
    } catch { /* fall through to home */ }
  } else if (v === 'collection' || v === 'wishlist') {
    view.value = v
    loadItems()
    return
  } else if (v === 'playlists' || v === 'shared') {
    view.value = v
    return
  }
  // home or unrecognised — update hash to canonical form
  setHash('#/')
}

function handleHashChange() {
  if (pendingHashSets > 0) {
    pendingHashSets--
    return
  }
  // Only reached for genuine browser back/forward navigation
  const { view: v, itemId, playlistId } = parseHash()
  if (v === 'detail' && itemId) {
    // If we're already showing this exact item, nothing to do
    if (view.value === 'detail' && selectedItem.value?.id === itemId) return
    // Try to find in the already-loaded list first to avoid a fetch
    const cached = items.value.find(i => i.id === itemId)
    if (cached) {
      selectedItem.value = cached
      view.value = 'detail'
    }
    // If not cached, do nothing — don't call restoreFromHash (would overwrite previousView)
  } else if (v === 'playlist-detail' && playlistId) {
    if (view.value === 'playlist-detail' && selectedPlaylist.value?.id === playlistId) return
    // selectedPlaylist should still be in memory from navigation
    if (selectedPlaylist.value?.id === playlistId) {
      view.value = 'playlist-detail'
    }
  } else if (v !== view.value) {
    view.value = v
    selectedItem.value = null
    selectedPlaylist.value = null
    if (v === 'collection' || v === 'wishlist') loadItems()
  }
}

function handleBeforeUnload(e) {
  if (enrich.running.value || market.running.value) {
    e.preventDefault()
    e.returnValue = 'A Discogs operation is still running. Leaving the page will stop it.'
  }
}

// ── data loading ──────────────────────────────────────────────────────────────
async function loadItems() {
  loading.value = true
  try {
    const response = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = response.data.ocs?.data ?? []
    items.value = view.value === 'wishlist'
      ? all.filter(i => i.status === 'wanted')
      : all.filter(i => i.status === 'owned')
  } catch (e) {
    console.error('Failed to load media items', e)
  } finally {
    loading.value = false
  }
}

// ── navigation ────────────────────────────────────────────────────────────────
function switchView(newView) {
  view.value = newView
  selectedItem.value = null
  selectedPlaylist.value = null
  setHash(hashForView(newView))
  if (newView === 'collection' || newView === 'wishlist') {
    loadItems()
  } else if (newView === 'playlists') {
    nextTick(() => playlistsView.value?.load())
  } else if (newView === 'shared') {
    nextTick(() => sharedView.value?.load())
  }
}

function showDetail(item) {
  // Save scroll position so Back can restore it
  savedScrollTop.value = document.getElementById('app-content-vue')?.scrollTop ?? 0
  previousView.value = view.value
  selectedItem.value = item
  view.value = 'detail'
  setHash(hashForView('detail', item.id))
  // Auto-enrich items that haven't been enriched yet (search-then-enrich handles missing discogsId)
  const notEnriched = !item.genres && !item.artistBio && !(Array.isArray(item.tracklist) && item.tracklist.length > 0)
  if (notEnriched && autoEnrichOnClick.value) {
    triggerEnrich(item.id)
  }
}

async function triggerEnrich(id) {
  try {
    const res = await axios.post(generateOcsUrl(`/apps/crate/api/v1/media/${id}/enrich`))
    const enriched = res.data.ocs?.data
    if (enriched) {
      if (selectedItem.value && selectedItem.value.id == enriched.id) {
        selectedItem.value = enriched
      }
      const idx = items.value.findIndex(i => i.id == enriched.id)
      if (idx !== -1) items.value[idx] = enriched
      if (view.value === 'home') homeView.value?.load()
    }
  } catch (e) {
    // Discogs unavailable or no token — silently skip
  }
}

async function goBack() {
  const dest = previousView.value
  view.value = dest
  selectedItem.value = null
  if (dest !== 'playlist-detail') selectedPlaylist.value = null
  setHash(hashForView(dest))
  // Restore scroll position after the list has re-rendered
  const top = savedScrollTop.value
  await nextTick()
  document.getElementById('app-content-vue')?.scrollTo({ top, behavior: 'instant' })
}

// ── playlist navigation ───────────────────────────────────────────────────────
async function showPlaylistDetail(playlist) {
  savedScrollTop.value = document.getElementById('app-content-vue')?.scrollTop ?? 0
  previousView.value = view.value
  // If the playlist came from the grid list it only has itemCount/coverId.
  // Fetch the full object (with items[]) before showing the detail view.
  if (!Array.isArray(playlist.items)) {
    try {
      const res = await axios.get(generateOcsUrl(`/apps/crate/api/v1/playlists/${playlist.id}`))
      playlist = res.data.ocs?.data ?? playlist
    } catch { /* fall through with whatever we have */ }
  }
  selectedPlaylist.value = playlist
  view.value = 'playlist-detail'
  setHash(hashForView('playlist-detail', playlist.id))
}

async function handleDeletePlaylist(playlist) {
  try {
    await axios.delete(generateOcsUrl(`/apps/crate/api/v1/playlists/${playlist.id}`))
  } catch (e) {
    console.error('Failed to delete playlist', e)
  }
  view.value = 'playlists'
  selectedPlaylist.value = null
  setHash('#/playlists')
  nextTick(() => playlistsView.value?.load())
}

function handlePlaylistUpdated(updatedPlaylist) {
  selectedPlaylist.value = updatedPlaylist
}

// ── add to playlist / share modals ────────────────────────────────────────────
function openAddToPlaylist(item) {
  addToPlaylistItem.value = item
  showAddToPlaylist.value = true
}

function openShareAlbum(item) {
  shareTarget.value = { type: 'album', id: item.id, name: `${item.artist} — ${item.title}` }
  showShareModal.value = true
}

function openSharePlaylist(playlist) {
  shareTarget.value = { type: 'playlist', id: playlist.id, name: playlist.name }
  showShareModal.value = true
}

// ── modal helpers ─────────────────────────────────────────────────────────────
function openAdd() {
  editingItem.value = null
  modalOpen.value = true
}

function openEdit(item) {
  editingItem.value = item
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
  editingItem.value = null
}

function handleImported() {
  if (view.value === 'home') {
    homeView.value?.load()
  } else {
    loadItems()
  }
}

// ── save / delete ─────────────────────────────────────────────────────────────
async function saveItem(payload) {
  try {
    let saved
    // Capture before closeModal clears it
    const wasEditing = !!editingItem.value
    const editId = editingItem.value?.id

    // Pull out artwork side-effects before sending to the API
    const artworkFile = payload._artworkFile ?? null
    const removeArtwork = payload._removeArtwork ?? false
    // Save for fallback ID lookup (new item OCS quirk)
    const payloadTitle = payload.title
    const payloadArtist = payload.artist
    delete payload._artworkFile
    delete payload._removeArtwork

    if (wasEditing) {
      const res = await axios.put(
        generateOcsUrl(`/apps/crate/api/v1/media/${editId}`),
        payload,
      )
      saved = res.data.ocs?.data
    } else {
      const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/media'), payload)
      saved = res.data.ocs?.data
    }

    // Update selectedItem and items list before closing the modal so Vue
    // flushes the reactive updates in a single batch with the modal close.
    if (saved) {
      // eslint-disable-next-line eqeqeq
      if (selectedItem.value && selectedItem.value.id == saved.id) {
        selectedItem.value = saved
      }
      // Patch the items list too
      const idx = items.value.findIndex(i => i.id == saved.id)
      if (idx !== -1) items.value[idx] = saved
    }

    closeModal()

    // Handle artwork upload / removal
    // For PUT responses saved may be null (OCS quirk), fall back to editId.
    // For POST (new item) saved may also be null — resolve by matching in items list.
    let targetId = saved?.id ?? editId
    if (!targetId && !wasEditing && artworkFile) {
      try {
        const allRes = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
        const allItems = allRes.data.ocs?.data ?? []
        const match = allItems
          .filter(i => i.title === payloadTitle && i.artist === payloadArtist)
          .sort((a, b) => b.id - a.id)[0]
        if (match) targetId = match.id
      } catch { /* ignore */ }
    }
    if (targetId) {
      if (artworkFile) {
        try {
          const fd = new FormData()
          fd.append('file', artworkFile)
          await axios.post(generateUrl(`/apps/crate/artwork/${targetId}`), fd)
          // Re-fetch item so artworkPath = 'local' is reflected everywhere
          const r = await axios.get(generateOcsUrl(`/apps/crate/api/v1/media/${targetId}`))
          const fresh = r.data.ocs?.data
          if (fresh) {
            // eslint-disable-next-line eqeqeq
            if (selectedItem.value?.id == fresh.id) selectedItem.value = fresh
            const idx = items.value.findIndex(i => i.id == fresh.id)
            if (idx !== -1) items.value[idx] = fresh
            saved = fresh
          }
        } catch (e) {
          console.error('Artwork upload failed', e)
        }
      } else if (removeArtwork) {
        try {
          await axios.delete(generateUrl(`/apps/crate/artwork/${targetId}`))
          const r = await axios.get(generateOcsUrl(`/apps/crate/api/v1/media/${targetId}`))
          const fresh = r.data.ocs?.data
          if (fresh) {
            // eslint-disable-next-line eqeqeq
            if (selectedItem.value?.id == fresh.id) selectedItem.value = fresh
            const idx = items.value.findIndex(i => i.id == fresh.id)
            if (idx !== -1) items.value[idx] = fresh
            saved = fresh
          }
        } catch (e) {
          console.error('Artwork removal failed', e)
        }
      }
    }

    // Auto-enrich newly added items that have a Discogs ID
    if (!wasEditing && saved?.id && saved?.discogsId) {
      triggerEnrich(saved.id)
    }

    if (view.value === 'detail') {
      // The PUT response data (`saved`) is sometimes not populated due to OCS
      // response format differences. Always re-fetch the item after an edit to
      // guarantee the detail view is current.
      if (wasEditing && editId) {
        try {
          const r = await axios.get(generateOcsUrl(`/apps/crate/api/v1/media/${editId}`))
          const fresh = r.data.ocs?.data
          if (fresh) {
            // eslint-disable-next-line eqeqeq
            if (selectedItem.value?.id == fresh.id) selectedItem.value = fresh
            const idx = items.value.findIndex(i => i.id == fresh.id)
            if (idx !== -1) items.value[idx] = fresh
          }
        } catch { /* ignore — view will be stale until next navigation */ }
      }
    } else if (view.value === 'home') {
      homeView.value?.load()
    } else {
      await loadItems()
    }
  } catch (e) {
    console.error('Failed to save item', e)
  }
}

function confirmDelete(item) {
  deletingItem.value = item
}

async function deleteItem() {
  const inDetail = view.value === 'detail'
  try {
    await axios.delete(generateOcsUrl(`/apps/crate/api/v1/media/${deletingItem.value.id}`))
    deletingItem.value = null
    if (inDetail) {
      goBack()
    }
    if (previousView.value === 'home' || view.value === 'home') {
      homeView.value?.load()
    } else {
      await loadItems()
    }
  } catch (e) {
    console.error('Failed to delete item', e)
  }
}

// ── enrich ────────────────────────────────────────────────────────────────────
function handleEnriched(updated) {
  selectedItem.value = updated
  // Patch item in the items list too
  const idx = items.value.findIndex(i => i.id === updated.id)
  if (idx !== -1) {
    items.value[idx] = updated
  }
}
</script>

<style scoped>
/* NcAppContent already provides padding via Nextcloud styles */

/* ── Floating enrichment chip ── */
.enrich-chip {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 2000;
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--color-main-background);
  border: 1px solid var(--color-border-dark);
  border-radius: 24px;
  padding: 8px 14px 8px 16px;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.3);
  font-size: 0.85em;
  min-width: 220px;
}

.enrich-chip__text {
  white-space: nowrap;
  color: var(--color-main-text);
  flex-shrink: 0;
}

.enrich-chip__bar-wrap {
  flex: 1;
  height: 5px;
  background: var(--color-background-dark);
  border-radius: 3px;
  overflow: hidden;
  min-width: 60px;
}

.enrich-chip__bar {
  height: 100%;
  background: var(--color-primary-element);
  border-radius: 3px;
  transition: width 0.4s ease;
}

.enrich-chip__cancel {
  flex-shrink: 0;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  font-size: 1em;
  line-height: 1;
  padding: 2px 4px;
  border-radius: 50%;
  transition: background 0.1s, color 0.1s;
}

.enrich-chip__cancel:hover {
  background: var(--color-background-hover);
  color: var(--color-error);
}

.eq-chip-enter-active, .eq-chip-leave-active { transition: opacity 0.2s, transform 0.2s; }
.eq-chip-enter-from, .eq-chip-leave-to { opacity: 0; transform: translateY(12px); }

@media (max-width: 480px) {
  .enrich-chip {
    left: 12px;
    right: 12px;
    bottom: 12px;
    min-width: 0;
  }
}
</style>
