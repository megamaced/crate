<template>
  <!-- show-navigation gives the standard Nextcloud two-column settings layout:
       a section list on the left, the sections themselves scrolling on the
       right. The component drops the rail by itself on narrow viewports, so
       the same markup covers mobile. Section order here is the order the rail
       shows, and matches the Android app's settings screen. -->
  <NcAppSettingsDialog
    :open="open"
    show-navigation
    name="Crate settings"
    @update:open="$emit('update:open', $event)"
  >
    <!-- ── Categories ── -->
    <NcAppSettingsSection
      id="crate-settings-categories"
      name="Categories"
      description="Hide categories you don't use. Hidden categories disappear from the sidebar and the Home view, and won't show up in search. At least one category must remain visible."
    >
      <div class="settings-stack">
        <NcCheckboxRadioSwitch
          v-for="cat in categoryToggles"
          :key="cat.value"
          :model-value="!hiddenCategories.includes(cat.value)"
          :disabled="!hiddenCategories.includes(cat.value) && hiddenCategories.length >= categoryToggles.length - 1"
          @update:model-value="setCategoryVisible(cat.value, $event)"
        >
          {{ cat.label }}
        </NcCheckboxRadioSwitch>
      </div>
    </NcAppSettingsSection>

    <!-- ── Enrichment ── -->
    <NcAppSettingsSection
      id="crate-settings-enrichment"
      name="Enrichment"
      description="Fill items in with metadata, artwork and descriptions from each category's provider. Every provider except Open Library needs a key, set under API keys."
    >
      <div class="settings-group">
        <h4 class="settings-group__label">
          Automatic
        </h4>
        <NcCheckboxRadioSwitch v-model="autoEnrichOnClick">
          Auto-enrich items when opening them
        </NcCheckboxRadioSwitch>
        <p class="settings-sub-hint">
          Applies to Music (Discogs), Films (TMDB), Books (Open Library — no key needed), Games (RAWG), and Comics (ComicVine). Requires the relevant API key to be configured.
        </p>
      </div>

      <div class="settings-group">
        <h4 class="settings-group__label">
          Everything at once
        </h4>
        <div class="settings-actions">
          <NcButton
            variant="secondary"
            :disabled="enrich.running.value || marketQueue.running.value"
            @click="enrichAll()"
          >
            {{ enrich.running.value
              ? `Enriching… ${enrich.done.value} / ${enrich.total.value}`
              : 'Enrich all items (every category)' }}
          </NcButton>
          <NcButton
            v-if="enrich.running.value"
            variant="tertiary"
            @click="enrich.cancel()"
          >
            Stop
          </NcButton>
        </div>
        <p class="settings-hint settings-hint--tight">
          Enriches every un-enriched item across every category, using whichever API keys you have configured.
        </p>
      </div>

      <div class="settings-group">
        <h4 class="settings-group__label">
          By category
        </h4>
        <div class="settings-stack settings-stack--wide">
          <EnrichProviderRow
            label="Books"
            action-label="Enrich all un-enriched books"
            :disabled="enrich.running.value || marketQueue.running.value"
            @enrich="enrichAll('book')"
          >
            Book metadata, covers and author bios via the
            <a
              href="https://openlibrary.org/developers/api"
              target="_blank"
              rel="noopener"
            >Open Library API</a>.
            No API key is required.
          </EnrichProviderRow>

          <EnrichProviderRow
            label="Comics"
            action-label="Enrich all un-enriched comics"
            :disabled="!comicVine.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="comicVine.hasValue.value ? '' : 'Add a ComicVine API key under API keys to enable enrichment.'"
            @enrich="enrichAll('comic')"
          >
            Comic volume metadata, artwork, genres and descriptions via the
            <a
              href="https://comicvine.gamespot.com/api/"
              target="_blank"
              rel="noopener"
            >ComicVine API</a>.
          </EnrichProviderRow>

          <EnrichProviderRow
            label="Films"
            action-label="Enrich all un-enriched films"
            :disabled="!tmdb.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="tmdb.hasValue.value ? '' : 'Add a TMDB API key under API keys to enable enrichment.'"
            @enrich="enrichAll('film')"
          >
            Film metadata, posters and director info via the
            <a
              href="https://www.themoviedb.org/"
              target="_blank"
              rel="noopener"
            >TMDB API</a>.
          </EnrichProviderRow>

          <EnrichProviderRow
            label="Games"
            action-label="Enrich all un-enriched games"
            :disabled="!rawg.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="rawg.hasValue.value ? '' : 'Add a RAWG API key under API keys to enable enrichment.'"
            @enrich="enrichAll('game')"
          >
            Game metadata and cover art via the
            <a
              href="https://rawg.io/"
              target="_blank"
              rel="noopener"
            >RAWG API</a>.
          </EnrichProviderRow>

          <EnrichProviderRow
            label="Music"
            action-label="Enrich all un-enriched music"
            :disabled="!discogs.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="discogs.hasValue.value ? '' : 'Add a Discogs API key under API keys to enable enrichment.'"
            @enrich="enrichAll('music')"
          >
            Metadata, artwork, tracklists and artist info via the
            <a
              href="https://www.discogs.com/developers/"
              target="_blank"
              rel="noopener"
            >Discogs API</a>.
          </EnrichProviderRow>
        </div>
      </div>
    </NcAppSettingsSection>

    <!-- ── Recommendations ── -->
    <NcAppSettingsSection
      id="crate-settings-recommendations"
      name="Recommendations"
      description="Items show a &quot;More from your crate&quot; row, suggesting similar things you already own or want. That's worked out locally from your own collection and needs no setup."
    >
      <div class="settings-group">
        <h4 class="settings-group__label">
          Online
        </h4>
        <NcCheckboxRadioSwitch
          :model-value="onlineRecommendations"
          @update:model-value="onlineRecommendations = $event"
        >
          Enable online recommendations
        </NcCheckboxRadioSwitch>
        <p class="settings-sub-hint">
          Also suggest things you don't own yet, from the same service that enriched the item — Discogs for music, TMDB for films, Open Library for books, RAWG for games. Only enriched items can have these, since suggestions are looked up by the ID enrichment stores. Results are cached, so re-opening an item won't re-query the service. Comics aren't covered: ComicVine publishes no similarity data.
        </p>
      </div>
    </NcAppSettingsSection>

    <!-- ── Market values ── -->
    <NcAppSettingsSection
      id="crate-settings-market"
      name="Market values"
    >
      <p class="settings-hint">
        Music market values come from Discogs; game and comic prices come from
        <a
          href="https://www.pricecharting.com/"
          target="_blank"
          rel="noopener"
        >PriceCharting</a> (paid API &mdash; requires a subscription). Both keys live under API keys. Films and books have no market-value source.
      </p>

      <div class="settings-group">
        <h4 class="settings-group__label">
          Display
        </h4>
        <div class="settings-field settings-field--inline">
          <label for="market-currency">Display currency</label>
          <select
            id="market-currency"
            v-model="marketCurrency"
            class="settings-currency-select"
          >
            <option
              v-for="c in currencies"
              :key="c"
              :value="c"
            >
              {{ c }}
            </option>
          </select>
          <p class="settings-sub-hint settings-sub-hint--flush">
            Used for Discogs (music) prices. PriceCharting (games &amp; comics) prices are always in USD.
          </p>
        </div>
      </div>

      <div class="settings-group">
        <h4 class="settings-group__label">
          Fetching
        </h4>
        <NcCheckboxRadioSwitch v-model="autoFetchMarketRates">
          Fetch market rates automatically
        </NcCheckboxRadioSwitch>
        <p class="settings-sub-hint">
          When enabled, opening an item triggers a live price lookup for the applicable categories.
        </p>

        <div class="settings-actions settings-actions--spaced">
          <NcButton
            variant="secondary"
            :disabled="(!discogs.hasValue.value && !priceCharting.hasValue.value) || marketQueue.running.value || enrich.running.value"
            @click="refreshAllMarketRates"
          >
            {{ marketQueue.running.value
              ? `Fetching… ${marketQueue.done.value} / ${marketQueue.total.value}`
              : 'Refresh all market rates' }}
          </NcButton>
          <NcButton
            v-if="marketQueue.running.value"
            variant="tertiary"
            @click="marketQueue.cancel()"
          >
            Stop
          </NcButton>
          <span
            v-if="!discogs.hasValue.value && !priceCharting.hasValue.value"
            class="settings-hint settings-hint--flush"
          >Add a Discogs or PriceCharting API key to enable market rates.</span>
        </div>
      </div>
    </NcAppSettingsSection>

    <!-- ── API keys ──
         Every provider credential in one place, in the same order as the
         Android app. Keys are configured once and then never touched, which is
         why they sit below the sections that use them rather than inside each. -->
    <NcAppSettingsSection
      id="crate-settings-api-keys"
      name="API keys"
      description="Keys are stored on your Nextcloud server and are never shown again once saved. Books need no key — Open Library is open."
    >
      <TokenField
        v-model="discogs.input.value"
        input-id="discogs-token"
        label="Discogs personal access token"
        :has-value="discogs.hasValue.value"
        :saving="discogs.saving.value"
        :message="discogs.message.value"
        @save="saveDiscogsToken"
        @remove="clearDiscogsToken"
      >
        Powers music enrichment and music market values. Generate a personal access token at
        <a
          href="https://www.discogs.com/settings/developers"
          target="_blank"
          rel="noopener"
        >discogs.com/settings/developers</a>.
      </TokenField>

      <TokenField
        v-model="tmdb.input.value"
        input-id="tmdb-token"
        label="TMDB API Read Access Token"
        :has-value="tmdb.hasValue.value"
        :saving="tmdb.saving.value"
        :message="tmdb.message.value"
        @save="saveTmdbToken"
        @remove="clearTmdbToken"
      >
        Powers film enrichment. Generate an API Read Access Token at
        <a
          href="https://www.themoviedb.org/settings/api"
          target="_blank"
          rel="noopener"
        >themoviedb.org/settings/api</a>.
      </TokenField>

      <TokenField
        v-model="rawg.input.value"
        input-id="rawg-key"
        label="RAWG API key"
        :has-value="rawg.hasValue.value"
        :saving="rawg.saving.value"
        :message="rawg.message.value"
        @save="saveRawgKey"
        @remove="clearRawgKey"
      >
        Powers game enrichment. Get a free API key at
        <a
          href="https://rawg.io/apidocs"
          target="_blank"
          rel="noopener"
        >rawg.io/apidocs</a>.
      </TokenField>

      <TokenField
        v-model="comicVine.input.value"
        input-id="comicvine-key"
        label="ComicVine API key"
        :has-value="comicVine.hasValue.value"
        :saving="comicVine.saving.value"
        :message="comicVine.message.value"
        @save="saveComicVineKey"
        @remove="clearComicVineKey"
      >
        Powers comic enrichment. Get a free API key at
        <a
          href="https://comicvine.gamespot.com/api/"
          target="_blank"
          rel="noopener"
        >comicvine.gamespot.com/api</a>.
      </TokenField>

      <TokenField
        v-model="priceCharting.input.value"
        input-id="pricecharting-token"
        label="PriceCharting API key"
        :has-value="priceCharting.hasValue.value"
        :saving="priceCharting.saving.value"
        :message="priceCharting.message.value"
        @save="savePriceChartingToken"
        @remove="clearPriceChartingToken"
      >
        Powers game and comic market values. API access is a paid subscription &mdash; see the
        <a
          href="https://www.pricecharting.com/api-documentation"
          target="_blank"
          rel="noopener"
        >API documentation</a> for pricing and details.
      </TokenField>
    </NcAppSettingsSection>

    <!-- ── Danger zone ── -->
    <NcAppSettingsSection
      id="crate-settings-danger"
      name="Danger zone"
      description="Permanently delete selected data from your collection. You choose what to wipe in the confirmation dialog. This cannot be undone."
    >
      <div class="settings-actions">
        <NcButton
          variant="error"
          :disabled="wiping"
          @click="openWipeDialog"
        >
          {{ wiping ? 'Wiping…' : 'Wipe data…' }}
        </NcButton>
        <span
          v-if="wipedMessage"
          class="settings-saved"
        >{{ wipedMessage }}</span>
      </div>

      <NcDialog
        v-if="confirmWipe"
        name="Wipe data"
        :open="confirmWipe"
        @closing="confirmWipe = false"
      >
        <p>Tick the categories you want to permanently delete. There is no undo.</p>
        <div class="wipe-scopes">
          <NcCheckboxRadioSwitch
            v-for="scope in wipeScopes"
            :key="scope.value"
            :model-value="wipeSelection.includes(scope.value)"
            @update:model-value="toggleWipeScope(scope.value, $event)"
          >
            {{ scope.label }}
          </NcCheckboxRadioSwitch>
        </div>
        <template #actions>
          <NcButton
            type="button"
            variant="tertiary"
            @click="confirmWipe = false"
          >
            Cancel
          </NcButton>
          <NcButton
            type="button"
            variant="error"
            :disabled="wipeSelection.length === 0"
            @click="wipeCollection"
          >
            Wipe selected
          </NcButton>
        </template>
      </NcDialog>
    </NcAppSettingsSection>
  </NcAppSettingsDialog>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import EnrichProviderRow from './settings/EnrichProviderRow.vue'
