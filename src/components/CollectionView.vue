<template>
  <div class="collection-view">
    <!-- Sticky header: toolbar + format filter chips -->
    <div class="cv-sticky-header">
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
              Newest First
            </option>
            <option value="createdAt-asc">
              Oldest First
            </option>
            <option value="artist-asc">
              {{ sortLabels.artist }} A–Z
            </option>
            <option value="artist-desc">
              {{ sortLabels.artist }} Z–A
            </option>
            <option value="title-asc">
              {{ sortLabels.title }} A–Z
            </option>
            <option value="title-desc">
              {{ sortLabels.title }} Z–A
            </option>
            <option value="year-asc">
              Year (Oldest)
            </option>
            <option value="year-desc">
              Year (Newest)
            </option>
            <template v-if="sortLabels.hasFormat">
              <option value="format-asc">
                Format A–Z
              </option>
              <option value="format-desc">
                Format Z–A
              </option>
            </template>
            <template v-if="sortLabels.hasValue">
              <option value="marketValue-desc">
                Value (Highest)
              </option>
              <option value="marketValue-asc">
                Value (Lowest)
              </option>
            </template>
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

          <!-- Status tabs (inline with toolbar) -->
          <div
            class="cv-status-tabs"
            role="group"
            aria-label="Collection or Wishlist"
          >
            <button
              :class="['cv-status-tab', { active: statusFilter === 'owned' }]"
              @click="statusFilter = 'owned'; filterFormat = ''"
            >
              Collection
            </button>
            <button
              :class="['cv-status-tab', { active: statusFilter === 'wanted' }]"
              @click="statusFilter = 'wanted'; filterFormat = ''"
            >
              Wishlist
            </button>
          </div>

          <NcButton
            variant="secondary"
            @click="exportOpen = true"
          >
            Export
          </NcButton>
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
          All ({{ filteredByStatus.length }})
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
    </div><!-- /cv-sticky-header -->

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
        <p>No {{ filterFormat }} items in your {{ statusFilter === 'wanted' ? 'wishlist' : 'collection' }}.</p>
        <NcButton
          variant="tertiary"
          @click="filterFormat = ''"
        >
          Clear filter
        </NcButton>
      </template>
      <template v-else>
        <p>{{ statusFilter === 'wanted' ? 'Your wishlist is empty.' : `No ${heading} items yet.` }}</p>
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
        :key="group.header"
        :ref="el => registerGroupEl(group.header, el)"
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
                <span
                  v-if="item.status === 'wanted'"
                  class="cv-badge cv-badge--wanted"
                >Wishlist</span>
                <template v-if="item.label">&ensp;·&ensp;{{ item.label }}</template>
              </span>
            </div>
            <div
              v-if="item.marketValue"
              class="cv-list-market"
            >
              {{ formatMarketValue(item) }}
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

    <ExportModal
      :show="exportOpen"
      :scope="statusFilter"
      :category="props.category"
      :has-discogs-token="props.hasDiscogsToken"
      :has-price-charting-token="props.hasPriceChartingToken"
      @close="exportOpen = false"
    />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import MediaCard from './MediaCard.vue'
import ExportModal from './ExportModal.vue'
import { formatMarketValue } from '../utils/formatMarketValue.js'
import { artworkStyleFor } from '../composables/useArtworkStyle.js'
import { CATEGORY_LABELS, FORMAT_LIST } from '../utils/categoryFormats.js'

/**
 * Per-category sort config.
 *  - artist / title : sort-option label for that column
 *  - hasValue       : whether this category can have a market value
 *                     (music=Discogs, game+comic=PriceCharting; films/books have no source)
 *  - hasFormat      : whether to offer Format A–Z / Z–A — useful where the
 *                     format axis is high-variance (e.g. games spans Sony /
 *                     Nintendo / Sega / PC and collectors group by platform).
 */
const CATEGORY_SORT_LABELS = {
  music: { artist: 'Artist',    title: 'Album',  hasValue: true,  hasFormat: false },
  film:  { artist: 'Director',  title: 'Film',   hasValue: false, hasFormat: false },
  book:  { artist: 'Author',    title: 'Title',  hasValue: false, hasFormat: false },
  game:  { artist: 'Developer', title: 'Game',   hasValue: true,  hasFormat: true  },
  comic: { artist: 'Writer',    title: 'Volume', hasValue: true,  hasFormat: false },
}

