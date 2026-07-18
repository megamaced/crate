<template>
  <div class="playlist-detail">
    <!-- Top bar -->
    <div class="pd-topbar">
      <NcButton
        variant="tertiary"
        class="pd-back"
        @click="$emit('back')"
      >
        ← Back
      </NcButton>
      <div class="pd-topbar-actions">
        <span
          v-if="isShared"
          class="pd-shared-badge"
        >Shared by {{ playlist.sharedByUser }} · {{ canWrite ? 'can edit' : 'read-only' }}</span>
        <NcButton
          v-if="canWrite"
          variant="tertiary"
          @click="startEdit"
        >
          Edit
        </NcButton>
        <NcButton
          v-if="!isShared"
          variant="tertiary"
          @click="$emit('share', playlist)"
        >
          Share
        </NcButton>
        <NcButton
          v-if="!isShared"
          variant="error"
          @click="confirmDelete = true"
        >
          Delete playlist
        </NcButton>
      </div>
    </div>

    <!-- Header -->
    <div class="pd-header">
      <div
        class="pd-cover"
        :style="coverArtIds.length <= 1 ? coverStyle : {}"
      >
        <div
          v-if="coverArtIds.length > 1"
          class="pd-art-grid"
        >
          <div
            v-for="cid in coverArtIds"
            :key="cid"
            class="pd-art-cell"
            :style="artCellStyle(cid)"
          />
        </div>
      </div>
      <div class="pd-header-info">
        <h2 class="pd-title">
          {{ playlist.name }}
        </h2>
        <p
          v-if="playlist.description"
          class="pd-desc"
        >
          {{ playlist.description }}
        </p>
        <p class="pd-count">
          {{ playlistItemCountLabel }}
        </p>
      </div>
    </div>

    <!-- Items -->
    <div
      v-if="!playlist.items || playlist.items.length === 0"
      class="pd-empty"
    >
      <p>No items in this playlist yet. Open any item and use "Add to playlist" to add it here.</p>
    </div>

    <div
      v-else
      class="pd-list"
    >
      <div
        v-for="item in playlist.items"
        :key="item.id"
        class="pd-row"
        @click="openItem(item)"
      >
        <MediaThumb
          :item="item"
          class="pd-thumb"
        />
        <div class="pd-info">
          <span class="pd-item-title">{{ item.title }}</span>
          <span class="pd-item-artist">{{ item.artist }}</span>
          <span class="pd-item-meta">
            <span
              v-if="item.category"
              class="pd-badge pd-badge--cat"
            >{{ CATEGORY_LABELS[item.category] ?? item.category }}</span>
            <span class="pd-badge">{{ item.format }}</span>
            <template v-if="item.year">&thinsp;{{ item.year }}</template>
            <span
              v-if="item.status"
              class="pd-badge"
              :class="item.status === 'wanted' ? 'pd-badge--wanted' : 'pd-badge--owned'"
            >{{ item.status === 'wanted' ? 'Wanted' : 'Owned' }}</span>
          </span>
        </div>
        <div
          v-if="canWrite"
          class="pd-actions"
          @click.stop
        >
          <NcButton
            variant="tertiary"
            :aria-label="'Remove ' + item.title + ' from playlist'"
            @click="removeItem(item)"
          >
            Remove
          </NcButton>
        </div>
      </div>
    </div>

    <!-- Edit dialog -->
    <NcDialog
      v-if="editOpen"
      name="Edit playlist"
      :open="editOpen"
      @closing="editOpen = false"
    >
      <div class="pd-dialog-form">
        <div class="pd-dialog-field">
          <label for="pd-edit-name">Name</label>
          <input
            id="pd-edit-name"
            v-model="editName"
            type="text"
            maxlength="500"
            autocomplete="off"
          >
        </div>
        <div class="pd-dialog-field">
          <label for="pd-edit-desc">Description (optional)</label>
          <input
            id="pd-edit-desc"
            v-model="editDesc"
            type="text"
            maxlength="500"
            autocomplete="off"
          >
        </div>
      </div>
      <template #actions>
        <NcButton
          variant="tertiary"
          @click="editOpen = false"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="primary"
          :disabled="!editName.trim() || saving"
          @click="doEdit"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </NcButton>
      </template>
    </NcDialog>

    <!-- Delete confirm -->
    <NcDialog
      v-if="confirmDelete"
      name="Delete playlist"
      :open="confirmDelete"
      @closing="confirmDelete = false"
    >
      <p>Delete <strong>{{ playlist.name }}</strong>? The albums in it won't be deleted.</p>
      <template #actions>
        <NcButton
          variant="tertiary"
          @click="confirmDelete = false"
        >
          Cancel
        </NcButton>
        <NcButton
          variant="error"
          @click="$emit('delete', playlist)"
        >
          Delete
        </NcButton>
      </template>
    </NcDialog>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import MediaThumb from './MediaThumb.vue'
