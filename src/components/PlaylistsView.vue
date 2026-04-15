<template>
  <div class="playlists-view">
    <!-- Toolbar -->
    <div class="pv-toolbar">
      <h2 class="pv-heading">
        Playlists
      </h2>
      <NcButton
        variant="primary"
        @click="showCreate = true"
      >
        <template #icon>
          <span class="pv-plus">+</span>
        </template>
        New playlist
      </NcButton>
    </div>

    <p
      v-if="loading"
      class="pv-status"
    >
      Loading…
    </p>

    <div
      v-else-if="playlists.length === 0"
      class="pv-empty"
    >
      <p>No playlists yet. Create one to organise your collection.</p>
      <NcButton
        variant="primary"
        @click="showCreate = true"
      >
        Create a playlist
      </NcButton>
    </div>

    <div
      v-else
      class="pv-grid"
    >
      <div
        v-for="pl in playlists"
        :key="pl.id"
        class="pv-card"
        @click="$emit('open', pl)"
      >
        <div
          class="pv-card-art"
          :style="coverStyle(pl)"
        >
          <span class="pv-card-count">{{ pl.itemCount }} {{ pl.itemCount === 1 ? 'album' : 'albums' }}</span>
        </div>
        <div class="pv-card-info">
          <span class="pv-card-name">{{ pl.name }}</span>
          <span
            v-if="pl.description"
            class="pv-card-desc"
          >{{ pl.description }}</span>
        </div>
        <div
          class="pv-card-actions"
          @click.stop
        >
          <NcButton
            variant="tertiary"
            :aria-label="'Rename ' + pl.name"
            @click="startRename(pl)"
          >
            Rename
          </NcButton>
          <NcButton
            variant="error"
            :aria-label="'Delete ' + pl.name"
            @click="confirmDelete(pl)"
          >
            Delete
          </NcButton>
        </div>
      </div>
    </div>

    <!-- Create dialog -->
    <NcDialog
      v-if="showCreate"
      name="New playlist"
      :open="showCreate"
      @closing="showCreate = false"
    >
      <div class="pv-dialog-form">
        <div class="pv-field">
          <label for="pl-name-new">Name</label>
          <input
            id="pl-name-new"
            v-model="createName"
            type="text"
            placeholder="e.g. Road trip vibes"
            maxlength="500"
            autocomplete="off"
            @keydown.enter="doCreate"
          >
        </div>
        <div class="pv-field">
          <label for="pl-desc-new">Description (optional)</label>
          <input
            id="pl-desc-new"
            v-model="createDesc"
            type="text"
            placeholder="A short description"
            maxlength="500"
            autocomplete="off"
          >
        </div>
      </div>
      <template #actions>
        <NcButton
          variant="tertiary"
          @click="showCreate = false"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="primary"
          :disabled="!createName.trim() || creating"
          @click="doCreate"
        >
          {{ creating ? 'Creating…' : 'Create' }}
        </NcButton>
      </template>
    </NcDialog>

    <!-- Rename dialog -->
    <NcDialog
      v-if="renamingPlaylist"
      name="Rename playlist"
      :open="!!renamingPlaylist"
      @closing="renamingPlaylist = null"
    >
      <div class="pv-dialog-form">
        <div class="pv-field">
          <label for="pl-name-edit">Name</label>
          <input
            id="pl-name-edit"
            v-model="renameValue"
            type="text"
            maxlength="500"
            autocomplete="off"
            @keydown.enter="doRename"
          >
        </div>
        <div class="pv-field">
          <label for="pl-desc-edit">Description (optional)</label>
          <input
            id="pl-desc-edit"
            v-model="renameDesc"
            type="text"
            maxlength="500"
            autocomplete="off"
          >
        </div>
      </div>
      <template #actions>
        <NcButton
          variant="tertiary"
          @click="renamingPlaylist = null"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="primary"
          :disabled="!renameValue.trim() || renaming"
          @click="doRename"
        >
          {{ renaming ? 'Saving…' : 'Save' }}
        </NcButton>
      </template>
    </NcDialog>

    <!-- Delete confirm -->
    <NcDialog
      v-if="deletingPlaylist"
      name="Delete playlist"
      :open="!!deletingPlaylist"
      @closing="deletingPlaylist = null"
    >
      <p>Delete <strong>{{ deletingPlaylist.name }}</strong>? The albums in it won't be deleted.</p>
      <template #actions>
        <NcButton
          variant="tertiary"
          @click="deletingPlaylist = null"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="error"
          @click="doDelete"
        >
          Delete
        </NcButton>
      </template>
    </NcDialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'

