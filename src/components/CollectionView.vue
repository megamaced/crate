<template>
  <div class="collection-view">
    <!-- Toolbar -->
    <div class="cv-toolbar">
      <div class="cv-toolbar-left">
        <h2 class="cv-heading">
          {{ heading }}
        </h2>
      </div>

      <div class="cv-toolbar-right">
        <!-- Sort -->
        <select
          v-model="sortKey"
          class="cv-sort-select"
          aria-label="Sort order"
        >
          <option value="createdAt-desc">
            Date Added ↓
          </option>
          <option value="createdAt-asc">
            Date Added ↑
          </option>
          <option value="artist-asc">
            Artist A–Z
          </option>
          <option value="artist-desc">
            Artist Z–A
          </option>
          <option value="title-asc">
            Album A–Z
          </option>
          <option value="title-desc">
            Album Z–A
          </option>
          <option value="year-asc">
            Year ↑
          </option>
          <option value="year-desc">
            Year ↓
          </option>
        </select>

        <!-- View mode toggle -->
        <div
          class="cv-view-toggle"
          role="group"
          aria-label="View mode"
        >
          <button
            :class="['cv-toggle-btn', { active: viewMode === 'card' }]"
            title="Card view"
            aria-label="Card view"
            @click="viewMode = 'card'"
          >
            ▦
          </button>
          <button
            :class="['cv-toggle-btn', { active: viewMode === 'list' }]"
            title="List view"
            aria-label="List view"
            @click="viewMode = 'list'"
          >
            ☰
          </button>
        </div>

        <NcButton
          variant="secondary"
          @click="$emit('import')"
        >
          Import
        </NcButton>
        <NcButton
          variant="primary"
          @click="$emit('add')"
        >
          <template #icon>
            <span class="cv-plus">+</span>
          </template>
          Add item
        </NcButton>
      </div>
    </div>

    <!-- Format filter chips -->
    <div
      v-if="presentFormats.length > 1"
      class="cv-filters"
      role="group"
      aria-label="Filter by format"
    >
      <button
        :class="['cv-chip', { active: filterFormat === '' }]"
        @click="filterFormat = ''"
      >
        All ({{ items.length }})
      </button>
      <button
        v-for="fmt in presentFormats"
        :key="fmt"
        :class="['cv-chip', { active: filterFormat === fmt }]"
        @click="filterFormat = fmt"
      >
        {{ fmt }} ({{ formatCount(fmt) }})
      </button>
    </div>

    <!-- Loading -->
    <p
      v-if="loading"
      class="cv-status"
    >
      Loading…
    </p>

    <!-- Empty state -->
    <div
      v-else-if="filteredSorted.length === 0"
      class="cv-empty"
    >
      <template v-if="filterFormat">
        <p>No {{ filterFormat }} items in your {{ status === 'wanted' ? 'wishlist' : 'collection' }}.</p>
        <NcButton
          variant="tertiary"
          @click="filterFormat = ''"
        >
          Clear filter
        </NcButton>
      </template>
      <template v-else>
        <p>{{ status === 'wanted' ? 'Your wishlist is empty.' : 'No items yet. Add your first record!' }}</p>
        <NcButton
          variant="primary"
          @click="$emit('add')"
        >
          Add item
        </NcButton>
      </template>
    </div>

    <!-- Grouped card / list content -->
    <template v-else>
      <div
        v-for="group in groupedItems"
        :id="'cv-grp-' + groupId(group.header)"
        :key="group.header"
        class="cv-group"
      >
        <div class="cv-group-header">
          <span class="cv-group-label">{{ group.header }}</span>
          <span class="cv-group-count">{{ group.items.length }}</span>
        </div>

        <!-- Card grid -->
        <div
          v-if="viewMode === 'card'"
          class="crate-card-grid"
        >
          <MediaCard
            v-for="item in group.items"
            :key="item.id"
            :item="item"
            @detail="$emit('detail', item)"
          />
        </div>

        <!-- List view -->
        <div
          v-else
          class="cv-list"
        >
          <div
            v-for="item in group.items"
            :key="item.id"
            class="cv-list-row"
            @click="$emit('detail', item)"
          >
            <div
              class="cv-list-thumb"
              :style="thumbStyle(item)"
            />
            <div class="cv-list-info">
              <span class="cv-list-title">{{ item.title }}</span>
              <span class="cv-list-artist">{{ item.artist }}</span>
              <span class="cv-list-meta">
                <span class="cv-badge">{{ item.format }}</span>
                <template v-if="item.year">&thinsp;{{ item.year }}</template>
                <template v-if="item.label">&ensp;·&ensp;{{ item.label }}</template>
              </span>
            </div>
            <div
              class="cv-list-actions"
              @click.stop
            >
              <NcButton
                variant="tertiary"
                :aria-label="'Edit ' + item.title"
                @click="$emit('edit', item)"
              >
                Edit
              </NcButton>
              <NcButton
                variant="tertiary"
                :aria-label="'Delete ' + item.title"
                @click="$emit('delete', item)"
              >
                Delete
              </NcButton>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Quick-nav index strip -->
    <nav
      v-if="groupedItems.length > 1"
      class="cv-index"
      aria-label="Jump to section"
    >
      <button
        v-for="group in groupedItems"
        :key="group.header"
        :class="['cv-index-btn', { active: activeGroup === group.header }]"
        :title="group.header"
        @click="scrollToGroup(group.header)"
      >
        {{ shortLabel(group.header) }}
      </button>
    </nav>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import MediaCard from './MediaCard.vue'