import { CATEGORY_LABELS, playlistCountLabel } from '../utils/categoryFormats.js'

const props = defineProps({
  playlist: { type: Object, required: true },
})

const emit = defineEmits(['back', 'detail', 'delete', 'share', 'updated'])

// A playlist opened from Shared-with-me carries `sharedByUser` and `canWrite`.
// canWrite covers own playlists (no sharedByUser) plus read/write sharees —
// they may rename and add/remove tracks. Delete and re-share stay owner-only.
const isShared = computed(() => !!props.playlist.sharedByUser)
const canWrite = computed(() => !props.playlist.sharedByUser || props.playlist.canWrite === true)

// Items reached through a shared playlist are read-only at the item level: a
// playlist share (even read/write) never grants edit rights on the underlying
// media items. Tag them with the owner + canWrite:false so ItemDetailView
// hides the owner-only Edit/Delete/Remove-data actions. Items in the user's
// own playlists pass through untouched.
function openItem(item) {
  if (props.playlist.sharedByUser) {
    emit('detail', { ...item, sharedByUser: props.playlist.sharedByUser, canWrite: false })
  } else {
    emit('detail', item)
  }
}

const confirmDelete = ref(false)
const editOpen = ref(false)
const editName = ref('')
const editDesc = ref('')
const saving = ref(false)

const playlistItemCountLabel = computed(() => {
  const items = props.playlist.items ?? []
  const cats = [...new Set(items.map(i => i.category ?? 'music'))]
  return playlistCountLabel(items.length, cats)
})

function startEdit() {
  editName.value = props.playlist.name
  editDesc.value = props.playlist.description ?? ''
  editOpen.value = true
}

async function doEdit() {
  if (!editName.value.trim()) return
  saving.value = true
  try {
    const res = await axios.put(
      generateOcsUrl(`/apps/crate/api/v1/playlists/${props.playlist.id}`),
      { name: editName.value.trim(), description: editDesc.value.trim() || null },
    )
    const updated = res.data.ocs?.data
    if (updated) emit('updated', updated)
    editOpen.value = false
  } catch (e) {
    console.error('Failed to update playlist', e)
    showError('Failed to update playlist')
  } finally {
    saving.value = false
  }
}

