<template>
  <NcAppSettingsDialog
    :open="open"
    :show-navigation="false"
    name="Crate settings"
    @update:open="$emit('update:open', $event)"
  >
    <!-- ── General ── -->
    <NcAppSettingsSection
      id="crate-settings-general"
      name="General"
    >
      <div class="settings-enrichment-options">
        <div>
          <NcCheckboxRadioSwitch v-model="autoEnrichOnClick">
            Auto-enrich items when opening them
          </NcCheckboxRadioSwitch>
          <p class="settings-sub-hint">
            Applies to Music (Discogs), Films (TMDB), Books (Open Library — no key needed), Games (RAWG), and Comics (ComicVine). Requires the relevant API key to be configured below.
          </p>
        </div>

        <div class="settings-actions settings-enrich-all">
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
        <p class="settings-sub-hint">
          Enriches every un-enriched item across every category, using whichever API keys you have configured below.
        </p>
      </div>
    </NcAppSettingsSection>

    <!-- ── Books ── -->
    <NcAppSettingsSection
      id="crate-settings-books"
      name="Books"
    >
      <p class="settings-hint">
        Book metadata, covers and author bios via the
        <a
          href="https://openlibrary.org/developers/api"
          target="_blank"
          rel="noopener"
        >Open Library API</a>.
        No API key is required.
      </p>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="enrich.running.value || marketQueue.running.value"
          @click="enrichAll('book')"
        >
          Enrich all un-enriched books
        </NcButton>
      </div>
    </NcAppSettingsSection>
    <!-- ── Comics ── -->
    <NcAppSettingsSection
      id="crate-settings-comics"
      name="Comics"
    >
      <p class="settings-hint">
        Comic volume metadata, artwork, genres and descriptions via the
        <a
          href="https://comicvine.gamespot.com/api/"
          target="_blank"
          rel="noopener"
        >ComicVine API</a>.
        Get a free API key at
        <a
          href="https://comicvine.gamespot.com/api/"
          target="_blank"
          rel="noopener"
        >comicvine.gamespot.com/api</a>.
      </p>

      <div class="settings-field">
        <label for="comicvine-key">ComicVine API key</label>
        <div class="settings-token-row">
          <input
            id="comicvine-key"
            v-model="comicVine.input.value"
            type="password"
            :placeholder="comicVine.hasValue.value ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="comicVine.saving.value || comicVine.input.value === ''"
          @click="saveComicVineKey"
        >
          {{ comicVine.saving.value ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="comicVine.hasValue.value"
          variant="tertiary"
          :disabled="comicVine.saving.value"
          @click="clearComicVineKey"
        >
          Remove
        </NcButton>
        <span
          v-if="comicVine.message.value"
          class="settings-saved"
        >{{ comicVine.message.value }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!comicVine.hasValue.value || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('comic')"
        >
          Enrich all un-enriched comics
        </NcButton>
        <span
          v-if="!comicVine.hasValue.value"
          class="settings-hint"
          style="margin:0"
        >Add a ComicVine API key above to enable enrichment.</span>
      </div>
    </NcAppSettingsSection>
    <!-- ── Films ── -->
    <NcAppSettingsSection
      id="crate-settings-films"
      name="Films"
    >
      <p class="settings-hint">
        Film metadata, posters and director info via the
        <a
          href="https://www.themoviedb.org/"
          target="_blank"
          rel="noopener"
        >TMDB API</a>.
        Generate an API Read Access Token at
        <a
          href="https://www.themoviedb.org/settings/api"
          target="_blank"
          rel="noopener"
        >themoviedb.org/settings/api</a>.
      </p>

      <div class="settings-field">
        <label for="tmdb-token">TMDB API Read Access Token</label>
        <div class="settings-token-row">
          <input
            id="tmdb-token"
            v-model="tmdb.input.value"
            type="password"
            :placeholder="tmdb.hasValue.value ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="tmdb.saving.value || tmdb.input.value === ''"
          @click="saveTmdbToken"
        >
          {{ tmdb.saving.value ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="tmdb.hasValue.value"
          variant="tertiary"
          :disabled="tmdb.saving.value"
          @click="clearTmdbToken"
        >
          Remove
        </NcButton>
        <span
          v-if="tmdb.message.value"
          class="settings-saved"
        >{{ tmdb.message.value }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!tmdb.hasValue.value || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('film')"
        >
          Enrich all un-enriched films
        </NcButton>
        <span
          v-if="!tmdb.hasValue.value"
          class="settings-hint"
          style="margin:0"
        >Add a TMDB API key above to enable enrichment.</span>
      </div>
    </NcAppSettingsSection>
    <!-- ── Games ── -->
    <NcAppSettingsSection
      id="crate-settings-games"
      name="Games"
    >
      <p class="settings-hint">
        Game metadata and cover art via the
        <a
          href="https://rawg.io/"
          target="_blank"
          rel="noopener"
        >RAWG API</a>.
        Get a free API key at
        <a
          href="https://rawg.io/apidocs"
          target="_blank"
          rel="noopener"
        >rawg.io/apidocs</a>.
      </p>

      <div class="settings-field">
        <label for="rawg-key">RAWG API key</label>
        <div class="settings-token-row">
          <input
            id="rawg-key"
            v-model="rawg.input.value"
            type="password"
            :placeholder="rawg.hasValue.value ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="rawg.saving.value || rawg.input.value === ''"
          @click="saveRawgKey"
        >
          {{ rawg.saving.value ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="rawg.hasValue.value"
          variant="tertiary"
          :disabled="rawg.saving.value"
          @click="clearRawgKey"
        >
          Remove
        </NcButton>
        <span
          v-if="rawg.message.value"
          class="settings-saved"
        >{{ rawg.message.value }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!rawg.hasValue.value || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('game')"
        >
          Enrich all un-enriched games
        </NcButton>
        <span
          v-if="!rawg.hasValue.value"
          class="settings-hint"
          style="margin:0"
        >Add a RAWG API key above to enable enrichment.</span>
      </div>
    </NcAppSettingsSection>
    <!-- ── Music ── -->
    <NcAppSettingsSection
      id="crate-settings-music"
      name="Music"
    >
      <p class="settings-hint">
        Metadata, artwork, tracklists and artist info via the
        <a
          href="https://www.discogs.com/developers/"
          target="_blank"
          rel="noopener"
        >Discogs API</a>.
        Generate a personal access token at
        <a
          href="https://www.discogs.com/settings/developers"
          target="_blank"
          rel="noopener"
        >discogs.com/settings/developers</a>.
      </p>

      <div class="settings-field">
        <label for="discogs-token">Discogs personal access token</label>
        <div class="settings-token-row">
          <input
            id="discogs-token"
            v-model="discogs.input.value"
            type="password"
            :placeholder="discogs.hasValue.value ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="discogs.saving.value || discogs.input.value === ''"
          @click="saveDiscogsToken"
        >
          {{ discogs.saving.value ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="discogs.hasValue.value"
          variant="tertiary"
          :disabled="discogs.saving.value"
          @click="clearDiscogsToken"
        >
          Remove
        </NcButton>
        <span
          v-if="discogs.message.value"
          class="settings-saved"
        >{{ discogs.message.value }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!discogs.hasValue.value || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('music')"
        >
          Enrich all un-enriched music
        </NcButton>
        <span
          v-if="!discogs.hasValue.value"
          class="settings-hint"
          style="margin:0"
        >Add a Discogs API key above to enable enrichment.</span>
      </div>
    </NcAppSettingsSection>

    <!-- ── Market Values ── -->
    <NcAppSettingsSection
      id="crate-settings-market"
      name="Market values"
    >
      <p class="settings-hint">
        Music market values come from Discogs (configured in the Music section above); game and comic prices come from
        <a
          href="https://www.pricecharting.com/"
          target="_blank"
          rel="noopener"
        >PriceCharting</a> (paid API &mdash; requires a subscription). Films and books have no market-value source.
      </p>

      <div class="settings-enrichment-options">
        <div>
          <NcCheckboxRadioSwitch v-model="autoFetchMarketRates">
            Fetch market rates automatically
          </NcCheckboxRadioSwitch>
          <p class="settings-sub-hint">
            When enabled, opening an item triggers a live price lookup for the applicable categories.
          </p>
        </div>

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
          <p class="settings-sub-hint">
            Used for Discogs (music) prices. PriceCharting (games &amp; comics) prices are always in USD.
          </p>
        </div>
      </div>

      <div class="settings-field">
        <label for="pricecharting-token">PriceCharting API key</label>
        <p class="settings-sub-hint pricecharting-helper">
          API access is a paid subscription &mdash; see the
          <a
            href="https://www.pricecharting.com/api-documentation"
            target="_blank"
            rel="noopener"
          >API documentation</a> for pricing and details.
        </p>
        <div class="settings-token-row">
          <input
            id="pricecharting-token"
            v-model="priceCharting.input.value"
            type="password"
            :placeholder="priceCharting.hasValue.value ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="priceCharting.saving.value || priceCharting.input.value === ''"
          @click="savePriceChartingToken"
        >
          {{ priceCharting.saving.value ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="priceCharting.hasValue.value"
          variant="tertiary"
          :disabled="priceCharting.saving.value"
          @click="clearPriceChartingToken"
        >
          Remove
        </NcButton>
        <span
          v-if="priceCharting.message.value"
          class="settings-saved"
        >{{ priceCharting.message.value }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
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
          class="settings-hint"
          style="margin:0"
        >Add a Discogs or PriceCharting API key to enable market rates.</span>
      </div>
    </NcAppSettingsSection>

    <!-- ── Sharing ── -->
    <NcAppSettingsSection
      id="crate-settings-sharing"
      name="Sharing"
    >
      <p class="settings-hint">
        Share your whole collection or a single category read-only with another Nextcloud user. Individual albums and playlists are still shared from the item or playlist itself.
      </p>
      <div class="settings-actions settings-share-row">
        <NcButton
          variant="secondary"
          @click="$emit('share-library')"
        >
          Share whole library…
        </NcButton>
      </div>
      <div class="settings-share-categories">
        <NcButton
          v-for="cat in shareCategoryOptions"
          :key="cat.value"
          variant="tertiary"
          @click="$emit('share-category', cat.value)"
        >
          Share {{ cat.label }}…
        </NcButton>
      </div>
    </NcAppSettingsSection>

    <!-- ── Danger Zone ── -->
    <NcAppSettingsSection
      id="crate-settings-danger"
      name="Danger zone"
    >
      <p class="settings-hint">
        Permanently delete selected data from your collection. You choose what to wipe in the confirmation dialog. This cannot be undone.
      </p>
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
import { ref, onMounted } from 'vue'
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { useEnrichQueue } from '../composables/useEnrichQueue.js'
import { useMarketValueQueue } from '../composables/useMarketValueQueue.js'
import { useSettings } from '../composables/useSettings.js'
import { useTokenSetting } from '../composables/useTokenSetting.js'

defineProps({
  open: { type: Boolean, required: true },
})
const emit = defineEmits(['update:open', 'token-changed', 'tmdb-token-changed', 'rawg-key-changed', 'comicvine-key-changed', 'pricecharting-token-changed', 'collection-wiped', 'share-library', 'share-category'])

const shareCategoryOptions = [
  { value: 'music', label: 'Music' },
  { value: 'film',  label: 'Films' },
  { value: 'book',  label: 'Books' },
  { value: 'game',  label: 'Games' },
  { value: 'comic', label: 'Comics' },
]

const enrich = useEnrichQueue()
const marketQueue = useMarketValueQueue()
const { autoEnrichOnClick, autoFetchMarketRates, marketCurrency } = useSettings()

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
    setTimeout(() => { wipedMessage.value = '' }, 4000)
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
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = res.data.ocs?.data ?? []
    // Music uses Discogs (needs discogsId); games/comics use PriceCharting (looked up by title).
    // discogsId is shared across categories as a generic enrichment id — gate by category=music
    // so TMDB / Open Library ids on films/books don't get treated as Discogs release ids.
    const ids = all
      .filter(i => (i.category === 'music' && i.discogsId) || i.category === 'game' || i.category === 'comic')
      .map(i => i.id)
    if (ids.length > 0) {
      marketQueue.start(ids, marketCurrency.value)
    }
  } catch (e) {
    console.error('Failed to load items for market rate refresh', e)
  }
}

/**
 * Start the enrich queue for every un-enriched item in the given category,
 * or — when category is null — across every category the user has. Shared
 * between the global "Enrich all items" button in General and each
 * per-category button.
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
.pricecharting-helper {
  margin-top: -4px;
  margin-bottom: 6px;
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

.settings-hint a {
  color: var(--color-primary-element);
}

.settings-sub-hint {
  font-size: 0.8em;
  color: var(--color-text-maxcontrast);
  margin: 4px 0 0 28px;
  line-height: 1.4;
}

.settings-field {
  margin-bottom: 12px;
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

.settings-token-row {
  display: flex;
  gap: 8px;
  align-items: center;
}

.settings-token-row input {
  flex: 1;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  font-family: monospace;
}

.settings-token-row input:focus {
  border-color: var(--color-primary-element);
  outline: none;
}

.settings-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.settings-saved {
  font-size: 0.875em;
  color: #4ade80;
}

.settings-enrichment-options {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.settings-enrich-all {
  margin-top: 12px;
}

.settings-share-row {
  margin-top: 12px;
}

.settings-share-categories {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 12px;
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
