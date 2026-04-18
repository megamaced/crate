<template>
  <div
    class="media-card"
    @click="$emit('detail', item)"
  >
    <div
      class="media-card-art"
      :style="artStyle"
    >
      <span class="media-card-format-label">{{ item.format }}</span>
    </div>
    <div class="media-card-info">
      <span class="media-card-title">{{ item.title }}</span>
      <span class="media-card-artist">{{ item.artist }}</span>
      <div class="media-card-footer">
        <span
          v-if="item.year"
          class="media-card-year"
        >{{ item.year }}</span>
        <span
          v-if="item.marketValue"
          class="media-card-market"
        >{{ formatMarketValue(item) }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { formatMarketValue } from '../utils/formatMarketValue.js'
import { artworkStyleFor } from '../composables/useArtworkStyle.js'

const props = defineProps({
  item: { type: Object, required: true },
})

defineEmits(['detail'])

const artStyle = computed(() => artworkStyleFor(props.item))
</script>

<style scoped>
.media-card {
  border-radius: var(--border-radius-large);
  overflow: hidden;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}

.media-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.media-card-art {
  aspect-ratio: 1;
  display: flex;
  align-items: flex-end;
  padding: 10px;
}

.media-card-format-label {
  font-size: 0.7em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(255, 255, 255, 0.75);
  background: rgba(0, 0, 0, 0.25);
  padding: 2px 6px;
  border-radius: 4px;
}

.media-card-info {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.media-card-title {
  font-weight: 600;
  font-size: 0.875em;
  line-height: 1.3;
  /* two-line clamp */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.media-card-artist {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.media-card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-top: 2px;
}

.media-card-year {
  font-size: 0.75em;
  color: var(--color-text-maxcontrast);
}

.media-card-market {
  font-size: 0.75em;
  font-weight: 700;
  color: #4ade80;
  white-space: nowrap;
}
</style>
