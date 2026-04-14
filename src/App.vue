<template>
  <NcContent app-name="crate">
    <NcAppNavigation>
      <template #list>
        <NcAppNavigationItem
          name="Home"
          :active="view === 'home'"
          @click="switchView('home')"
        />
        <NcAppNavigationItem
          name="My Collection"
          :active="view === 'collection'"
          @click="switchView('collection')"
        />
        <NcAppNavigationItem
          name="Wishlist"
          :active="view === 'wishlist'"
          @click="switchView('wishlist')"
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
        @back="goBack"
        @edit="openEdit"
        @delete="confirmDelete"
        @enriched="handleEnriched"
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
          type="tertiary"
          @click="deletingItem = null"
        >
          Cancel
        </NcButton>
        <NcButton
          type="error"
          @click="deleteItem"
        >
          Delete
        </NcButton>
      </template>
    </NcDialog>

    <!-- Floating enrichment progress chip (visible when modal is closed but queue is running) -->
    <Transition name="eq-chip">
      <div
        v-if="enrich.running.value && !importOpen"
        class="enrich-chip"
      >
        <span class="enrich-chip__text">Enriching {{ enrich.done.value }} / {{ enrich.total.value }}</span>
        <div class="enrich-chip__bar-wrap">
          <div
            class="enrich-chip__bar"
            :style="{ width: enrich.progress.value + '%' }"
          />
        </div>
        <button
          class="enrich-chip__cancel"
          title="Stop enrichment"
          @click="enrich.cancel()"
        >
          ✕
        </button>
      </div>
    </Transition>
  </NcContent>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import {
  NcContent, NcAppContent, NcAppNavigation, NcAppNavigationItem,
  NcAppNavigationSettings, NcButton, NcDialog,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import AddEditModal from './components/AddEditModal.vue'
import CollectionView from './components/CollectionView.vue'
import HomeView from './components/HomeView.vue'
import ImportModal from './components/ImportModal.vue'
import ItemDetailView from './components/ItemDetailView.vue'
import SettingsPanel from './components/SettingsPanel.vue'
import { useEnrichQueue } from './composables/useEnrichQueue.js'

const enrich = useEnrichQueue()

// ── state ─────────────────────────────────────────────────────────────────────
const view = ref('home')
const previousView = ref('home')
const selectedItem = ref(null)
const settingsOpen = ref(false)
const items = ref([])
const loading = ref(false)
const homeView = ref(null)

const modalOpen = ref(false)
const editingItem = ref(null)
const deletingItem = ref(null)
const importOpen = ref(false)
const hasDiscogsToken = ref(false)

// ── init ──────────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'))
    hasDiscogsToken.value = res.data.ocs?.data?.hasToken ?? false
  } catch { /* ignore */ }
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onUnmounted(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})

function handleBeforeUnload(e) {
  if (enrich.running.value) {
    e.preventDefault()
    e.returnValue = 'Discogs enrichment is still running. Leaving the page will stop it.'
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
  if (newView !== 'home') {
    loadItems()
  }
}

function showDetail(item) {
  previousView.value = view.value
  selectedItem.value = item
  view.value = 'detail'
  // Auto-enrich items that haven't been enriched yet (search-then-enrich handles missing discogsId)
  const notEnriched = !item.genres && !item.artistBio && !(Array.isArray(item.tracklist) && item.tracklist.length > 0)
  if (notEnriched) {
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

function goBack() {
  view.value = previousView.value
  selectedItem.value = null
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

    closeModal()

    // Update selectedItem immediately if we just edited the item being viewed
    if (saved) {
      // eslint-disable-next-line eqeqeq
      if (selectedItem.value && selectedItem.value.id == saved.id) {
        selectedItem.value = saved
      }
      // Patch the items list too
      const idx = items.value.findIndex(i => i.id == saved.id)
      if (idx !== -1) items.value[idx] = saved
    }

    // Auto-enrich newly added items that have a Discogs ID
    if (!wasEditing && saved?.id && saved?.discogsId) {
      triggerEnrich(saved.id)
    }

    if (view.value === 'detail') {
      // selectedItem already patched above; list will refresh on back navigation
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
</style>
