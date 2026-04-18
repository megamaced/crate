<template>
  <div class="crate-home">
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
      <section
        v-if="albumOfDay"
        class="crate-hero"
      >
        <div class="crate-hero__inner">
          <div class="crate-hero__art">
            <img
              v-if="albumOfDay.artworkPath"
              :src="generateUrl('/apps/crate/artwork/' + albumOfDay.id) + (albumOfDay.updatedAt ? '?v=' + encodeURIComponent(albumOfDay.updatedAt) : '')"
              :alt="albumOfDay.title"
            >
            <div
              v-else
              class="crate-hero__art-placeholder"
            >
              ♫
            </div>
          </div>
          <div class="crate-hero__info">
            <p class="crate-hero__eyebrow">
              Album of the Day
            </p>
            <h2 class="crate-hero__title">
              {{ albumOfDay.title }}
            </h2>
            <p class="crate-hero__artist">
              {{ albumOfDay.artist }}
            </p>
            <p class="crate-hero__meta">
              <span
                v-if="albumOfDay.format"
                class="crate-tag"
              >{{ albumOfDay.format }}</span>
              <span
                v-if="albumOfDay.year"
                class="crate-tag"
              >{{ albumOfDay.year }}</span>
              <span
                v-if="albumOfDay.label"
                class="crate-tag"
              >{{ albumOfDay.label }}</span>
            </p>
            <p
              v-if="albumOfDay.genres"
              class="crate-hero__genres"
            >
              {{ genreList(albumOfDay) }}
            </p>
            <NcButton
              variant="secondary"
              class="crate-hero__btn"
              @click="$emit('detail', albumOfDay)"
            >
              View details
            </NcButton>
          </div>
        </div>
      </section>

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

      <section
        v-for="row in formatRows"
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
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import MediaCard from './MediaCard.vue'

defineEmits(['add', 'detail'])

const loading = ref(false)
const items = ref([])

const ROW_COUNT = 6

async function load() {
  loading.value = true
  try {
    const response = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = response.data.ocs?.data ?? []
    items.value = all.filter(i => i.status === 'owned')
  } catch (e) {
    console.error('Failed to load items', e)
    showError('Failed to load recent items')
  } finally {
    loading.value = false
  }
}

/** Deterministic daily seed from date string */
function dateSeed() {
  const s = new Date().toDateString()
  let h = 0
  for (let i = 0; i < s.length; i++) {
    h = Math.imul(31, h) + s.charCodeAt(i) | 0
  }
  return Math.abs(h)
}

/** Seeded pseudo-shuffle using date seed */
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

const albumOfDay = computed(() => {
  if (items.value.length === 0) return null
  const seed = dateSeed()
  const idx = seed % items.value.length
  return items.value[idx]
})

const formatRows = computed(() => {
  const seed = dateSeed()
  const formatOrder = ['Vinyl', 'CD', 'Cassette', 'SACD', 'MiniDisc']
  const seen = new Set()
  const rows = []

  // Collect formats in preferred order first, then any others
  const allFormats = [...new Set(items.value.map(i => i.format).filter(Boolean))]
  const ordered = [
    ...formatOrder.filter(f => allFormats.includes(f)),
  ]

  for (const fmt of ordered) {
    const pool = items.value.filter(i => i.format === fmt)
    if (pool.length === 0) continue
    const shuffled = seededShuffle(pool, seed + fmt.charCodeAt(0))
    const picks = shuffled.slice(0, ROW_COUNT)
    picks.forEach(i => seen.add(i.id))
    rows.push({ format: fmt, label: pluralLabel(fmt), items: picks })
  }
  return rows
})

const recentItems = computed(() => items.value.slice(0, ROW_COUNT))

const mostValuable = computed(() => {
  return [...items.value]
    .filter(i => i.marketValue)
    .sort((a, b) => (b.marketValue ?? 0) - (a.marketValue ?? 0))
    .slice(0, ROW_COUNT)
})

function pluralLabel(fmt) {
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

onMounted(load)
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

/* --- Hero --- */
.crate-hero {
  margin-bottom: 24px;
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
}

.crate-hero__artist {
  font-size: 1.1em;
  color: var(--color-text-maxcontrast);
  margin: 0;
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
  .crate-hero__inner {
    flex-direction: column;
  }
  .crate-hero__art {
    flex: unset;
    width: 100%;
    aspect-ratio: 16/9;
  }
  .crate-hero__info {
    padding: 16px;
  }
  .crate-hero__title {
    font-size: 1.3em;
  }
}

.crate-status {
  color: var(--color-text-maxcontrast);
}
</style>
