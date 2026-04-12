<template>
  <NcContent app-name="crate">
    <NcAppNavigation>
      <template #list>
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
    </NcAppNavigation>

    <NcAppContent>
      <div class="crate-content">
        <div class="crate-header">
          <h2>{{ view === 'collection' ? 'My Collection' : 'Wishlist' }}</h2>
          <NcButton
            type="primary"
            @click="openAdd"
          >
            <template #icon>
              <span class="crate-btn-icon">+</span>
            </template>
            Add item
          </NcButton>
        </div>

        <p
          v-if="loading"
          class="crate-status"
        >
          Loading…
        </p>
        <p
          v-else-if="items.length === 0"
          class="crate-status"
        >
          {{ view === 'collection' ? 'No items yet. Add your first record!' : 'Your wishlist is empty.' }}
        </p>

        <ul
          v-else
          class="crate-list"
        >
          <li
            v-for="item in items"
            :key="item.id"
            class="crate-item"
          >
            <div class="crate-item-info">
              <span class="crate-item-title">{{ item.artist }} — {{ item.title }}</span>
              <span class="crate-item-meta">{{ item.format }}<template v-if="item.year">, {{ item.year }}</template></span>
              <span
                v-if="item.notes"
                class="crate-item-notes"
              >{{ item.notes }}</span>
            </div>
            <div class="crate-item-actions">
              <NcButton
                type="tertiary"
                :aria-label="'Edit ' + item.title"
                @click="openEdit(item)"
              >
                Edit
              </NcButton>
              <NcButton
                type="tertiary"
                :aria-label="'Delete ' + item.title"
                @click="confirmDelete(item)"
              >
                Delete
              </NcButton>
            </div>
          </li>
        </ul>
      </div>
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
import { ref, onMounted } from 'vue'
import { NcContent, NcAppContent, NcAppNavigation, NcAppNavigationItem, NcButton, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import AddEditModal from './components/AddEditModal.vue'

const view = ref('collection')
const items = ref([])
const loading = ref(false)

const modalOpen = ref(false)
const editingItem = ref(null)
const deletingItem = ref(null)

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

function switchView(newView) {
  view.value = newView
  loadItems()
}

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

async function saveItem(payload) {
  try {
    if (editingItem.value) {
      await axios.put(generateOcsUrl(`/apps/crate/api/v1/media/${editingItem.value.id}`), payload)
    } else {
      await axios.post(generateOcsUrl('/apps/crate/api/v1/media'), payload)
    }
    closeModal()
    await loadItems()
  } catch (e) {
    console.error('Failed to save item', e)
  }
}

function confirmDelete(item) {
  deletingItem.value = item
}

async function deleteItem() {
  try {
    await axios.delete(generateOcsUrl(`/apps/crate/api/v1/media/${deletingItem.value.id}`))
    deletingItem.value = null
    await loadItems()
  } catch (e) {
    console.error('Failed to delete item', e)
  }
}

onMounted(loadItems)
</script>

<style scoped>
.crate-content {
  padding: 20px;
  padding-top: calc(var(--default-clickable-area, 44px) + 8px);
  max-width: 900px;
}

.crate-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.crate-header h2 {
  margin: 0;
}

.crate-btn-icon {
  font-size: 1.2em;
  line-height: 1;
  margin-right: 2px;
}

.crate-status {
  color: var(--color-text-maxcontrast);
}

.crate-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.crate-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-radius: var(--border-radius-large);
  transition: background 0.1s;
}

.crate-item:hover {
  background: var(--color-background-hover);
}

.crate-item-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.crate-item-title {
  font-weight: 500;
}

.crate-item-meta {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

.crate-item-notes {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  font-style: italic;
}

.crate-item-actions {
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity 0.1s;
}

.crate-item:hover .crate-item-actions {
  opacity: 1;
}
</style>