const props = defineProps({
  items: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  status: { type: String, default: 'owned' }, // 'owned' | 'wanted'
})

defineEmits(['add', 'import', 'detail', 'edit', 'delete'])

const viewMode = ref('card')
const sortKey = ref('artist-asc')
const filterFormat = ref('')

const heading = computed(() => props.status === 'wanted' ? 'Wishlist' : 'My Collection')

const presentFormats = computed(() => {
  const seen = new Set()
  for (const item of props.items) {
    if (item.format) seen.add(item.format)
  }
  return [...seen].sort()
})

function formatCount(fmt) {
  return props.items.filter(i => i.format === fmt).length
}

const filteredSorted = computed(() => {
  let list = filterFormat.value
    ? props.items.filter(i => i.format === filterFormat.value)
    : [...props.items]

  const [field, dir] = sortKey.value.split('-')

  list.sort((a, b) => {
    let av, bv
    if (field === 'createdAt') {
      av = a.createdAt ?? ''
      bv = b.createdAt ?? ''
    } else if (field === 'year') {
      av = a.year ?? 0
      bv = b.year ?? 0
    } else if (field === 'artist') {
      av = stripArticle(a.artist ?? '').toLowerCase()
      bv = stripArticle(b.artist ?? '').toLowerCase()
    } else {
      av = (a[field] ?? '').toLowerCase()
      bv = (b[field] ?? '').toLowerCase()
    }
    if (av < bv) return dir === 'asc' ? -1 : 1
    if (av > bv) return dir === 'asc' ? 1 : -1
    return 0
  })

  return list
})

// Strip leading articles for alphabetical grouping (but not display)
function stripArticle(str) {
  return str.replace(/^(the |a |an )\s*/i, '')
}

// Compute the group header label for a single item given the active sort field
function getGroupKey(item, field) {
  if (field === 'artist' || field === 'title') {
    const raw = (item[field] ?? '').trim()
    const cmp = field === 'artist' ? stripArticle(raw) : raw
    const first = cmp[0]?.toUpperCase() ?? '#'
    return /[A-Z]/.test(first) ? first : '#'
  }

  if (field === 'year') {
    const y = item.year
    if (!y) return 'Unknown'
    const decade = Math.floor(y / 10) * 10
    return `${decade}s`
  }

  if (field === 'createdAt') {
    const now = new Date()
    const raw = item.createdAt
    if (!raw) return 'Unknown'
    const d = new Date(raw)
    const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const itemDate = new Date(d.getFullYear(), d.getMonth(), d.getDate())
    const diffDays = Math.round((todayStart - itemDate) / 86400000)
    if (diffDays === 0) return 'Today'
    if (diffDays <= 7) return 'This Week'
    if (d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth()) return 'This Month'
    if (d.getFullYear() === now.getFullYear()) return 'This Year'
    return String(d.getFullYear())
  }

  return ''
}

