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
        @detail="showDetail"
        @edit="openEdit"
        @delete="confirmDelete"
      />
    </NcAppContent>

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
  </NcContent>
</template>

<script setup>
import { ref } from 'vue'
import {
  NcContent, NcAppContent, NcAppNavigation, NcAppNavigationItem,
  NcAppNavigationSettings, NcButton, NcDialog,
} from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import AddEditModal from './components/AddEditModal.vue'
import CollectionView from './components/CollectionView.vue'
import HomeView from './components/HomeView.vue'
import ItemDetailView from './components/ItemDetailView.vue'
import SettingsPanel from './components/SettingsPanel.vue'

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

// ── save / delete ─────────────────────────────────────────────────────────────
async function saveItem(payload) {
  try {
    let saved
    if (editingItem.value) {
      const res = await axios.put(
        generateOcsUrl(`/apps/crate/api/v1/media/${editingItem.value.id}`),
        payload,
      )
      saved = res.data.ocs?.data
    } else {
      await axios.post(generateOcsUrl('/apps/crate/api/v1/media'), payload)
    }

    closeModal()

    // Update selectedItem in case we edited from detail view
    if (saved && selectedItem.value?.id === saved.id) {
      selectedItem.value = saved
    }

    if (view.value === 'detail') {
      // Refresh the list in background so it's current when navigating back
      if (previousView.value !== 'home') {
        loadItems()
      } else {
        homeView.value?.load()
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
</style>
