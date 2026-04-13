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
      <span
        v-if="item.year"
        class="media-card-year"
      >{{ item.year }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { generateUrl } from '@nextcloud/router'

const props = defineProps({
  item: { type: Object, required: true },
})

defineEmits(['detail'])

const FORMAT_COLOURS = {
  Vinyl: ['#6b21a8', '#a855f7'],
  CD: ['#1d4ed8', '#60a5fa'],
  SACD: ['#0f766e', '#2dd4bf'],
  Cassette: ['#b45309', '#fbbf24'],
  MiniDisc: ['#0e7490', '#38bdf8'],
}

const artStyle = computed(() => {
  if (props.item.artworkPath) {
    const url = generateUrl('/apps/crate/artwork/' + props.item.id)
    return {
      backgroundImage: `url(${url})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }
  const colours = FORMAT_COLOURS[props.item.format] ?? ['#374151', '#6b7280']
  return { background: `linear-gradient(135deg, ${colours[0]}, ${colours[1]})` }
})
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

.media-card-year {
  font-size: 0.75em;
  color: var(--color-text-maxcontrast);
  margin-top: 2px;
}
</style>
