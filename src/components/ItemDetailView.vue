<template>
  <div class="detail-view">
    <!-- Sticky header: topbar + hero -->
    <div class="detail-sticky-header">
      <!-- Top bar -->
      <div class="detail-topbar">
        <NcButton
          variant="tertiary"
          class="detail-back"
          @click="$emit('back')"
        >
          ← Back
        </NcButton>
        <div class="detail-topbar-actions">
          <NcButton
            v-if="!enriching && !stripping"
            variant="tertiary"
            :disabled="!hasToken"
            @click="enrich"
          >
            {{ isEnriched ? 'Re-enrich' : 'Enrich from Discogs' }}
          </NcButton>
          <NcButton
            v-if="isEnriched && !enriching && !stripping"
            variant="tertiary"
            @click="stripEnrich"
          >
            Remove data
          </NcButton>
          <NcButton
            v-if="!fetchingMarket"
            variant="tertiary"
            :disabled="!hasToken"
            @click="fetchMarketValue"
          >
            {{ item.marketValue ? 'Refresh market rate' : 'Fetch market rate' }}
          </NcButton>
          <span
            v-if="enriching"
            class="detail-enriching"
          >Fetching from Discogs…</span>
          <span
            v-if="stripping"
            class="detail-enriching"
          >Removing…</span>
          <span
            v-if="fetchingMarket"
            class="detail-enriching"
          >Fetching price…</span>
          <NcButton
            variant="tertiary"
            @click="$emit('addToPlaylist', item)"
          >
            Add to playlist
          </NcButton>
          <NcButton
            variant="tertiary"
            @click="$emit('share', item)"
          >
            Share
          </NcButton>
          <NcButton
            variant="tertiary"
            @click="$emit('edit', item)"
          >
            Edit
          </NcButton>
          <NcButton
            variant="error"
            @click="$emit('delete', item)"
          >
            Delete
          </NcButton>
        </div>
      </div>

      <!-- Hero -->
      <div class="detail-hero">
        <div
          class="detail-artwork"
          :style="artStyle"
        />

        <div class="detail-hero-info">
          <h2 class="detail-title">
            {{ item.title }}
          </h2>
          <p class="detail-artist">
            {{ item.artist }}
          </p>

          <div class="detail-badges">
            <span class="badge badge-format">{{ item.format }}</span>
            <span
              v-if="item.year"
              class="badge badge-year"
            >{{ item.year }}</span>
            <span
              v-if="item.status === 'wanted'"
              class="badge badge-wanted"
            >Wishlist</span>
            <span
              v-if="item.country"
              class="badge badge-country"
            >{{ item.country }}</span>
          </div>

          <!-- Market value -->
          <p
            v-if="item.marketValue"
            class="detail-market-value"
          >
            {{ formatMarketValue(item) }}
            <span class="detail-market-fetched">
              as of {{ formatFetchedAt(item.marketValueFetchedAt) }}
            </span>
          </p>

          <!-- Metadata grid -->
          <dl class="detail-meta">
            <template v-if="item.label">
              <dt>Label</dt>
              <dd>{{ item.label }}</dd>
            </template>
            <template v-if="item.genres">
              <dt>Genres</dt>
              <dd>{{ item.genres }}</dd>
            </template>
            <template v-if="item.barcode">
              <dt>Barcode</dt>
              <dd>{{ item.barcode }}</dd>
            </template>
            <template v-if="item.notes">
              <dt>Notes</dt>
              <dd>{{ item.notes }}</dd>
            </template>
          </dl>
        </div>
      </div>
    </div><!-- /detail-sticky-header -->

    <!-- Scrollable content -->
    <div class="detail-body">
      <!-- Tracklist -->
      <section
        v-if="tracklist.length > 0"
        class="detail-section"
      >
        <h3>Tracklist</h3>
        <table class="detail-tracklist">
          <tbody>
            <tr
              v-for="(track, idx) in tracklist"
              :key="idx"
            >
              <td class="track-pos">
                {{ track.position || (idx + 1) }}
              </td>
              <td class="track-title">
                {{ track.title }}
              </td>
              <td class="track-dur">
                {{ track.duration }}
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Pressing notes -->
      <section
        v-if="item.pressingNotes"
        class="detail-section"
      >
        <h3>Notes</h3>
        <p class="detail-notes-text">
          {{ item.pressingNotes }}
        </p>
      </section>

      <!-- Artist info -->
      <section
        v-if="item.artistBio || (members.length > 0)"
        class="detail-section"
      >
        <h3>About {{ item.artist }}</h3>
        <p
          v-if="item.artistBio"
          class="detail-bio"
        >
          {{ item.artistBio }}
        </p>
        <div
          v-if="members.length > 0"
          class="detail-members"
        >
          <strong>Members:</strong>
          {{ members.join(', ') }}
        </div>
      </section>
    </div><!-- /detail-body -->
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'

import { NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { useSettings } from '../composables/useSettings.js'
import { formatMarketValue } from '../utils/formatMarketValue.js'

const props = defineProps({
  item: { type: Object, required: true },
  hasToken: { type: Boolean, default: false },
})

const emit = defineEmits(['back', 'edit', 'delete', 'enriched', 'addToPlaylist', 'share'])

const { autoFetchMarketRates, marketCurrency } = useSettings()

const enriching = ref(false)
const stripping = ref(false)
const fetchingMarket = ref(false)

// True once full release data has been fetched from Discogs
const isEnriched = computed(() =>
  !!(props.item.genres || props.item.artistBio || (Array.isArray(props.item.tracklist) && props.item.tracklist.length > 0)),
)

const FORMAT_COLOURS = {
  Vinyl: ['#6b21a8', '#a855f7'],
  CD: ['#1d4ed8', '#60a5fa'],
  SACD: ['#0f766e', '#2dd4bf'],
  Cassette: ['#b45309', '#fbbf24'],
  MiniDisc: ['#0e7490', '#38bdf8'],
}

const artStyle = computed(() => {
  if (props.item.artworkPath) {
    const v = props.item.updatedAt ? '?v=' + encodeURIComponent(props.item.updatedAt) : ''
    const url = generateUrl('/apps/crate/artwork/' + props.item.id) + v
    return {
      backgroundImage: `url(${url})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    }
  }
  const colours = FORMAT_COLOURS[props.item.format] ?? ['#374151', '#6b7280']
  return { background: `linear-gradient(135deg, ${colours[0]}, ${colours[1]})` }
})

const tracklist = computed(() => {
  if (!props.item.tracklist) return []
  if (Array.isArray(props.item.tracklist)) return props.item.tracklist
  try { return JSON.parse(props.item.tracklist) } catch { return [] }
})

const members = computed(() => {
  if (!props.item.artistMembers) return []
  if (Array.isArray(props.item.artistMembers)) return props.item.artistMembers
  try { return JSON.parse(props.item.artistMembers) } catch { return [] }
})

async function enrich() {
  enriching.value = true
  try {
    const res = await axios.post(
      generateOcsUrl('/apps/crate/api/v1/media/' + props.item.id + '/enrich'),
    )
    const updated = res.data.ocs?.data ?? null
    if (updated) {
      emit('enriched', updated)
      if (autoFetchMarketRates.value && updated.discogsId) {
        await fetchMarketValue()
      }
    }
  } catch (e) {
    console.error('Enrich failed', e)
  } finally {
    enriching.value = false
  }
}

async function fetchMarketValue() {
  fetchingMarket.value = true
  try {
    const res = await axios.post(
      generateOcsUrl('/apps/crate/api/v1/media/' + props.item.id + '/market-value'),
      { currency: marketCurrency.value },
    )
    const updated = res.data.ocs?.data ?? null
    if (updated) emit('enriched', updated)
  } catch (e) {
    console.error('Fetch market value failed', e)
  } finally {
    fetchingMarket.value = false
  }
}

function formatFetchedAt(dateStr) {
  if (!dateStr) return ''
  try {
    return new Date(dateStr).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
  } catch {
    return dateStr
  }
}

function shouldAutoFetchMarket() {
  return autoFetchMarketRates.value && props.item.discogsId && !props.item.marketValue
}

onMounted(() => {
  if (shouldAutoFetchMarket()) fetchMarketValue()
})

// Fires when App.vue's triggerEnrich auto-enriches and updates props.item —
// discogsId goes from falsy to a real value, so fetch market rate if needed.
watch(() => props.item.discogsId, (newId, oldId) => {
  if (newId && !oldId && shouldAutoFetchMarket()) fetchMarketValue()
})

async function stripEnrich() {
  stripping.value = true
  try {
    const res = await axios.delete(
      generateOcsUrl('/apps/crate/api/v1/media/' + props.item.id + '/enrich'),
    )
    const updated = res.data.ocs?.data ?? null
    if (updated) {
      emit('enriched', updated)
    }
  } catch (e) {
    console.error('Strip enrich failed', e)
  } finally {
    stripping.value = false
  }
}
</script>

<style scoped>
.detail-market-value {
  font-size: 1.8em;
  font-weight: 800;
  color: #16a34a;
  margin: 8px 0 0;
  line-height: 1.2;
}

@media (prefers-color-scheme: dark) {
  .detail-market-value {
    color: #4ade80;
  }
}

.detail-market-fetched {
  font-size: 0.55em;
  font-weight: 400;
  color: var(--color-text-maxcontrast);
  margin-left: 8px;
}

.detail-view {
  padding: 0 0 40px;
}

.detail-body {
  padding: 0 20px;
}

/* Sticky header wrapper */
.detail-sticky-header {
  position: sticky;
  top: 0;
  background: var(--color-main-background);
  z-index: 10;
  padding: 0 20px;
}

/* Top bar */
.detail-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  padding: calc(var(--default-clickable-area, 44px) + 8px) 0 12px;
  overflow-x: auto;
  scrollbar-width: none;
}

.detail-topbar::-webkit-scrollbar {
  display: none;
}

.detail-back {
  flex-shrink: 0;
  margin-right: 8px;
}

.detail-topbar-actions {
  display: flex;
  gap: 4px;
  align-items: center;
  flex-shrink: 0;
}

.detail-enriching {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

/* Hero */
.detail-hero {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 32px;
  padding-bottom: 16px;
}

@media (max-width: 640px) {
  .detail-hero {
    grid-template-columns: 1fr;
  }
  .detail-sticky-header {
    position: static;
  }
}

.detail-artwork {
  width: 100%;
  aspect-ratio: 1;
  border-radius: var(--border-radius-large);
  background: var(--color-background-dark);
}

.detail-hero-info {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-title {
  margin: 0;
  font-size: 1.8em;
  line-height: 1.2;
}

.detail-artist {
  margin: 0;
  font-size: 1.1em;
  color: var(--color-text-maxcontrast);
}

/* Badges */
.detail-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 0.8em;
  font-weight: 600;
}

.badge-format {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text);
}

.badge-year,
.badge-country {
  background: var(--color-background-dark);
  color: var(--color-main-text);
}

.badge-wanted {
  background: var(--color-warning);
  color: #fff;
}

/* Metadata */
.detail-meta {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 4px 16px;
  margin: 0;
  font-size: 0.875em;
}

.detail-meta dt {
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
}

.detail-meta dd {
  margin: 0;
}

/* Sections */
.detail-section {
  margin-bottom: 32px;
}

.detail-section h3 {
  font-size: 0.85em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-maxcontrast);
  margin: 0 0 14px;
}

/* Tracklist */
.detail-tracklist {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9em;
}

.detail-tracklist tr:hover {
  background: var(--color-background-hover);
}

.track-pos {
  width: 3em;
  color: var(--color-text-maxcontrast);
  padding: 5px 8px 5px 0;
  text-align: right;
}

.track-title {
  padding: 5px 8px;
}

.track-dur {
  padding: 5px 0 5px 8px;
  color: var(--color-text-maxcontrast);
  text-align: right;
  white-space: nowrap;
}

/* Bio */
.detail-bio {
  font-size: 0.9em;
  line-height: 1.6;
  color: var(--color-main-text);
  white-space: pre-line;
  margin: 0 0 10px;
  max-height: 220px;
  overflow-y: auto;
}

.detail-notes-text {
  font-size: 0.9em;
  line-height: 1.6;
  white-space: pre-line;
  margin: 0;
  max-height: 180px;
  overflow-y: auto;
}

.detail-members {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}
</style>
