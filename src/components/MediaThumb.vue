<template>
  <div
    class="media-thumb"
    :style="fallbackStyle"
  >
    <!--
      Native lazy loading defers the request until the browser thinks the
      image is near the viewport. Collection views with 100+ items would
      otherwise fan out all artwork requests at once and starve Apache /
      php-fpm workers, tripping the kube liveness probe.
    -->
    <img
      v-if="item?.artworkPath"
      :src="artUrl"
      loading="lazy"
      decoding="async"
      alt=""
      class="media-thumb-img"
    >
    <slot />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { formatColours } from '../utils/formatColours.js'
import { artworkUrl } from '../utils/artworkUrl.js'

const props = defineProps({
  item: { type: Object, default: null },
})

const artUrl = computed(() => (props.item ? artworkUrl(props.item) : ''))
const fallbackStyle = computed(() => {
  const colours = formatColours(props.item?.format)
  return { background: `linear-gradient(135deg, ${colours[0]}, ${colours[1]})` }
})
</script>

<style scoped>
.media-thumb {
  position: relative;
  overflow: hidden;
}

.media-thumb-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
</style>