const props = defineProps({
  category:               { type: String, default: 'music' },
  scrollContainer:        { type: Object, default: null },
  hasDiscogsToken:        { type: Boolean, default: false },
  hasPriceChartingToken:  { type: Boolean, default: false },
  visible:                { type: Boolean, default: true },
})

defineEmits(['add', 'import', 'detail', 'edit', 'delete'])

const items = ref([])
const loading = ref(false)
const statusFilter = ref('owned') // 'owned' | 'wanted'
const exportOpen = ref(false)
const viewMode = ref(localStorage.getItem('crate_viewMode') ?? 'card')
const sortKey = ref('artist-asc')
const filterFormat = ref('')

watch(viewMode, v => localStorage.setItem('crate_viewMode', v))

async function load() {
  loading.value = true
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'), {
      params: { category: props.category },
    })
    items.value = res.data.ocs?.data ?? []
  } catch (e) {
    console.error('Failed to load items', e)
    showError('Failed to load your collection')
  } finally {
    loading.value = false
  }
}

defineExpose({ reload: load })

// Defer initial load until the view is actually visible. When the parent
// uses v-show, the component mounts immediately (even while hidden), so
// we watch the visible prop to avoid a wasted API call on the home page.
const loaded = ref(false)
onMounted(() => {
  if (props.visible) {
    load()
    loaded.value = true
  }
})
watch(() => props.visible, (vis) => {
  if (vis && !loaded.value) {
    load()
    loaded.value = true
  }
})
watch(() => props.category, load)

// If we land on a category that doesn't support the active sort axis,
// reset to a sensible default so the select doesn't show an empty /
// invalid selection.
watch(
  [() => props.category, sortKey],
  () => {
    const key = sortKey.value
    const stale =
      (key.startsWith('marketValue-') && !sortLabels.value.hasValue)
      || (key.startsWith('format-')   && !sortLabels.value.hasFormat)
    if (stale) {
      sortKey.value = 'artist-asc'
    }
  },
  { immediate: true },
)

const filteredByStatus = computed(() =>
  items.value.filter(i => i.status === statusFilter.value),
)

const heading = computed(() => CATEGORY_LABELS[props.category] ?? 'Collection')
const sortLabels = computed(() => CATEGORY_SORT_LABELS[props.category] ?? CATEGORY_SORT_LABELS.music)

const presentFormats = computed(() => {
  const seen = new Set()
  for (const item of filteredByStatus.value) {
    if (item.format) seen.add(item.format)
  }
  const canonical = FORMAT_LIST[props.category] ?? []
  const ordered = canonical.filter(f => seen.has(f))
  const unknown = [...seen].filter(f => !canonical.includes(f)).sort()
  return [...ordered, ...unknown]
})

function formatCount(fmt) {
  return filteredByStatus.value.filter(i => i.format === fmt).length
}