import TokenField from './settings/TokenField.vue'
import { useEnrichQueue } from '../composables/useEnrichQueue.js'
import { useMarketValueQueue } from '../composables/useMarketValueQueue.js'
import { useSettings } from '../composables/useSettings.js'
import { useTokenSetting } from '../composables/useTokenSetting.js'

defineProps({
  open: { type: Boolean, required: true },
})
const emit = defineEmits(['update:open', 'token-changed', 'tmdb-token-changed', 'rawg-key-changed', 'comicvine-key-changed', 'pricecharting-token-changed', 'collection-wiped'])

const enrich = useEnrichQueue()
const marketQueue = useMarketValueQueue()
const {
  autoEnrichOnClick,
  autoFetchMarketRates,
  marketCurrency,
  hiddenCategories,
  onlineRecommendations,
} = useSettings()

// Alphabetical by label so the Categories list matches Android.
const categoryToggles = [
  { value: 'book',  label: 'Books' },
  { value: 'comic', label: 'Comics' },
  { value: 'film',  label: 'Films' },
  { value: 'game',  label: 'Games' },
  { value: 'music', label: 'Music' },
]

function setCategoryVisible(category, visible) {
  const current = new Set(hiddenCategories.value)
  if (visible) {
    current.delete(category)
  } else {
    // Refuse to hide the last visible category — the disabled prop on the
    // switch usually catches this, but guard defensively too.
    if (current.size >= categoryToggles.length - 1) return
    current.add(category)
  }
  hiddenCategories.value = [...current]
}