const coverStyle = computed(() => {
  const first = props.playlist.items?.[0]
  if (first?.artworkPath) {
    const url = generateUrl('/apps/crate/artwork/' + first.id)
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  return { background: 'linear-gradient(135deg, #374151, #6b7280)' }
})

const coverArtIds = computed(() => {
  const items = props.playlist.items ?? []
  const ids = []
  const seen = new Set()
  for (const item of items) {
    if (!item.artworkPath || seen.has(item.id)) continue
    seen.add(item.id)
    ids.push(item.id)
    if (ids.length >= 4) break
  }
  return ids
})

function artCellStyle(mediaItemId) {
  const url = generateUrl('/apps/crate/artwork/' + mediaItemId)
  return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
}


async function removeItem(item) {
  try {
    const res = await axios.delete(
      generateOcsUrl(`/apps/crate/api/v1/playlists/${props.playlist.id}/items/${item.id}`),
    )
    const updated = res.data.ocs?.data
    if (updated) emit('updated', updated)
  } catch (e) {
    console.error('Failed to remove item from playlist', e)
    showError('Failed to remove item from playlist')
  }
}
</script>

<style scoped>
.playlist-detail {
  padding: 0 36px 40px 20px;
}

/* Top bar */
.pd-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 16px;
  position: sticky;
  top: 0;
  background: var(--color-main-background);
  z-index: 10;
}

.pd-topbar-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.pd-shared-badge {
  font-size: 0.85em;
  font-style: italic;
  color: var(--color-text-maxcontrast);
  padding: 6px 12px;
  background: var(--color-background-dark);
  border-radius: var(--border-radius);
}

/* Header */
.pd-header {
  display: grid;
  grid-template-columns: 180px 1fr;
  gap: 24px;
  margin-bottom: 32px;
  position: sticky;
  top: calc(var(--default-clickable-area, 44px) + 40px);
  background: var(--color-main-background);
  z-index: 9;
  padding-bottom: 16px;
}

@media (max-width: 640px) {
  .pd-header { grid-template-columns: 1fr; }
}

.pd-cover {
  width: 100%;
  aspect-ratio: 1;
  border-radius: var(--border-radius-large);
  background: var(--color-background-dark);
  overflow: hidden;
  position: relative;
}

.pd-art-grid {
  position: absolute;
  inset: 0;
  display: grid;
  grid-template-columns: 1fr 1fr;
  grid-template-rows: 1fr 1fr;
  gap: 2px;
}

.pd-art-cell {
  background: var(--color-background-dark);
}

.pd-header-info {
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 8px;
}

.pd-title {
  margin: 0;
  font-size: 1.8em;
  line-height: 1.2;
}

.pd-desc {
  margin: 0;
  font-size: 0.9em;
  color: var(--color-text-maxcontrast);
}

.pd-count {
  margin: 0;
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

/* Empty */
.pd-empty {
  color: var(--color-text-maxcontrast);
  margin-top: 32px;
}

/* List */
.pd-list {
  display: flex;
  flex-direction: column;
}

.pd-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 12px;
  border-radius: var(--border-radius-large);
  cursor: pointer;
  transition: background 0.1s;
}

.pd-row:hover {
  background: var(--color-background-hover);
}

.pd-row:hover .pd-actions {
  opacity: 1;
}

.pd-thumb {
  width: 44px;
  height: 44px;
  border-radius: var(--border-radius);
  flex-shrink: 0;
}

.pd-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.pd-item-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pd-item-artist {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pd-item-meta {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}

.pd-badge {
  background: var(--color-background-dark);
  padding: 1px 6px;
  border-radius: 10px;
  font-size: 0.85em;
  font-weight: 600;
}

.pd-badge--cat {
  background: var(--color-primary-element-light, rgba(var(--color-primary-element-rgb), 0.15));
  color: var(--color-primary-element);
}

.pd-badge--wanted {
  background: var(--color-warning);
  color: #fff;
}

.pd-badge--owned {
  background: var(--color-background-dark);
  color: var(--color-main-text);
}

.pd-actions {
  opacity: 0;
  transition: opacity 0.1s;
  flex-shrink: 0;
}

/* Edit dialog form */
.pd-dialog-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 4px 0 8px;
}

.pd-dialog-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.pd-dialog-field label {
  font-size: 0.8em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-maxcontrast);
}

.pd-dialog-field input {
  width: 100%;
  box-sizing: border-box;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  padding: 9px 12px;
  font-size: 1em;
  font-family: inherit;
}

.pd-dialog-field input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}
</style>