defineEmits(['open'])

const playlists = ref([])
const loading = ref(false)

const showCreate = ref(false)
const createName = ref('')
const createDesc = ref('')
const creating = ref(false)

const renamingPlaylist = ref(null)
const renameValue = ref('')
const renameDesc = ref('')
const renaming = ref(false)

const deletingPlaylist = ref(null)

function coverStyle(pl) {
  if (pl.coverId) {
    const url = generateUrl('/apps/crate/artwork/' + pl.coverId)
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  return { background: 'linear-gradient(135deg, #374151, #6b7280)' }
}

async function load() {
  loading.value = true
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/playlists'))
    playlists.value = res.data.ocs?.data ?? []
  } catch (e) {
    console.error('Failed to load playlists', e)
  } finally {
    loading.value = false
  }
}

async function doCreate() {
  if (!createName.value.trim()) return
  creating.value = true
  try {
    const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/playlists'), {
      name: createName.value.trim(),
      description: createDesc.value.trim() || null,
    })
    const created = res.data.ocs?.data
    if (created) playlists.value.push({ ...created, itemCount: 0, coverId: null })
    showCreate.value = false
    createName.value = ''
    createDesc.value = ''
  } catch (e) {
    console.error('Failed to create playlist', e)
  } finally {
    creating.value = false
  }
}

function startRename(pl) {
  renamingPlaylist.value = pl
  renameValue.value = pl.name
  renameDesc.value = pl.description ?? ''
}

async function doRename() {
  if (!renameValue.value.trim() || !renamingPlaylist.value) return
  renaming.value = true
  try {
    const res = await axios.put(generateOcsUrl(`/apps/crate/api/v1/playlists/${renamingPlaylist.value.id}`), {
      name: renameValue.value.trim(),
      description: renameDesc.value.trim() || null,
    })
    const updated = res.data.ocs?.data
    if (updated) {
      const idx = playlists.value.findIndex(p => p.id === renamingPlaylist.value.id)
      if (idx !== -1) playlists.value[idx] = { ...playlists.value[idx], ...updated }
    }
    renamingPlaylist.value = null
  } catch (e) {
    console.error('Failed to rename playlist', e)
  } finally {
    renaming.value = false
  }
}

function confirmDelete(pl) {
  deletingPlaylist.value = pl
}

async function doDelete() {
  if (!deletingPlaylist.value) return
  try {
    await axios.delete(generateOcsUrl(`/apps/crate/api/v1/playlists/${deletingPlaylist.value.id}`))
    playlists.value = playlists.value.filter(p => p.id !== deletingPlaylist.value.id)
    deletingPlaylist.value = null
  } catch (e) {
    console.error('Failed to delete playlist', e)
  }
}

onMounted(load)
defineExpose({ load })
</script>

<style scoped>
.playlists-view {
  padding: 0 20px 40px;
  max-width: 1100px;
}

.pv-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 24px;
}

.pv-heading {
  margin: 0;
  font-size: 1.4em;
}

.pv-plus {
  font-size: 1.1em;
}

.pv-status {
  color: var(--color-text-maxcontrast);
}

.pv-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-top: 60px;
  color: var(--color-text-maxcontrast);
}

/* Grid */
.pv-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 14px;
}

.pv-card {
  border-radius: var(--border-radius-large);
  overflow: hidden;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}

.pv-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.pv-card:hover .pv-card-actions {
  opacity: 1;
}

.pv-card-art {
  aspect-ratio: 1;
  display: flex;
  align-items: flex-end;
  padding: 10px;
  position: relative;
}

.pv-card-count {
  font-size: 0.7em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(255, 255, 255, 0.85);
  background: rgba(0, 0, 0, 0.35);
  padding: 2px 8px;
  border-radius: 4px;
}

.pv-card-info {
  padding: 10px 12px 8px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.pv-card-name {
  font-weight: 600;
  font-size: 0.875em;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.pv-card-desc {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pv-card-actions {
  display: flex;
  gap: 4px;
  padding: 0 8px 8px;
  opacity: 0;
  transition: opacity 0.1s;
}

/* Dialog form */
.pv-dialog-form {
  padding: 8px 0 4px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.pv-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pv-field label {
  font-size: 0.8em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-maxcontrast);
}

.pv-field input {
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  padding: 9px 12px;
  font-size: 1em;
  font-family: inherit;
}

.pv-field input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}
</style>