const filteredSorted = computed(() => {
  let list = filterFormat.value
    ? filteredByStatus.value.filter(i => i.format === filterFormat.value)
    : [...filteredByStatus.value]

  const [field, dir] = sortKey.value.split('-')

  list.sort((a, b) => {
    let av, bv
    if (field === 'createdAt') {
      av = a.createdAt ?? ''
      bv = b.createdAt ?? ''
    } else if (field === 'year') {
      av = a.year ?? 0
      bv = b.year ?? 0
    } else if (field === 'marketValue') {
      av = a.marketValue ?? 0
      bv = b.marketValue ?? 0
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

  if (field === 'format') {
    return item.format || 'Unknown'
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

// Map of group header → DOM element, populated via :ref in the template.
const groupEls = new Map()
function registerGroupEl(header, el) {
  if (el) groupEls.set(header, el)
  else groupEls.delete(header)
}

function updateActiveGroup() {
  const groups = groupedItems.value
  if (!groups.length) return
  let active = groups[0].header
  // Use a threshold of ~140px from top to account for the sticky toolbar
  const threshold = 140
  for (const group of groups) {
    const el = groupEls.get(group.header)
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
  // Use the scroll container prop if provided, otherwise fall back to known selectors
  const containerEl = props.scrollContainer?.$el
  if (containerEl) {
    _scrollTargets = [containerEl]
  } else {
    const SELECTORS = [
      () => document.querySelector('.app-content-vue'),
      () => document.querySelector('.app-content'),
    ]
    _scrollTargets = SELECTORS.map(s => s()).filter(Boolean)
  }
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
  const el = groupEls.get(header)
  if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function thumbStyle(item) {
  return artworkStyleFor(item)
}
</script>

<style scoped>
.collection-view {
  padding: 0 36px 40px 20px;
}

@media (max-width: 600px) {
  .collection-view {
    padding: 0 12px 40px 12px;
  }
  .cv-index {
    display: none;
  }
  .crate-card-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  }
}

/* Sticky header wrapper */
.cv-sticky-header {
  position: sticky;
  top: 0;
  background: var(--color-main-background);
  z-index: 10;
  padding-bottom: 4px;
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

/* Every bordered toolbar control is forced to the same external height
   (--default-clickable-area, same as NcButton's min-height) so the sort
   select, view toggle, status tabs, and the Export / Import / Add item
   buttons all share one horizontal line.

   The native <select> can't be a flex container, so we size it with an
   explicit height + line-height to vertically centre its text. The
   <div> wrappers for view-toggle and status-tabs use align-items:
   stretch and let their child buttons fill via flex centering. */
.cv-sort-select,
.cv-toggle-btn,
.cv-status-tab {
  min-height: var(--default-clickable-area, 44px);
  box-sizing: border-box;
}

.cv-sort-select {
  /* appearance:none so the browser's native dropdown chrome doesn't push
   * the label off-centre or eat the right-hand edge. We redraw the chevron
   * as two gradient lines so it sits in a predictable spot and reserve
   * 32px of right padding for it. */
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  background-color: var(--color-primary-element-light);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23222'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: calc(100% - 12px) center;
  background-size: 10px 6px;
  color: var(--color-primary-element-light-text);
  border: 1px solid var(--color-primary-element-light-hover);
  border-bottom-width: 2px;
  border-radius: var(--border-radius-element, 22px);
  padding: 1px 32px 0 12px;
  font-size: var(--default-font-size);
  font-weight: bold;
  cursor: pointer;
}

/* View toggle – button-group pattern: each button owns its border,
   adjacent borders collapse via negative margin, outer corners rounded. */
.cv-view-toggle {
  display: inline-flex;
  align-items: stretch;
}

.cv-toggle-btn {
  background-color: var(--color-primary-element-light);
  border: 1px solid var(--color-primary-element-light-hover);
  border-bottom-width: 2px;
  margin: 0;
  min-width: var(--default-clickable-area, 44px);
  padding: 1px 12px 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15em;
  font-weight: bold;
  cursor: pointer;
  color: var(--color-primary-element-light-text);
  transition: background 0.1s, color 0.1s, border-color 0.1s;
}

.cv-toggle-btn + .cv-toggle-btn {
  margin-left: -1px;
}

.cv-toggle-btn:first-child {
  border-radius: var(--border-radius-element, 22px) 0 0 var(--border-radius-element, 22px);
}

.cv-toggle-btn:last-child {
  border-radius: 0 var(--border-radius-element, 22px) var(--border-radius-element, 22px) 0;
}

.cv-toggle-btn.active {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  border-color: var(--color-primary-element-hover);
  position: relative;
  z-index: 1;
}

.cv-plus {
  font-size: 1.1em;
}

/* Status tabs (inline in toolbar) – button-group pattern, same as view toggle */
.cv-status-tabs {
  display: inline-flex;
  align-items: stretch;
  gap: 0;
  flex-shrink: 0;
}

.cv-status-tab {
  background-color: var(--color-primary-element-light);
  border: 1px solid var(--color-primary-element-light-hover);
  border-bottom-width: 2px;
  margin: 0;
  padding: 1px 18px 0;
  display: inline-flex;
  align-items: center;
  font-size: var(--default-font-size);
  font-weight: bold;
  cursor: pointer;
  color: var(--color-primary-element-light-text);
  transition: background 0.1s, color 0.1s, border-color 0.1s;
}

.cv-status-tab + .cv-status-tab {
  margin-left: -1px;
}

.cv-status-tab:first-child {
  border-radius: var(--border-radius-element, 22px) 0 0 var(--border-radius-element, 22px);
}

.cv-status-tab:last-child {
  border-radius: 0 var(--border-radius-element, 22px) var(--border-radius-element, 22px) 0;
}

.cv-status-tab.active {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
  border-color: var(--color-primary-element-hover);
  position: relative;
  z-index: 1;
}

/* Format filter chips */
.cv-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 8px;
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

.cv-list-market {
  font-size: 0.875em;
  font-weight: 600;
  color: #4ade80;
  white-space: nowrap;
  flex-shrink: 0;
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

.cv-badge--wanted {
  background: var(--color-warning);
  color: #fff;
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
    display: none;
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