// Produce an ordered array of { header, items } groups, preserving sort order
const groupedItems = computed(() => {
  const [field] = sortKey.value.split('-')
  const groups = []
  const seen = new Map()

  for (const item of filteredSorted.value) {
    const key = getGroupKey(item, field)
    if (!seen.has(key)) {
      const g = { header: key, items: [] }
      seen.set(key, g)
      groups.push(g)
    }
    seen.get(key).items.push(item)
  }

  return groups
})

// ── index / quick-nav ────────────────────────────────────────────────────────
const activeGroup = ref('')

function updateActiveGroup() {
  const groups = groupedItems.value
  if (!groups.length) return
  let active = groups[0].header
  // Use a threshold of ~140px from top to account for the sticky toolbar
  const threshold = 140
  for (const group of groups) {
    const el = document.getElementById('cv-grp-' + groupId(group.header))
    if (!el) continue
    const rect = el.getBoundingClientRect()
    if (rect.top <= threshold) {
      active = group.header
    } else {
      break
    }
  }
  activeGroup.value = active
}

let _scrollTargets = []
let _scrollHandler = null
onMounted(() => {
  _scrollHandler = updateActiveGroup
  // Attach to all potential Nextcloud scroll containers + window
  const SELECTORS = [
    () => document.getElementById('app-content-vue'),
    () => document.querySelector('.app-content-vue'),
    () => document.getElementById('content-vue'),
    () => document.getElementById('app-content'),
    () => document.querySelector('.app-content'),
  ]
  _scrollTargets = SELECTORS.map(s => s()).filter(Boolean)
  _scrollTargets.forEach(el => el.addEventListener('scroll', _scrollHandler, { passive: true }))
  window.addEventListener('scroll', _scrollHandler, { passive: true })
  updateActiveGroup()
})
onBeforeUnmount(() => {
  if (_scrollHandler) {
    _scrollTargets.forEach(el => el.removeEventListener('scroll', _scrollHandler))
    window.removeEventListener('scroll', _scrollHandler)
  }
})
watch(groupedItems, () => setTimeout(updateActiveGroup, 50))

function groupId(header) {
  return header.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '_')
}

function shortLabel(header) {
  if (header.length <= 3) return header
  // Decade like "1970s" → "70s"
  const decade = header.match(/^(\d{2})(\d{2})s$/)
  if (decade) return decade[2] + 's'
  if (header === 'This Week') return 'Wk'
  if (header === 'This Month') return 'Mo'
  if (header === 'This Year') return 'Yr'
  // Full year like "2024" → "'24"
  if (/^\d{4}$/.test(header)) return '\'' + header.slice(2)
  if (header === 'Unknown') return '?'
  return header.slice(0, 3)
}

