<template>
  <div class="shared-view">
    <div class="sv-toolbar">
      <h2 class="sv-heading">
        Shared with me
      </h2>
    </div>

    <p
      v-if="loading"
      class="sv-status"
    >
      Loading…
    </p>

    <div
      v-else-if="albums.length === 0 && playlists.length === 0"
      class="sv-empty"
    >
      <p>Nothing has been shared with you yet.</p>
    </div>

    <template v-else>
      <!-- Shared albums -->
      <section
        v-if="albums.length > 0"
        class="sv-section"
      >
        <h3 class="sv-section-title">
          Albums
        </h3>
        <div class="sv-list">
          <div
            v-for="item in albums"
            :key="item.id"
            class="sv-row"
            @click="$emit('detail', item)"
          >
            <div
              class="sv-thumb"
              :style="thumbStyle(item)"
            />
            <div class="sv-info">
              <span class="sv-title">{{ item.title }}</span>
              <span class="sv-artist">{{ item.artist }}</span>
              <span class="sv-meta">
                <span class="sv-badge">{{ item.format }}</span>
                <template v-if="item.year">&thinsp;{{ item.year }}</template>
                <span class="sv-shared-by">&ensp;·&ensp;Shared by {{ item.sharedByUser }}</span>
              </span>
            </div>
          </div>
        </div>
      </section>

      <!-- Shared playlists -->
      <section
        v-if="playlists.length > 0"
        class="sv-section"
      >
        <h3 class="sv-section-title">
          Playlists
        </h3>
        <div class="sv-playlist-grid">
          <div
            v-for="pl in playlists"
            :key="pl.id"
            class="sv-pl-card"
            @click="$emit('playlist', pl)"
          >
            <div
              class="sv-pl-art"
              :style="playlistCoverStyle(pl)"
            />
            <div class="sv-pl-info">
              <span class="sv-pl-name">{{ pl.name }}</span>
              <span class="sv-pl-meta">{{ playlistCountLabel(pl.itemCount, pl.categories) }} · by {{ pl.sharedByUser }}</span>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { playlistCountLabel } from '../utils/categoryFormats.js'
import { artworkStyleFor } from '../composables/useArtworkStyle.js'

defineEmits(['detail', 'playlist'])

const albums = ref([])
const playlists = ref([])
const loading = ref(false)

function thumbStyle(item) {
  return artworkStyleFor(item)
}

function playlistCoverStyle(pl) {
  if (pl.coverId) {
    const url = generateUrl('/apps/crate/artwork/' + pl.coverId)
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  const first = pl.items?.[0]
  if (first) {
    return artworkStyleFor(first)
  }
  return { background: 'linear-gradient(135deg, #374151, #6b7280)' }
}

async function load() {
  loading.value = true
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/share/with-me'))
    const data = res.data.ocs?.data ?? {}
    albums.value = data.albums ?? []
    playlists.value = data.playlists ?? []
  } catch (e) {
    console.error('Failed to load shared items', e)
    showError('Failed to load shared items')
  } finally {
    loading.value = false
  }
}

onMounted(load)
defineExpose({ load })
</script>

<style scoped>
.shared-view {
  padding: 0 20px 40px;
  max-width: 860px;
}

.sv-toolbar {
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 24px;
}

.sv-heading {
  margin: 0;
  font-size: 1.4em;
}

.sv-status {
  color: var(--color-text-maxcontrast);
}

.sv-empty {
  color: var(--color-text-maxcontrast);
  margin-top: 40px;
}

.sv-section {
  margin-bottom: 40px;
}

.sv-section-title {
  font-size: 0.85em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 14px;
}

/* Album list */
.sv-list {
  display: flex;
  flex-direction: column;
}

.sv-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 12px;
  border-radius: var(--border-radius-large);
  cursor: pointer;
  transition: background 0.1s;
}

.sv-row:hover {
  background: var(--color-background-hover);
}

.sv-thumb {
  width: 44px;
  height: 44px;
  border-radius: var(--border-radius);
  flex-shrink: 0;
}

.sv-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.sv-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sv-artist {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sv-meta {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}

.sv-badge {
  background: var(--color-background-dark);
  padding: 1px 6px;
  border-radius: 10px;
  font-size: 0.85em;
  font-weight: 600;
}

.sv-shared-by {
  font-style: italic;
}

/* Playlist grid */
.sv-playlist-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
}

.sv-pl-card {
  border-radius: var(--border-radius-large);
  overflow: hidden;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}

.sv-pl-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.sv-pl-art {
  aspect-ratio: 1;
}

.sv-pl-info {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.sv-pl-name {
  font-weight: 600;
  font-size: 0.875em;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.sv-pl-meta {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}
</style>
