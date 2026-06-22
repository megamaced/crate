<template>
  <div
    ref="homeEl"
    class="crate-home"
  >
    <p
      v-if="loading"
      class="crate-status"
    >
      Loading…
    </p>

    <template v-else-if="items.length === 0">
      <div class="crate-home-empty">
        <p>Your collection is empty.</p>
        <NcButton
          variant="primary"
          @click="$emit('add')"
        >
          Add your first record
        </NcButton>
      </div>
    </template>

    <template v-else>
      <!-- Hero cards — one per populated category, compact grid when multiple -->
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
                {{ HERO_EYEBROW[hero.category ?? 'music'] }}
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
                <span
                  v-if="hero.label"
                  class="crate-tag"
                >{{ hero.label }}</span>
              </p>
              <p
                v-if="hero.genres"
                class="crate-hero__genres"
              >
                {{ genreList(hero) }}
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

      <section class="crate-home-section">
        <h3>Recently Added</h3>
        <div class="crate-card-grid">
          <MediaCard
            v-for="item in recentItems"
            :key="'r' + item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>
      </section>

      <section
        v-if="mostValuable.length > 0"
        class="crate-home-section"
      >
        <h3>Most Valuable</h3>
        <div class="crate-card-grid">
          <MediaCard
            v-for="item in mostValuable"
            :key="'mv' + item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>
      </section>

      <!-- Per-category format rows, ordered by collection size descending -->
      <div
        v-for="cat in categorySections"
        :key="cat.category"
        class="crate-category-block"
      >
        <div class="crate-category-heading">
          <span class="crate-category-heading__label">{{ cat.label }}</span>
          <span class="crate-category-heading__count">{{ cat.count }}</span>
        </div>
        <section
          v-for="row in cat.rows"
          :key="row.format"
          class="crate-home-section"
        >
          <h3>{{ row.label }}</h3>
          <div class="crate-card-grid">
            <MediaCard
              v-for="item in row.items"
              :key="item.id"
              :item="item"
              @detail="$emit('detail', item)"
            />
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import MediaCard from './MediaCard.vue'
import { CATEGORY_LABELS, FORMAT_LIST } from '../utils/categoryFormats.js'
import { useSettings } from '../composables/useSettings.js'

defineEmits(['add', 'detail'])

const HERO_EYEBROW = {
  music: 'Album of the Day',
  film:  'Film of the Day',
  book:  'Book of the Day',
  game:  'Game of the Day',
  comic: 'Comic of the Day',
}

const HERO_PLACEHOLDER = {
  music: '♫',
  film:  '▶',
  book:  '☰',
  game:  '⊞',
  comic: '◉',
}

const CATEGORY_DISPLAY_ORDER = ['music', 'film', 'book', 'game', 'comic']

const { hiddenCategories } = useSettings()

const loading = ref(false)
const allItems = ref([])
// Items with hidden categories filtered out — reactive on hiddenCategories
// so unhiding shows them again without a re-fetch.
const items = computed(() =>
  allItems.value.filter(i => !hiddenCategories.value.includes(i.category ?? 'music')),
)
const homeEl = ref(null)
const rowCount = ref(6)

function updateRowCount() {
  const el = homeEl.value?.$el ?? homeEl.value
  if (!el) return
  const width = el.clientWidth
  // Cards: minmax(180px, 1fr) with 12px gap
  const cols = Math.floor((width + 12) / (180 + 12))
  rowCount.value = Math.max(cols, 2)
}

let _resizeObserver = null

async function load() {
  loading.value = true
  try {
    const response = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = response.data.ocs?.data ?? []
    allItems.value = all.filter(i => i.status === 'owned')
  } catch (e) {
    console.error('Failed to load items', e)
    showError('Failed to load recent items')
  } finally {
    loading.value = false
  }
}

function dateSeed() {
  const s = new Date().toDateString()
  let h = 0
  for (let i = 0; i < s.length; i++) {
    h = Math.imul(31, h) + s.charCodeAt(i) | 0
  }
  return Math.abs(h)
}

function seededShuffle(arr, seed) {
  const a = [...arr]
  let s = seed
  for (let i = a.length - 1; i > 0; i--) {
    s = (s * 1664525 + 1013904223) & 0xffffffff
    const j = Math.abs(s) % (i + 1);
    [a[i], a[j]] = [a[j], a[i]]
  }
  return a
}