const currencies = ref([])

// Token settings via composable
const discogs = useTokenSetting({ endpoint: '/settings/discogs-token', payloadKey: 'token', responseKey: 'hasToken', label: 'Discogs token' })
const tmdb = useTokenSetting({ endpoint: '/settings/tmdb-token', payloadKey: 'token', responseKey: 'hasToken', label: 'TMDB token' })
const rawg = useTokenSetting({ endpoint: '/settings/rawg-key', payloadKey: 'key', responseKey: 'hasKey', label: 'RAWG key' })
const comicVine = useTokenSetting({ endpoint: '/settings/comicvine-key', payloadKey: 'key', responseKey: 'hasKey', label: 'ComicVine key' })
const priceCharting = useTokenSetting({ endpoint: '/settings/pricecharting-token', payloadKey: 'token', responseKey: 'hasToken', label: 'PriceCharting token' })

const confirmWipe = ref(false)
const wiping = ref(false)
const wipedMessage = ref('')
// One handle, so a second wipe's message isn't cleared by the first wipe's
// timer and nothing fires after the panel is gone.
let wipedTimer = null
onBeforeUnmount(() => clearTimeout(wipedTimer))

const wipeScopes = [
  { value: 'music',     label: 'Music' },
  { value: 'film',      label: 'Films' },
  { value: 'book',      label: 'Books' },
  { value: 'game',      label: 'Games' },
  { value: 'comic',     label: 'Comics' },
  { value: 'playlists', label: 'Playlists and shares' },
]
const wipeSelection = ref([])

