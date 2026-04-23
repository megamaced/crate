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
            v-model="comicVineKeyInput"
            :type="showComicVineKey ? 'text' : 'password'"
            :placeholder="hasComicVineKey ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showComicVineKey ? 'Hide API key' : 'Show API key'"
            @click="showComicVineKey = !showComicVineKey"
          >
            {{ showComicVineKey ? 'Hide' : 'Show' }}
          </NcButton>
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="savingComicVine || comicVineKeyInput === ''"
          @click="saveComicVineKey"
        >
          {{ savingComicVine ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="hasComicVineKey"
          variant="tertiary"
          :disabled="savingComicVine"
          @click="clearComicVineKey"
        >
          Remove
        </NcButton>
        <span
          v-if="comicVineSavedMessage"
          class="settings-saved"
        >{{ comicVineSavedMessage }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!hasComicVineKey || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('comic')"
        >
          Enrich all un-enriched comics
        </NcButton>
        <span
          v-if="!hasComicVineKey"
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
            v-model="tmdbTokenInput"
            :type="showTmdbToken ? 'text' : 'password'"
            :placeholder="hasTmdbToken ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showTmdbToken ? 'Hide API key' : 'Show API key'"
            @click="showTmdbToken = !showTmdbToken"
          >
            {{ showTmdbToken ? 'Hide' : 'Show' }}
          </NcButton>
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="savingTmdb || tmdbTokenInput === ''"
          @click="saveTmdbToken"
        >
          {{ savingTmdb ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="hasTmdbToken"
          variant="tertiary"
          :disabled="savingTmdb"
          @click="clearTmdbToken"
        >
          Remove
        </NcButton>
        <span
          v-if="tmdbSavedMessage"
          class="settings-saved"
        >{{ tmdbSavedMessage }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!hasTmdbToken || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('film')"
        >
          Enrich all un-enriched films
        </NcButton>
        <span
          v-if="!hasTmdbToken"
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
            v-model="rawgKeyInput"
            :type="showRawgKey ? 'text' : 'password'"
            :placeholder="hasRawgKey ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showRawgKey ? 'Hide API key' : 'Show API key'"
            @click="showRawgKey = !showRawgKey"
          >
            {{ showRawgKey ? 'Hide' : 'Show' }}
          </NcButton>
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="savingRawg || rawgKeyInput === ''"
          @click="saveRawgKey"
        >
          {{ savingRawg ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="hasRawgKey"
          variant="tertiary"
          :disabled="savingRawg"
          @click="clearRawgKey"
        >
          Remove
        </NcButton>
        <span
          v-if="rawgSavedMessage"
          class="settings-saved"
        >{{ rawgSavedMessage }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!hasRawgKey || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('game')"
        >
          Enrich all un-enriched games
        </NcButton>
        <span
          v-if="!hasRawgKey"
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
            v-model="tokenInput"
            :type="showToken ? 'text' : 'password'"
            :placeholder="hasToken ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showToken ? 'Hide API key' : 'Show API key'"
            @click="showToken = !showToken"
          >
            {{ showToken ? 'Hide' : 'Show' }}
          </NcButton>
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="saving || tokenInput === ''"
          @click="save"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="hasToken"
          variant="tertiary"
          :disabled="saving"
          @click="clearToken"
        >
          Remove
        </NcButton>
        <span
          v-if="savedMessage"
          class="settings-saved"
        >{{ savedMessage }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="!hasToken || enrich.running.value || marketQueue.running.value"
          @click="enrichAll('music')"
        >
          Enrich all un-enriched music
        </NcButton>
        <span
          v-if="!hasToken"
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
          API access is a paid subscription &mdash; see
          <a
            href="https://www.pricecharting.com/api"
            target="_blank"
            rel="noopener"
          >pricecharting.com/api</a> for pricing.
        </p>
        <div class="settings-token-row">
          <input
            id="pricecharting-token"
            v-model="priceChartingTokenInput"
            :type="showPriceChartingToken ? 'text' : 'password'"
            :placeholder="hasPriceChartingToken ? '(saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showPriceChartingToken ? 'Hide API key' : 'Show API key'"
            @click="showPriceChartingToken = !showPriceChartingToken"
          >
            {{ showPriceChartingToken ? 'Hide' : 'Show' }}
          </NcButton>
        </div>
      </div>

      <div class="settings-actions">
        <NcButton
          variant="primary"
          :disabled="savingPriceCharting || priceChartingTokenInput === ''"
          @click="savePriceChartingToken"
        >
          {{ savingPriceCharting ? 'Saving…' : 'Save' }}
        </NcButton>
        <NcButton
          v-if="hasPriceChartingToken"
          variant="tertiary"
          :disabled="savingPriceCharting"
          @click="clearPriceChartingToken"
        >
          Remove
        </NcButton>
        <span
          v-if="priceChartingSavedMessage"
          class="settings-saved"
        >{{ priceChartingSavedMessage }}</span>
      </div>

      <div class="settings-actions settings-enrich-all">
        <NcButton
          variant="secondary"
          :disabled="(!hasToken && !hasPriceChartingToken) || marketQueue.running.value || enrich.running.value"
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
          v-if="!hasToken && !hasPriceChartingToken"
          class="settings-hint"
          style="margin:0"
        >Add a Discogs or PriceCharting API key to enable market rates.</span>
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

defineProps({
  open: { type: Boolean, required: true },
})
const emit = defineEmits(['update:open', 'token-changed', 'tmdb-token-changed', 'rawg-key-changed', 'comicvine-key-changed', 'pricecharting-token-changed', 'collection-wiped'])

const enrich = useEnrichQueue()
const marketQueue = useMarketValueQueue()
const { autoEnrichOnClick, autoFetchMarketRates, marketCurrency } = useSettings()

const currencies = ref([])

// Discogs (Music)
const tokenInput = ref('')
const hasToken = ref(false)
const showToken = ref(false)
const saving = ref(false)
const savedMessage = ref('')

// TMDB (Films)
const tmdbTokenInput = ref('')
const hasTmdbToken = ref(false)
const showTmdbToken = ref(false)
const savingTmdb = ref(false)
const tmdbSavedMessage = ref('')

// RAWG (Games)
const rawgKeyInput = ref('')
const hasRawgKey = ref(false)
const showRawgKey = ref(false)
const savingRawg = ref(false)
const rawgSavedMessage = ref('')

// ComicVine (Comics)
const comicVineKeyInput = ref('')
const hasComicVineKey = ref(false)
const showComicVineKey = ref(false)
const savingComicVine = ref(false)
const comicVineSavedMessage = ref('')

// PriceCharting (Market Values)
const priceChartingTokenInput = ref('')
const hasPriceChartingToken = ref(false)
const showPriceChartingToken = ref(false)
const savingPriceCharting = ref(false)
const priceChartingSavedMessage = ref('')

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
    const [tokenRes, currRes, tmdbRes, rawgRes, cvRes, pcRes] = await Promise.all([
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token')),
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/currencies')),
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/tmdb-token')),
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/rawg-key')),
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/comicvine-key')),
      axios.get(generateOcsUrl('/apps/crate/api/v1/settings/pricecharting-token')),
    ])
    hasToken.value              = tokenRes.data.ocs?.data?.hasToken ?? false
    currencies.value            = currRes.data.ocs?.data ?? []
    hasTmdbToken.value          = tmdbRes.data.ocs?.data?.hasToken ?? false
    hasRawgKey.value            = rawgRes.data.ocs?.data?.hasKey ?? false
    hasComicVineKey.value       = cvRes.data.ocs?.data?.hasKey ?? false
    hasPriceChartingToken.value = pcRes.data.ocs?.data?.hasToken ?? false
    // Populate the inputs with the saved secrets so the Show toggle has
    // something to reveal. The input type is still `password` by default, so
    // the value is masked until the user clicks Show.
    tokenInput.value               = tokenRes.data.ocs?.data?.token ?? ''
    tmdbTokenInput.value           = tmdbRes.data.ocs?.data?.token ?? ''
    rawgKeyInput.value             = rawgRes.data.ocs?.data?.key ?? ''
    comicVineKeyInput.value        = cvRes.data.ocs?.data?.key ?? ''
    priceChartingTokenInput.value  = pcRes.data.ocs?.data?.token ?? ''
  } catch (e) {
    console.error('Failed to load settings', e)
    showError('Failed to load settings')
  }
}

async function save() {
  saving.value = true
  savedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'), { token: tokenInput.value })
    hasToken.value = tokenInput.value !== ''
    emit('token-changed', hasToken.value)
    savedMessage.value = 'Saved!'
    setTimeout(() => { savedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save token', e)
    showError('Failed to save Discogs token')
    savedMessage.value = 'Failed to save.'
  } finally {
    saving.value = false
  }
}

async function clearToken() {
  saving.value = true
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'), { token: '' })
    hasToken.value = false
    tokenInput.value = ''
    emit('token-changed', false)
    savedMessage.value = 'Token removed.'
    setTimeout(() => { savedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear token', e)
    showError('Failed to clear Discogs token')
  } finally {
    saving.value = false
  }
}

async function saveTmdbToken() {
  savingTmdb.value = true
  tmdbSavedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/tmdb-token'), { token: tmdbTokenInput.value })
    hasTmdbToken.value = tmdbTokenInput.value !== ''
    emit('tmdb-token-changed', hasTmdbToken.value)
    tmdbSavedMessage.value = 'Saved!'
    setTimeout(() => { tmdbSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save TMDB token', e)
    showError('Failed to save TMDB token')
    tmdbSavedMessage.value = 'Failed to save.'
  } finally {
    savingTmdb.value = false
  }
}

async function clearTmdbToken() {
  savingTmdb.value = true
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/tmdb-token'), { token: '' })
    hasTmdbToken.value = false
    tmdbTokenInput.value = ''
    emit('tmdb-token-changed', false)
    tmdbSavedMessage.value = 'Token removed.'
    setTimeout(() => { tmdbSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear TMDB token', e)
    showError('Failed to clear TMDB token')
  } finally {
    savingTmdb.value = false
  }
}

async function saveRawgKey() {
  savingRawg.value = true
  rawgSavedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/rawg-key'), { key: rawgKeyInput.value })
    hasRawgKey.value = rawgKeyInput.value !== ''
    emit('rawg-key-changed', hasRawgKey.value)
    rawgSavedMessage.value = 'Saved!'
    setTimeout(() => { rawgSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save RAWG key', e)
    showError('Failed to save RAWG key')
    rawgSavedMessage.value = 'Failed to save.'
  } finally {
    savingRawg.value = false
  }
}

async function clearRawgKey() {
  savingRawg.value = true
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/rawg-key'), { key: '' })
    hasRawgKey.value = false
    rawgKeyInput.value = ''
    emit('rawg-key-changed', false)
    rawgSavedMessage.value = 'Key removed.'
    setTimeout(() => { rawgSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear RAWG key', e)
    showError('Failed to clear RAWG key')
  } finally {
    savingRawg.value = false
  }
}

async function saveComicVineKey() {
  savingComicVine.value = true
  comicVineSavedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/comicvine-key'), { key: comicVineKeyInput.value })
    hasComicVineKey.value = comicVineKeyInput.value !== ''
    emit('comicvine-key-changed', hasComicVineKey.value)
    comicVineSavedMessage.value = 'Saved!'
    setTimeout(() => { comicVineSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save ComicVine key', e)
    showError('Failed to save ComicVine key')
    comicVineSavedMessage.value = 'Failed to save.'
  } finally {
    savingComicVine.value = false
  }
}

async function clearComicVineKey() {
  savingComicVine.value = true
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/comicvine-key'), { key: '' })
    hasComicVineKey.value = false
    comicVineKeyInput.value = ''
    emit('comicvine-key-changed', false)
    comicVineSavedMessage.value = 'Key removed.'
    setTimeout(() => { comicVineSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear ComicVine key', e)
    showError('Failed to clear ComicVine key')
  } finally {
    savingComicVine.value = false
  }
}

async function savePriceChartingToken() {
  savingPriceCharting.value = true
  priceChartingSavedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/pricecharting-token'), { token: priceChartingTokenInput.value })
    hasPriceChartingToken.value = priceChartingTokenInput.value !== ''
    emit('pricecharting-token-changed', hasPriceChartingToken.value)
    priceChartingSavedMessage.value = 'Saved!'
    setTimeout(() => { priceChartingSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to save PriceCharting token', e)
    showError('Failed to save PriceCharting token')
    priceChartingSavedMessage.value = 'Failed to save.'
  } finally {
    savingPriceCharting.value = false
  }
}

async function clearPriceChartingToken() {
  savingPriceCharting.value = true
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/pricecharting-token'), { token: '' })
    hasPriceChartingToken.value = false
    priceChartingTokenInput.value = ''
    emit('pricecharting-token-changed', false)
    priceChartingSavedMessage.value = 'Token removed.'
    setTimeout(() => { priceChartingSavedMessage.value = '' }, 3000)
  } catch (e) {
    console.error('Failed to clear PriceCharting token', e)
    showError('Failed to clear PriceCharting token')
  } finally {
    savingPriceCharting.value = false
  }
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
    // Music uses Discogs (needs discogsId); games/comics use PriceCharting (looked up by title)
    const ids = all
      .filter(i => i.discogsId || i.category === 'game' || i.category === 'comic')
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
