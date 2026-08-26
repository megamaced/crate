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

    <ul
      class="rec-rail-track"
      :style="{ '--rec-rail-tile': `${tileMin}px`, '--rec-rail-gap': `${RAIL_GAP}px` }"
    >
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
            (CrateImageHosts, applied to the page in PageController::index),
            which is why reusing the search result shape here needs no CSP
            change.
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
import { RAIL_GAP, RAIL_TILE_TARGET } from '../utils/railLayout.js'

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
  /**
   * Grid tile floor in pixels, measured by the parent from the width the rail
   * actually has (see utils/railLayout.js). The default is what a rail wide
   * enough for the full-size tile gets.
   */
  tileMin: { type: Number, default: RAIL_TILE_TARGET },
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
  A fluid grid rather than a fixed row: the rail fills whatever width the detail
  view gives it, and the parent asks the server for exactly the number of
  suggestions that lays out in complete rows at that width. Both the tile floor
  and the gap come from the parent's measurement so the count and the grid can't
  disagree.
*/
.rec-rail-track {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(var(--rec-rail-tile), 1fr));
  gap: var(--rec-rail-gap);
  padding: 0;
  margin: 0;
  list-style: none;
}

/* A long title clamps inside its tile instead of widening the track. */
.rec-rail-item {
  min-width: 0;
}

.rec-rail-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
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
  width: 100%;
  aspect-ratio: 1;
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
  line-height: 1.3;
}

.rec-rail-name {
  font-size: 13px;
  font-weight: 500;
}

.rec-rail-sub {
  -webkit-line-clamp: 1;
  line-clamp: 1;
  font-size: 12px;
  color: var(--color-text-maxcontrast);
}
</style>
