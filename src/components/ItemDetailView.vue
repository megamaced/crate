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
            :disabled="!hasToken || queueBusy"
            @click="enrich"
          >
            {{ isEnriched ? 'Re-enrich' : `Enrich from ${enrichSourceLabel}` }}
          </NcButton>
          <NcButton
            v-if="isEnriched && !enriching && !stripping"
            variant="tertiary"
            @click="stripEnrich"
          >
            Remove data
          </NcButton>
          <NcButton
            v-if="hasMarketValue && !fetchingMarket"
            variant="tertiary"
            :disabled="!hasMarketToken || queueBusy"
            @click="fetchMarketValue"
          >
            {{ item.marketValue ? 'Refresh market rate' : 'Fetch market rate' }}
          </NcButton>
          <span
            v-if="enriching"
            class="detail-enriching"
          >Fetching from {{ enrichSourceLabel }}…</span>
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

          <!-- Market value — single price for music, three-tier for games/comics -->
          <div
            v-if="item.marketValue || item.marketValueLoose || item.marketValueNew"
            class="detail-market-block"
          >
            <template v-if="isPriceChartingCategory">
              <div class="detail-market-prices">
                <span
                  v-if="item.marketValueLoose"
                  class="detail-market-price"
                >
                  <span class="detail-market-price-label">Loose</span>
                  {{ formatPrice(item.marketValueLoose, item.marketValueCurrency) }}
                </span>
                <span
                  v-if="item.marketValue"
                  class="detail-market-price detail-market-price--cib"
                >
                  <span class="detail-market-price-label">CIB</span>
                  {{ formatPrice(item.marketValue, item.marketValueCurrency) }}
                </span>
                <span
                  v-if="item.marketValueNew"
                  class="detail-market-price"
                >
                  <span class="detail-market-price-label">New</span>
                  {{ formatPrice(item.marketValueNew, item.marketValueCurrency) }}
                </span>
              </div>
            </template>
            <template v-else>
              <p class="detail-market-value">
                {{ formatMarketValue(item) }}
              </p>
            </template>
            <span class="detail-market-fetched">
              as of {{ formatFetchedAt(item.marketValueFetchedAt) }}
            </span>
          </div>

          <!-- Metadata grid -->
          <dl class="detail-meta">
            <template v-if="item.label">
              <dt>{{ labelFieldLabel }}</dt>
              <dd>{{ item.label }}</dd>
            </template>
            <template v-if="item.genres">
              <dt>Genres</dt>
              <dd>{{ item.genres }}</dd>
            </template>
            <template v-if="item.barcode && showBarcode">
              <dt>{{ barcodeFieldLabel }}</dt>
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
      <!-- Tracklist (music only) -->
      <section
        v-if="isMusic && tracklist.length > 0"
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

      <!-- Overview / Description / Pressing notes -->
      <section
        v-if="item.pressingNotes"
        class="detail-section"
      >
        <h3>{{ notesSectionTitle }}</h3>
        <p class="detail-notes-text">
          {{ item.pressingNotes }}
        </p>
      </section>

      <!-- Artist / Director / Author info (not shown for games) -->
      <section
        v-if="showAboutSection && (item.artistBio || members.length > 0)"
        class="detail-section"
      >
        <h3>{{ aboutSectionTitle }}</h3>
        <p
          v-if="item.artistBio"
          class="detail-bio"
        >
          {{ item.artistBio }}
        </p>
        <div
          v-if="isMusic && members.length > 0"
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
import { generateOcsUrl } from '@nextcloud/router'
import { useSettings } from '../composables/useSettings.js'
import { formatMarketValue } from '../utils/formatMarketValue.js'
import { useArtworkStyle } from '../composables/useArtworkStyle.js'

const props = defineProps({
  item: { type: Object, required: true },
  hasToken: { type: Boolean, default: false },
  hasMarketToken: { type: Boolean, default: false },
  queueBusy: { type: Boolean, default: false },
})

const emit = defineEmits(['back', 'edit', 'delete', 'enriched', 'addToPlaylist', 'share'])

const { autoFetchMarketRates, marketCurrency } = useSettings()

const enriching = ref(false)
const stripping = ref(false)
const fetchingMarket = ref(false)

const isMusic = computed(() => !props.item.category || props.item.category === 'music')
const hasMarketValue = computed(() => !['film', 'book'].includes(props.item.category))
const isPriceChartingCategory = computed(() => ['game', 'comic'].includes(props.item.category))

const enrichSourceLabel = computed(() => {
  const map = { music: 'Discogs', film: 'TMDB', book: 'Open Library', game: 'RAWG', comic: 'ComicVine' }
  return map[props.item.category] ?? 'Discogs'
})

const notesSectionTitle = computed(() => {
  if (props.item.category === 'film') return 'Overview'
  if (props.item.category === 'book' || props.item.category === 'game' || props.item.category === 'comic') return 'Description'
  return 'Notes'
})

const showAboutSection = computed(() => props.item.category !== 'game' && props.item.category !== 'comic')

const aboutSectionTitle = computed(() => {
  if (props.item.category === 'film') return 'About the Director'
  if (props.item.category === 'book') return 'About the Author'
  return `About ${props.item.artist}`
})

const labelFieldLabel = computed(() => {
  if (props.item.category === 'film') return 'Studio'
  if (props.item.category === 'book' || props.item.category === 'game' || props.item.category === 'comic') return 'Publisher'
  return 'Label'
})

const showBarcode = computed(() => isMusic.value || props.item.category === 'book')
const barcodeFieldLabel = computed(() => props.item.category === 'book' ? 'ISBN' : 'Barcode')

const isEnriched = computed(() => {
  const i = props.item
  return !!(i.genres || i.artistBio || i.pressingNotes || i.discogsId ||
    (Array.isArray(i.tracklist) && i.tracklist.length > 0))
})

const artStyle = useArtworkStyle(computed(() => props.item))

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
      if (autoFetchMarketRates.value && updated.discogsId && !props.queueBusy) {
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

function formatPrice(value, currency) {
  if (value == null) return ''
  const c = currency ?? 'USD'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: c }).format(value)
  } catch {
    return `${c} ${value.toFixed(2)}`
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
  if (!autoFetchMarketRates.value || props.item.marketValue) return false
  if (isMusic.value) return !!(props.item.discogsId && props.hasMarketToken)
  if (isPriceChartingCategory.value) return !!(props.item.title && props.hasMarketToken)
  return false
}

onMounted(() => {
  if (shouldAutoFetchMarket()) fetchMarketValue()
})

// Fires when enrichment completes and updates props.item — fetch market rate if configured.
// Music: triggers when discogsId is first set. Games: triggers when discogsId (RAWG ID) is set.
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
  color: #4ade80;
  margin: 8px 0 0;
  line-height: 1.2;
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
  text-align: left;
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
}

.detail-notes-text {
  font-size: 0.9em;
  line-height: 1.6;
  white-space: pre-line;
  margin: 0;
}

.detail-members {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
}

/* PriceCharting three-tier price display */
.detail-market-block {
  margin: 8px 0 0;
}

.detail-market-prices {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  align-items: baseline;
}

.detail-market-price {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.detail-market-price-label {
  font-size: 0.65em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-text-maxcontrast);
}

.detail-market-price--cib {
  font-size: 1.3em;
  font-weight: 800;
  color: #4ade80;
}
</style>
