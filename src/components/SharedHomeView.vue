<template>
  <div
    ref="homeEl"
    class="crate-home"
  >
    <div class="crate-shared-toolbar">
      <h2 class="crate-shared-heading">
        Shared with me
      </h2>
    </div>

    <p
      v-if="loading"
      class="crate-status"
    >
      Loading…
    </p>

    <div
      v-else-if="isEmpty"
      class="crate-home-empty"
    >
      <p>Nothing has been shared with you yet.</p>
    </div>

    <template v-else>
      <!-- Hero cards — one per shared category, compact grid when multiple -->
      <div
        v-if="heroItems.length > 0"
        :class="['crate-heroes', heroItems.length > 1 ? 'crate-heroes--multi' : '']"
      >
        <section
          v-for="hero in heroItems"
          :key="'hero-' + hero.category"
          class="crate-hero"
        >
          <div class="crate-hero__inner">
            <div class="crate-hero__art">
              <img
                v-if="hero.artworkPath"
                :src="generateUrl('/apps/crate/artwork/' + hero.id) + (hero.updatedAt ? '?v=' + encodeURIComponent(hero.updatedAt) : '')"
                :alt="hero.title"
              >
              <div
                v-else
                class="crate-hero__art-placeholder"
              >
                {{ HERO_PLACEHOLDER[hero.category ?? 'music'] }}
              </div>
            </div>
            <div class="crate-hero__info">
              <p class="crate-hero__eyebrow">
                Shared by {{ hero.sharedByUser }}
              </p>
              <h2 class="crate-hero__title">
                {{ hero.title }}
              </h2>
              <p class="crate-hero__artist">
                {{ hero.artist }}
              </p>
              <p class="crate-hero__meta">
                <span
                  v-if="hero.format"
                  class="crate-tag"
                >{{ hero.format }}</span>
                <span
                  v-if="hero.year"
                  class="crate-tag"
                >{{ hero.year }}</span>
              </p>
              <NcButton
                variant="secondary"
                class="crate-hero__btn"
                @click="$emit('detail', hero)"
              >
                View details
              </NcButton>
            </div>
          </div>
        </section>
      </div>

      <!-- Recently shared -->
      <section
        v-if="recentShared.length > 0"
        class="crate-home-section"
      >
        <h3>Recently Shared</h3>
        <div class="crate-card-grid">
          <MediaCard
            v-for="item in recentItems"
            :key="'r' + item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>
      </section>

      <!-- One section per shared category -->
      <section
        v-for="cat in sharedCategories"
        :key="cat.category"
        class="crate-home-section"
      >
        <div
          class="crate-category-heading crate-category-heading--link"
          role="button"
          tabindex="0"
          @click="$emit('open-category', cat.category)"
          @keydown.enter="$emit('open-category', cat.category)"
        >
          <span class="crate-category-heading__label">{{ cat.label }}</span>
          <span class="crate-category-heading__count">{{ cat.count }}</span>
          <span class="crate-category-heading__chevron">›</span>
        </div>
        <div class="crate-card-grid">
          <MediaCard
            v-for="item in cat.items.slice(0, rowCount)"
            :key="item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>
      </section>

      <!-- Shared playlists -->
      <section
        v-if="sharedPlaylists.length > 0"
        class="crate-home-section"
      >
        <h3>Shared Playlists</h3>
        <div class="crate-pl-grid">
          <div
            v-for="pl in sharedPlaylists"
            :key="pl.id"
            class="crate-pl-card"
            @click="$emit('playlist', pl)"
          >
            <div
              class="crate-pl-art"
              :style="playlistCoverStyle(pl)"
            />
            <div class="crate-pl-info">
              <span class="crate-pl-name">{{ pl.name }}</span>
              <span class="crate-pl-meta">{{ playlistCountLabel(pl.itemCount, pl.categories) }} · by {{ pl.sharedByUser }}</span>
            </div>
          </div>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import MediaCard from './MediaCard.vue'
import { playlistCountLabel } from '../utils/categoryFormats.js'
import { artworkStyleFor } from '../composables/useArtworkStyle.js'
import { useSharedContent } from '../composables/useSharedContent.js'

defineEmits(['detail', 'playlist', 'open-category'])

const HERO_PLACEHOLDER = {
  music: '♫',
  film:  '▶',
  book:  '☰',
  game:  '⊞',
  comic: '◉',
}

const { loading, sharedCategories, sharedPlaylists, recentShared, load } = useSharedContent()

const homeEl = ref(null)
const rowCount = ref(6)

const isEmpty = computed(() =>
  sharedCategories.value.length === 0 && sharedPlaylists.value.length === 0,
)

// One hero item per shared category — the first (most recently touched via the
// recent-first ordering isn't guaranteed here, so just take the first item).
const heroItems = computed(() => sharedCategories.value.map(cat => cat.items[0]))