function openWipeDialog() {
  // Default: everything selected.
  wipeSelection.value = wipeScopes.map(s => s.value)
  confirmWipe.value = true
}

function toggleWipeScope(value, checked) {
  if (checked) {
    if (!wipeSelection.value.includes(value)) wipeSelection.value.push(value)
  } else {
    wipeSelection.value = wipeSelection.value.filter(v => v !== value)
  }
}

async function load() {
  try {
    const [currRes] = await Promise.all([
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/currencies')),
      discogs.load(),
      tmdb.load(),
      rawg.load(),
      comicVine.load(),
      priceCharting.load(),
    ])
    currencies.value = currRes.data.ocs?.data ?? []
  } catch (e) {
    console.error('Failed to load settings', e)
    showError('Failed to load settings')
  }
}

async function saveDiscogsToken() {
  await discogs.save()
  emit('token-changed', discogs.hasValue.value)
}

async function clearDiscogsToken() {
  await discogs.clear()
  emit('token-changed', false)
}

async function saveTmdbToken() {
  await tmdb.save()
  emit('tmdb-token-changed', tmdb.hasValue.value)
}

async function clearTmdbToken() {
  await tmdb.clear()
  emit('tmdb-token-changed', false)
}

async function saveRawgKey() {
  await rawg.save()
  emit('rawg-key-changed', rawg.hasValue.value)
}

async function clearRawgKey() {
  await rawg.clear()
  emit('rawg-key-changed', false)
}