function scrollToGroup(header) {
  activeGroup.value = header
  const el = document.getElementById('cv-grp-' + groupId(header))
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const FORMAT_COLOURS = {
  Vinyl: ['#6b21a8', '#a855f7'],
  CD: ['#1d4ed8', '#60a5fa'],
  SACD: ['#0f766e', '#2dd4bf'],
  Cassette: ['#b45309', '#fbbf24'],
  MiniDisc: ['#0e7490', '#38bdf8'],
}

function thumbStyle(item) {
  if (item.artworkPath) {
    const url = generateUrl('/apps/crate/artwork/' + item.id)
    return { backgroundImage: `url(${url})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  }
  const colours = FORMAT_COLOURS[item.format] ?? ['#374151', '#6b7280']
  return { background: `linear-gradient(135deg, ${colours[0]}, ${colours[1]})` }
}
</script>

<style scoped>
.collection-view {
  padding: 0 36px 40px 20px;
}

/* Toolbar */
.cv-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 16px;
}

.cv-toolbar-left {
  flex: 1 1 auto;
}

.cv-toolbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.cv-heading {
  margin: 0;
  font-size: 1.4em;
}

/* Sort */
.cv-sort-select {
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 5px 8px;
  font-size: 0.875em;
  cursor: pointer;
}

/* View toggle */
.cv-view-toggle {
  display: flex;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  overflow: hidden;
}

.cv-toggle-btn {
  background: none;
  border: none;
  padding: 4px 10px;
  font-size: 1em;
  cursor: pointer;
  color: var(--color-text-maxcontrast);
  transition: background 0.1s, color 0.1s;
}

.cv-toggle-btn.active {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

.cv-plus {
  font-size: 1.1em;
}

/* Format filter chips */
.cv-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 20px;
}

.cv-chip {
  border: 2px solid var(--color-border-dark);
  border-radius: 20px;
  background: none;
  color: var(--color-main-text);
  padding: 3px 12px;
  font-size: 0.8em;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.1s, border-color 0.1s, color 0.1s;
}

.cv-chip:hover {
  border-color: var(--color-primary-element);
}

.cv-chip.active {
  background: var(--color-primary-element);
  border-color: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

/* Status */
.cv-status {
  color: var(--color-text-maxcontrast);
}

.cv-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-top: 60px;
  color: var(--color-text-maxcontrast);
}

/* Group headers */
.cv-group {
  margin-bottom: 32px;
}

.cv-group-header {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 12px;
  padding-bottom: 6px;
  border-bottom: 2px solid var(--color-border);
}

.cv-group-label {
  font-size: 1.05em;
  font-weight: 700;
  letter-spacing: 0.03em;
  color: var(--color-main-text);
}

.cv-group-count {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}

/* Card grid */
.crate-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
}

/* List view */
.cv-list {
  display: flex;
  flex-direction: column;
}

.cv-list-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 8px 12px;
  border-radius: var(--border-radius-large);
  cursor: pointer;
  transition: background 0.1s;
}

.cv-list-row:hover {
  background: var(--color-background-hover);
}

.cv-list-row:hover .cv-list-actions {
  opacity: 1;
}

.cv-list-thumb {
  width: 44px;
  height: 44px;
  border-radius: var(--border-radius);
  flex-shrink: 0;
}

.cv-list-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.cv-list-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cv-list-artist {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cv-list-meta {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
}

.cv-badge {
  background: var(--color-background-dark);
  padding: 1px 6px;
  border-radius: 10px;
  font-size: 0.85em;
  font-weight: 600;
}

.cv-list-actions {
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity 0.1s;
  flex-shrink: 0;
}

@media (max-width: 480px) {
  .cv-list-actions {
    opacity: 1;
  }
  .cv-toolbar-right {
    width: 100%;
    justify-content: flex-end;
  }
}

/* Quick-nav index */
.cv-index {
  position: fixed;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  z-index: 50;
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 120px);
  overflow: hidden;
  pointer-events: auto;
}

.cv-index-btn {
  background: none;
  border: none;
  padding: 1px 5px;
  font-size: 0.68em;
  font-weight: 700;
  line-height: 1.5;
  color: var(--color-text-maxcontrast);
  cursor: pointer;
  text-align: center;
  min-width: 22px;
  border-radius: 3px;
  transition: color 0.1s, background 0.1s;
  letter-spacing: 0.02em;
}

.cv-index-btn:hover {
  color: var(--color-primary-element);
  background: var(--color-background-hover);
}

.cv-index-btn.active {
  color: var(--color-primary-element);
  background: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
}
</style>
