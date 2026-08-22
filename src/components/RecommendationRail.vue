<template>
  <!--
    Renders nothing at all when there's nothing to show. A recommendation rail
    is a nicety, so an empty or failed one should be invisible rather than an
    empty box or an error — the detail view has to look complete without it.
  -->
  <section
    v-if="items.length > 0"
    class="rec-rail"
  >
    <header class="rec-rail-header">
      <h3 class="rec-rail-title">
        {{ title }}
      </h3>
      <span
        v-if="source"
        class="rec-rail-source"
      >via {{ source }}</span>
    </header>

    <ul class="rec-rail-track">
      <li
        v-for="(entry, index) in items"
        :key="entry.key"
        class="rec-rail-item"
      >
        <button
          type="button"
          class="rec-rail-card"
          :title="entry.tooltip"
          @click="$emit('pick', { index, entry })"
        >
          <MediaThumb
            v-if="entry.item"
            :item="entry.item"
            class="rec-rail-art"
          />
          <!--
            Online suggestions come straight from the provider's CDN. Those
            hosts are already allow-listed for search-result thumbnails
            (ContentSecurityPolicyListener), which is why reusing the search
            result shape here needs no CSP change.
          -->
          <div
            v-else
            class="rec-rail-art rec-rail-art-remote"
          >
            <img
              v-if="entry.thumb"
              :src="entry.thumb"
              loading="lazy"
              decoding="async"
              alt=""
              class="rec-rail-art-img"
            >
          </div>

          <span class="rec-rail-name">{{ entry.title }}</span>
          <span
            v-if="entry.subtitle"
            class="rec-rail-sub"
          >{{ entry.subtitle }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>

<script setup>
import MediaThumb from './MediaThumb.vue'

defineProps({
  title:  { type: String, required: true },
  // Provider name for the attribution line; omitted for the local rail, whose
  // suggestions come from the user's own collection.
  source: { type: String, default: '' },
  /**
   * Normalised entries, so this component stays agnostic about whether a row
   * came from the collection or from a provider:
   *   { key, title, subtitle, tooltip, item? , thumb? }
   * `item` set → a collection item (artwork via the app's artwork proxy).
   * `thumb` set → a remote thumbnail URL.
   */
  items:  { type: Array, default: () => [] },
})

defineEmits(['pick'])
</script>

<style scoped>
.rec-rail {
  margin-top: 24px;
}

.rec-rail-header {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 8px;
}

.rec-rail-title {
  font-size: 15px;
  font-weight: 600;
  margin: 0;
}

.rec-rail-source {
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}

/*
  Horizontal scroll rather than a wrapping grid: the rail is a secondary
  element and shouldn't push the rest of the detail view down as it fills.
*/
.rec-rail-track {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding: 0 0 4px;
  margin: 0;
  list-style: none;
  scroll-snap-type: x proximity;
}

.rec-rail-item {
  flex: 0 0 auto;
  scroll-snap-align: start;
}

.rec-rail-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  width: 108px;
  padding: 0;
  background: none;
  border: none;
  text-align: left;
  cursor: pointer;
  color: inherit;
}

.rec-rail-card:hover .rec-rail-name,
.rec-rail-card:focus-visible .rec-rail-name {
  text-decoration: underline;
}

.rec-rail-art,
.rec-rail-art-remote {
  width: 108px;
  height: 108px;
  border-radius: var(--border-radius-large, 8px);
  overflow: hidden;
  background: var(--color-background-dark);
}

.rec-rail-art-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* Two lines max, so a long title can't make one card taller than its row. */
.rec-rail-name,
.rec-rail-sub {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  font-size: 12px;
  line-height: 1.3;
}

.rec-rail-name {
  font-weight: 500;
}

.rec-rail-sub {
  -webkit-line-clamp: 1;
  line-clamp: 1;
  color: var(--color-text-maxcontrast);
}
</style>
