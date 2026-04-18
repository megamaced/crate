<template>
  <NcModal
    :show="show"
    label-id="atp-modal-title"
    size="small"
    @close="$emit('close')"
  >
    <div class="atp-modal">
      <h2 id="atp-modal-title">
        Add to playlist
      </h2>
      <p class="atp-subtitle">
        <strong>{{ item?.artist }}</strong> — {{ item?.title }}
      </p>

      <p
        v-if="loading"
        class="atp-status"
      >
        Loading playlists…
      </p>

      <div
        v-else
        class="atp-list"
      >
        <button
          v-for="pl in playlists"
          :key="pl.id"
          class="atp-row"
          :class="{ 'atp-row--added': addedIds.has(pl.id) }"
          :disabled="addedIds.has(pl.id)"
          @click="addTo(pl)"
        >
          <div
            class="atp-row-art"
            :style="coverStyle(pl)"
          />
          <div class="atp-row-info">
            <span class="atp-row-name">{{ pl.name }}</span>
            <span class="atp-row-count">{{ pl.itemCount }} albums</span>
          </div>
          <span
            v-if="addedIds.has(pl.id)"
            class="atp-check"
          >✓</span>
        </button>

        <div
          v-if="playlists.length === 0"
          class="atp-empty"
        >
          No playlists yet.
        </div>
      </div>

      <!-- Create new inline -->
      <div class="atp-create">
        <input
          v-model="newName"
          type="text"
          placeholder="New playlist name…"
          class="atp-input"
          maxlength="500"
          @keydown.enter="createAndAdd"
        >
        <NcButton
          variant="secondary"
          :disabled="!newName.trim() || creating"
          @click="createAndAdd"
        >
          {{ creating ? 'Creating…' : 'Create & add' }}
        </NcButton>
      </div>
    </div>
  </NcModal>
</template>

<script setup>
import { ref, watch } from 'vue'
import { NcModal, NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

const props = defineProps({
  show: { type: Boolean, required: true },
  item: { type: Object, default: null },
})

defineEmits(['close'])

const playlists = ref([])
const loading = ref(false)
const addedIds = ref(new Set())
const newName = ref('')
const creating = ref(false)

function coverStyle(pl) {
  if (pl.coverId) {
    const url = generateUrl('/apps/crate/artwork/' + pl.coverId)
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  return { background: 'linear-gradient(135deg, #374151, #6b7280)' }
}

watch(() => props.show, async (open) => {
  if (!open) {
    newName.value = ''
    addedIds.value = new Set()
    return
  }
  loading.value = true
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/playlists'))
    playlists.value = res.data.ocs?.data ?? []
  } catch (e) {
    console.error('Failed to load playlists', e)
    showError('Failed to load playlists')
  } finally {
    loading.value = false
  }
})

async function addTo(pl) {
  if (!props.item || addedIds.value.has(pl.id)) return
  try {
    await axios.post(generateOcsUrl(`/apps/crate/api/v1/playlists/${pl.id}/items`), {
      mediaItemId: props.item.id,
    })
    addedIds.value = new Set([...addedIds.value, pl.id])
    // Update local count
    const idx = playlists.value.findIndex(p => p.id === pl.id)
    if (idx !== -1) playlists.value[idx] = { ...playlists.value[idx], itemCount: (playlists.value[idx].itemCount ?? 0) + 1 }
  } catch (e) {
    console.error('Failed to add to playlist', e)
    showError('Failed to add to playlist')
  }
}

async function createAndAdd() {
  if (!newName.value.trim() || !props.item) return
  creating.value = true
  try {
    // Create playlist
    const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/playlists'), {
      name: newName.value.trim(),
    })
    const created = res.data.ocs?.data
    if (created) {
      // Add item
      await axios.post(generateOcsUrl(`/apps/crate/api/v1/playlists/${created.id}/items`), {
        mediaItemId: props.item.id,
      })
      playlists.value.push({ ...created, itemCount: 1, coverId: props.item.artworkPath ? props.item.id : null })
      addedIds.value = new Set([...addedIds.value, created.id])
      newName.value = ''
    }
  } catch (e) {
    console.error('Failed to create playlist and add item', e)
    showError('Failed to create playlist')
  } finally {
    creating.value = false
  }
}
</script>

<style scoped>
.atp-modal {
  padding: 24px 28px 28px;
  min-width: min(380px, 90vw);
}

.atp-modal h2 {
  margin: 0 0 4px;
  font-size: 1.15em;
  font-weight: 700;
}

.atp-subtitle {
  margin: 0 0 20px;
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

.atp-status {
  color: var(--color-text-maxcontrast);
  font-size: 0.875em;
  margin: 0 0 16px;
}

.atp-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 280px;
  overflow-y: auto;
  margin-bottom: 16px;
}

.atp-empty {
  color: var(--color-text-maxcontrast);
  font-size: 0.875em;
  padding: 8px 0;
}

.atp-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  border-radius: var(--border-radius);
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  width: 100%;
  transition: background 0.1s;
  color: var(--color-main-text);
}

.atp-row:hover:not(:disabled) {
  background: var(--color-background-hover);
}

.atp-row--added {
  opacity: 0.6;
  cursor: default;
}

.atp-row-art {
  width: 40px;
  height: 40px;
  border-radius: var(--border-radius);
  flex-shrink: 0;
}

.atp-row-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.atp-row-name {
  font-weight: 500;
  font-size: 0.875em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.atp-row-count {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}

.atp-check {
  color: #4ade80;
  font-weight: 700;
  flex-shrink: 0;
}

.atp-create {
  display: flex;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border);
}

.atp-input {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-background-dark);
  color: var(--color-main-text);
  padding: 7px 10px;
  font-size: 0.9em;
  font-family: inherit;
}

.atp-input:focus {
  border-color: var(--color-primary-element);
  outline: none;
  background: var(--color-main-background);
}
</style>