function stringHash(s) {
  let h = 0
  for (let i = 0; i < s.length; i++) {
    h = Math.imul(31, h) + s.charCodeAt(i) | 0
  }
  return Math.abs(h)
}

// One hero item per populated category, picked by deterministic daily seed
const heroItems = computed(() => {
  if (items.value.length === 0) return []
  const seed = dateSeed()
  const byCategory = {}
  for (const item of items.value) {
    const cat = item.category ?? 'music'
    if (!byCategory[cat]) byCategory[cat] = []
    byCategory[cat].push(item)
  }
  return CATEGORY_DISPLAY_ORDER
    .filter(cat => byCategory[cat]?.length > 0)
    .map(cat => {
      const pool = byCategory[cat]
      const idx = (seed + stringHash(cat)) % pool.length
      return pool[idx]
    })
})

// Category sections ordered by collection size, each with per-format rows
const categorySections = computed(() => {
  const seed = dateSeed()
  const byCategory = {}
  for (const item of items.value) {
    const cat = item.category ?? 'music'
    if (!byCategory[cat]) byCategory[cat] = []
    byCategory[cat].push(item)
  }
  return Object.entries(byCategory)
    .sort((a, b) => b[1].length - a[1].length)
    .map(([cat, catItems]) => {
      const formatList = FORMAT_LIST[cat] ?? []
      const knownFmts = new Set(formatList)
      const rows = []

      for (const fmt of formatList) {
        const pool = catItems.filter(i => i.format === fmt)
        if (pool.length === 0) continue
        const shuffled = seededShuffle(pool, seed + stringHash(fmt + cat))
        rows.push({ format: fmt, label: pluralLabel(fmt, cat), items: shuffled.slice(0, rowCount.value) })
      }

      // Formats in the data that aren't in the known list
      const extraFmts = [...new Set(catItems
        .filter(i => i.format && !knownFmts.has(i.format))
        .map(i => i.format))]
      for (const fmt of extraFmts) {
        const pool = catItems.filter(i => i.format === fmt)
        const shuffled = seededShuffle(pool, seed + stringHash(fmt + cat))
        rows.push({ format: fmt, label: pluralLabel(fmt, cat), items: shuffled.slice(0, rowCount.value) })
      }

      return {
        category: cat,
        label: CATEGORY_LABELS[cat] ?? cat,
        count: catItems.length,
        rows,
      }
    })
})

const recentItems = computed(() => items.value.slice(0, rowCount.value))

const mostValuable = computed(() =>
  [...items.value]
    .filter(i => i.marketValue)
    .sort((a, b) => (b.marketValue ?? 0) - (a.marketValue ?? 0))
    .slice(0, rowCount.value)
)

function pluralLabel(fmt, cat) {
  // Game systems are proper nouns ("GameCube", "Dreamcast", "Neo Geo CD") —
  // pluralising them reads as nonsense ("GameCubes", "Dreamcasts"). Books,
  // films and music formats (Hardcover, Blu-ray, Vinyl) pluralise naturally.
  if (cat === 'game') return fmt
  if (fmt === 'Vinyl') return 'Vinyl'
  if (fmt.endsWith('s')) return fmt
  return fmt + 's'
}

function genreList(item) {
  try {
    const g = typeof item.genres === 'string' ? JSON.parse(item.genres) : item.genres
    return Array.isArray(g) ? g.join(' · ') : ''
  } catch {
    return ''
  }
}

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
defineExpose({ load })
</script>

<style scoped>
.crate-home {
  padding: 0 36px 40px 20px;
  padding-top: calc(var(--default-clickable-area, 44px) + 8px);
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

.crate-hero__genres {
  font-size: 0.85em;
  color: var(--color-text-maxcontrast);
  margin: 0;
}

.crate-hero__btn {
  align-self: flex-start;
  margin-top: 8px;
}

/* --- Category block --- */
.crate-category-block {
  margin-bottom: 8px;
}

.crate-category-heading {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin: 0 0 16px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--color-border);
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

.crate-status {
  color: var(--color-text-maxcontrast);
}
</style>