async function saveComicVineKey() {
  await comicVine.save()
  emit('comicvine-key-changed', comicVine.hasValue.value)
}

async function clearComicVineKey() {
  await comicVine.clear()
  emit('comicvine-key-changed', false)
}

async function savePriceChartingToken() {
  await priceCharting.save()
  emit('pricecharting-token-changed', priceCharting.hasValue.value)
}

async function clearPriceChartingToken() {
  await priceCharting.clear()
  emit('pricecharting-token-changed', false)
}

async function wipeCollection() {
  const scopes = [...wipeSelection.value]
  if (scopes.length === 0) return

  confirmWipe.value = false
  wiping.value = true
  clearTimeout(wipedTimer)
  wipedMessage.value = ''
  try {
    await axios.delete(generateOcsUrl('/apps/crate/api/v1/media'), {
      params: { scopes: scopes.join(',') },
    })
    const allSelected = scopes.length === wipeScopes.length
    wipedMessage.value = allSelected
      ? 'Collection wiped.'
      : `Wiped: ${scopes.join(', ')}.`
    emit('collection-wiped')
    clearTimeout(wipedTimer)
    wipedTimer = setTimeout(() => { wipedMessage.value = '' }, 4000)
  } catch (e) {
    console.error('Failed to wipe collection', e)
    showError('Failed to wipe collection')
    wipedMessage.value = 'Failed — check the console.'
  } finally {
    wiping.value = false
  }
}

async function refreshAllMarketRates() {
  if (marketQueue.running.value) return
  try {
    // The server already knows which items have a price source — music needs a
    // Discogs id, games and comics are looked up by title — and returns just
    // their ids, so the whole collection doesn't have to come down the wire to
    // be filtered here.
    const res = await axios.post(generateOcsUrl('/apps/crate/api/v1/market-value/refresh-all'))
    const ids = res.data.ocs?.data?.itemIds ?? []
    if (ids.length > 0) {
      marketQueue.start(ids, marketCurrency.value)
    }
  } catch (e) {
    console.error('Failed to load items for market rate refresh', e)
    showError('Failed to start market rate refresh')
  }
}

/**
 * Start the enrich queue for every un-enriched item in the given category,
 * or — when category is null — across every category the user has. Shared
 * between the "Enrich all items" button and each per-category button in the
 * Enrichment section.
 */
async function enrichAll(category = null) {
  if (enrich.running.value || marketQueue.running.value) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'), {
      params: category ? { category } : {},
    })
    const all = res.data.ocs?.data ?? []
    const needsEnrich = all.filter(item =>
      !item.genres && !item.artistBio
      && !(Array.isArray(item.tracklist) && item.tracklist.length > 0),
    ).map(item => item.id)
    if (needsEnrich.length > 0) {
      enrich.start(needsEnrich)
    }
  } catch (e) {
    console.error('Failed to load items for enrichment', e)
    showError('Failed to start enrichment')
  }
}

onMounted(load)
</script>

<style scoped>
/* A titled block within a section — the bold sub-label plus the controls it
   covers. NcAppSettingsSection already spaces its direct children apart, so a
   group only has to handle the gaps inside itself. */
.settings-group {
  display: flex;
  flex-direction: column;
}

.settings-group__label {
  font-size: 0.9em;
  font-weight: 700;
  margin: 0 0 8px;
}

/* Vertical run of like-for-like controls (category switches, provider rows). */
.settings-stack {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.settings-stack--wide {
  gap: 16px;
}

.wipe-scopes {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin: 12px 0 4px;
}

.settings-hint {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin-bottom: 16px;
  line-height: 1.5;
}

.settings-hint--tight {
  margin: 8px 0 0;
}

.settings-hint--flush {
  margin: 0;
}

.settings-hint a {
  color: var(--color-primary-element);
}

/* Indented to line up under the label of the switch it belongs to. */
.settings-sub-hint {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin: 4px 0 0 28px;
  line-height: 1.4;
}

.settings-sub-hint--flush {
  margin-left: 0;
}

.settings-field--inline {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.settings-field label {
  display: block;
  font-size: 0.875em;
  font-weight: 500;
  margin-bottom: 6px;
}

.settings-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.settings-actions--spaced {
  margin-top: 16px;
}

.settings-saved {
  font-size: 0.875em;
  color: #4ade80;
}

.settings-currency-select {
  display: block;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  min-width: 120px;
  width: fit-content;
}

.settings-currency-select:focus {
  border-color: var(--color-primary-element);
  outline: none;
}
</style>
