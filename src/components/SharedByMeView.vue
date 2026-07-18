<template>
  <div class="sbm-view">
    <div class="sbm-toolbar">
      <h2 class="sbm-heading">
        Shared by me
      </h2>
    </div>

    <p
      v-if="loading"
      class="sbm-status"
    >
      Loading…
    </p>

    <div
      v-else-if="shares.length === 0"
      class="sbm-empty"
    >
      <p>You haven't shared anything yet.</p>
    </div>

    <div
      v-else
      class="sbm-list"
    >
      <div
        v-for="share in shares"
        :key="share.id"
        class="sbm-row"
      >
        <span class="sbm-type-badge">{{ typeBadge(share) }}</span>
        <div class="sbm-info">
          <span class="sbm-label">{{ share.label }}</span>
          <span class="sbm-meta">
            Shared with {{ share.sharedWithDisplayName }}
            <span
              class="sbm-access-badge"
              :class="share.canWrite ? 'sbm-access-badge--write' : ''"
            >{{ share.canWrite ? 'Can edit' : 'Read-only' }}</span>
          </span>
        </div>
        <NcButton
          variant="error"
          size="small"
          :disabled="removing.includes(share.id)"
          @click="stopSharing(share)"
        >
          Stop sharing
        </NcButton>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { CATEGORY_LABELS } from '../utils/categoryFormats.js'

const TYPE_LABELS = {
  album:    'Album',
  playlist: 'Playlist',
  library:  'Library',
  category: 'Category',
}

const shares = ref([])
const loading = ref(false)
const removing = ref([])

// A single-item share carries the legacy internal type "album" regardless of
// the item's real category, so badge it by the item's category instead (e.g.
// GAME / FILM). `itemCategory` is null for library/playlist shares — those keep
// their plain type label. The badge style already uppercases the text.
function typeBadge(share) {
  if (share.shareableType === 'album') {
    return CATEGORY_LABELS[share.itemCategory] ?? 'Item'
  }
  return TYPE_LABELS[share.shareableType] ?? share.shareableType
}

async function load() {
  loading.value = true
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/share/by-me'))
    shares.value = res.data.ocs?.data ?? []
  } catch (e) {
    console.error('Failed to load shares', e)
    showError('Failed to load shares')
  } finally {
    loading.value = false
  }
}

async function stopSharing(share) {
  removing.value = [...removing.value, share.id]
  try {
    await axios.delete(generateOcsUrl(`/apps/crate/api/v1/share/${share.id}`))
    await load()
  } catch (e) {
    console.error('Failed to stop sharing', e)
    showError('Failed to stop sharing')
  } finally {
    removing.value = removing.value.filter(id => id !== share.id)
  }
}

onMounted(load)
defineExpose({ load })
</script>

<style scoped>
.sbm-view {
  padding: 0 20px 40px;
  max-width: 860px;
}

.sbm-toolbar {
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 24px;
}

.sbm-heading {
  margin: 0;
  font-size: 1.4em;
}

.sbm-status {
  color: var(--color-text-maxcontrast);
}

.sbm-empty {
  color: var(--color-text-maxcontrast);
  margin-top: 40px;
}

.sbm-list {
  display: flex;
  flex-direction: column;
}

.sbm-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 12px;
  border-radius: var(--border-radius-large);
  transition: background 0.1s;
}

.sbm-row:hover {
  background: var(--color-background-hover);
}

.sbm-type-badge {
  flex-shrink: 0;
  font-size: 0.72em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 2px 9px;
  border-radius: 10px;
  background: var(--color-background-dark);
  color: var(--color-text-maxcontrast);
}

.sbm-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.sbm-label {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sbm-meta {
  font-size: 0.82em;
  color: var(--color-text-maxcontrast);
  display: flex;
  align-items: center;
  gap: 8px;
}

.sbm-access-badge {
  font-size: 0.85em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 1px 7px;
  border-radius: 10px;
  background: var(--color-background-dark);
  color: var(--color-text-maxcontrast);
}

.sbm-access-badge--write {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}
</style>
