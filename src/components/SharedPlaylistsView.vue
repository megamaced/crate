<template>
  <div class="spv-view">
    <div class="spv-toolbar">
      <h2 class="spv-heading">
        Shared playlists
      </h2>
    </div>

    <p
      v-if="loading"
      class="spv-status"
    >
      Loading…
    </p>

    <div
      v-else-if="sharedPlaylists.length === 0"
      class="spv-empty"
    >
      <p>No playlists have been shared with you yet.</p>
    </div>

    <div
      v-else
      class="spv-grid"
    >
      <div
        v-for="pl in sharedPlaylists"
        :key="pl.id"
        class="spv-card"
        @click="$emit('playlist', pl)"
      >
        <div
          class="spv-card-art"
          :style="coverStyle(pl)"
        />
        <div class="spv-card-info">
          <span class="spv-card-name">{{ pl.name }}</span>
          <span class="spv-card-meta">{{ playlistCountLabel(pl.itemCount, pl.categories) }} · by {{ pl.sharedByUser }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { playlistCountLabel } from '../utils/categoryFormats.js'
import { artworkStyleFor } from '../composables/useArtworkStyle.js'
import { useSharedContent } from '../composables/useSharedContent.js'

defineEmits(['playlist'])

const { loading, sharedPlaylists, load } = useSharedContent()

function coverStyle(pl) {
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

onMounted(load)
defineExpose({ load, reload: load })
</script>

<style scoped>
.spv-view {
  padding: 0 36px 40px 20px;
}

.spv-toolbar {
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 24px;
}

.spv-heading {
  margin: 0;
  font-size: 1.4em;
}

.spv-status {
  color: var(--color-text-maxcontrast);
}

.spv-empty {
  color: var(--color-text-maxcontrast);
  margin-top: 40px;
}

.spv-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 14px;
}

.spv-card {
  border-radius: var(--border-radius-large);
  overflow: hidden;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}

.spv-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.spv-card-art {
  aspect-ratio: 1;
}

.spv-card-info {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.spv-card-name {
  font-weight: 600;
  font-size: 0.875em;
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.spv-card-meta {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}
</style>