const recentItems = computed(() => recentShared.value.slice(0, rowCount.value))

function updateRowCount() {
  const el = homeEl.value?.$el ?? homeEl.value
  if (!el) return
  const width = el.clientWidth
  const cols = Math.floor((width + 12) / (180 + 12))
  rowCount.value = Math.max(cols, 2)
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

let _resizeObserver = null

onMounted(() => {
  load()
  const el = homeEl.value?.$el ?? homeEl.value
  if (el) {
    updateRowCount()
    _resizeObserver = new ResizeObserver(updateRowCount)
    _resizeObserver.observe(el)
  }
})
onBeforeUnmount(() => {
  _resizeObserver?.disconnect()
})
defineExpose({ load, reload: load })
</script>

<style scoped>
.crate-home {
  padding: 0 36px 40px 20px;
  padding-top: calc(var(--default-clickable-area, 44px) + 8px);
}

.crate-shared-toolbar {
  margin-bottom: 24px;
}

.crate-shared-heading {
  margin: 0;
  font-size: 1.4em;
}

.crate-status {
  color: var(--color-text-maxcontrast);
}

.crate-home-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  margin-top: 60px;
  color: var(--color-text-maxcontrast);
}

/* --- Heroes wrapper --- */
.crate-heroes {
  margin-bottom: 24px;
}

.crate-heroes--multi {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 16px;
}

.crate-heroes--multi .crate-hero {
  margin-bottom: 0;
}

.crate-heroes--multi .crate-hero__art {
  flex: 0 0 160px;
}

.crate-heroes--multi .crate-hero__info {
  padding: 20px 24px;
}

.crate-heroes--multi .crate-hero__title {
  font-size: 1.3em;
}

/* --- Hero --- */
.crate-hero {
  border-radius: var(--border-radius-large);
  background: var(--color-background-dark);
  overflow: hidden;
}

.crate-hero__inner {
  display: flex;
  gap: 0;
}

.crate-hero__art {
  flex: 0 0 220px;
  aspect-ratio: 1;
  overflow: hidden;
  background: var(--color-background-darker, #111);
}

.crate-hero__art img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.crate-hero__art-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 4em;
  color: var(--color-text-maxcontrast);
  opacity: 0.3;
}

.crate-hero__info {
  flex: 1;
  padding: 28px 32px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 8px;
  min-width: 0;
}

.crate-hero__eyebrow {
  font-size: 0.75em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-primary-element);
  margin: 0;
}

.crate-hero__title {
  font-size: 1.7em;
  font-weight: 800;
  margin: 0;
  line-height: 1.15;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.crate-hero__artist {
  font-size: 1.1em;
  color: var(--color-text-maxcontrast);
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.crate-hero__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 4px 0 0;
}

.crate-tag {
  background: var(--color-background-darker, rgba(0,0,0,.18));
  border-radius: 4px;
  padding: 2px 8px;
  font-size: 0.78em;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.crate-hero__btn {
  align-self: flex-start;
  margin-top: 8px;
}

/* --- Category heading --- */
.crate-category-heading {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin: 0 0 16px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--color-border);
}

.crate-category-heading--link {
  cursor: pointer;
}

.crate-category-heading--link:hover .crate-category-heading__label {
  color: var(--color-primary-element);
}

.crate-category-heading__label {
  font-size: 1.1em;
  font-weight: 700;
  color: var(--color-main-text);
}

.crate-category-heading__count {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}

.crate-category-heading__chevron {
  margin-left: auto;
  font-size: 1.2em;
  color: var(--color-text-maxcontrast);
}

/* --- Section rows --- */
.crate-home-section {
  margin-bottom: 36px;
}

.crate-home-section h3 {
  margin: 0 0 14px;
  font-size: 1em;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-maxcontrast);
}

.crate-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
}

/* --- Playlist grid --- */
.crate-pl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
}

.crate-pl-card {
  border-radius: var(--border-radius-large);
  overflow: hidden;
  background: var(--color-background-dark);
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}

.crate-pl-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
}

.crate-pl-art {
  aspect-ratio: 1;
}

.crate-pl-info {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.crate-pl-name {
  font-weight: 600;
  font-size: 0.875em;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.crate-pl-meta {
  font-size: 0.78em;
  color: var(--color-text-maxcontrast);
}

/* --- Mobile --- */
@media (max-width: 600px) {
  .crate-heroes--multi {
    grid-template-columns: 1fr;
  }

  .crate-hero__inner {
    flex-direction: column;
  }

  .crate-hero__art,
  .crate-heroes--multi .crate-hero__art {
    flex: unset;
    width: 100%;
    aspect-ratio: 16/9;
  }

  .crate-hero__info,
  .crate-heroes--multi .crate-hero__info {
    padding: 16px;
  }

  .crate-hero__title,
  .crate-heroes--multi .crate-hero__title {
    font-size: 1.3em;
  }
}
</style>
