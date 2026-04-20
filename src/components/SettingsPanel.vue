<template>
  <NcAppSettingsDialog
    :open="open"
    :show-navigation="false"
    name="Crate settings"
    @update:open="$emit('update:open', $event)"
  >
    <NcAppSettingsSection
      id="crate-settings-enrich"
      name="Enrichment"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://www.discogs.com/developers/"
          target="_blank"
          rel="noopener"
        >Discogs API</a>
        to fetch album metadata, artwork, tracklists, genres and artist info.
        Enter your personal access token below — you can generate one at
        <a
          href="https://www.discogs.com/settings/developers"
          target="_blank"
          rel="noopener"
        >discogs.com/settings/developers</a>.
      </p>

      <div class="settings-field">
        <label for="discogs-token">Personal access token</label>
        <div class="settings-token-row">
          <input
            id="discogs-token"
            v-model="tokenInput"
            :type="showToken ? 'text' : 'password'"
            :placeholder="hasToken ? '(token saved — paste a new one to replace)' : 'Paste your token here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showToken ? 'Hide token' : 'Show token'"
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
          {{ saving ? 'Saving…' : 'Save token' }}
        </NcButton>
        <NcButton
          v-if="hasToken"
          variant="tertiary"
          :disabled="saving"
          @click="clearToken"
        >
          Remove token
        </NcButton>
        <span
          v-if="savedMessage"
          class="settings-saved"
        >{{ savedMessage }}</span>
      </div>

      <div class="settings-enrichment-options">
        <NcCheckboxRadioSwitch
          v-model="autoEnrichOnClick"
          :disabled="!hasToken"
        >
          Auto-enrich albums when clicking on them
        </NcCheckboxRadioSwitch>

        <div class="settings-actions settings-enrich-all">
          <NcButton
            variant="secondary"
            :disabled="!hasToken || enrich.running.value || marketQueue.running.value"
            @click="enrichAll"
          >
            {{ enrich.running.value ? `Enriching… ${enrich.done.value} / ${enrich.total.value}` : 'Enrich all un-enriched items' }}
          </NcButton>
          <NcButton
            v-if="enrich.running.value"
            variant="tertiary"
            @click="enrich.cancel()"
          >
            Stop
          </NcButton>
          <span
            v-if="!hasToken"
            class="settings-hint"
            style="margin:0"
          >Add a Discogs token above to enable enrichment.</span>
        </div>
      </div>
    </NcAppSettingsSection>

    <!-- ── TMDB (Films) ── -->
    <NcAppSettingsSection
      id="crate-settings-tmdb"
      name="Films — TMDB"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://www.themoviedb.org/"
          target="_blank"
          rel="noopener"
        >TMDB API</a>
        to fetch film metadata, posters, and director info.
        Enter your API Read Access Token — generate one at
        <a
          href="https://www.themoviedb.org/settings/api"
          target="_blank"
          rel="noopener"
        >themoviedb.org/settings/api</a>.
      </p>

      <div class="settings-field">
        <label for="tmdb-token">API Read Access Token</label>
        <div class="settings-token-row">
          <input
            id="tmdb-token"
            v-model="tmdbTokenInput"
            :type="showTmdbToken ? 'text' : 'password'"
            :placeholder="hasTmdbToken ? '(token saved — paste a new one to replace)' : 'Paste your token here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showTmdbToken ? 'Hide token' : 'Show token'"
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
          {{ savingTmdb ? 'Saving…' : 'Save token' }}
        </NcButton>
        <NcButton
          v-if="hasTmdbToken"
          variant="tertiary"
          :disabled="savingTmdb"
          @click="clearTmdbToken"
        >
          Remove token
        </NcButton>
        <span
          v-if="tmdbSavedMessage"
          class="settings-saved"
        >{{ tmdbSavedMessage }}</span>
      </div>
    </NcAppSettingsSection>

    <!-- ── RAWG (Games) ── -->
    <NcAppSettingsSection
      id="crate-settings-rawg"
      name="Games — RAWG"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://rawg.io/"
          target="_blank"
          rel="noopener"
        >RAWG API</a>
        to fetch game metadata and cover art.
        Get a free API key at
        <a
          href="https://rawg.io/apidocs"
          target="_blank"
          rel="noopener"
        >rawg.io/apidocs</a>.
      </p>

      <div class="settings-field">
        <label for="rawg-key">API Key</label>
        <div class="settings-token-row">
          <input
            id="rawg-key"
            v-model="rawgKeyInput"
            :type="showRawgKey ? 'text' : 'password'"
            :placeholder="hasRawgKey ? '(key saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showRawgKey ? 'Hide key' : 'Show key'"
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
          {{ savingRawg ? 'Saving…' : 'Save key' }}
        </NcButton>
        <NcButton
          v-if="hasRawgKey"
          variant="tertiary"
          :disabled="savingRawg"
          @click="clearRawgKey"
        >
          Remove key
        </NcButton>
        <span
          v-if="rawgSavedMessage"
          class="settings-saved"
        >{{ rawgSavedMessage }}</span>
      </div>
    </NcAppSettingsSection>

    <!-- ── ComicVine (Comics) ── -->
    <NcAppSettingsSection
      id="crate-settings-comicvine"
      name="Comics — ComicVine"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://comicvine.gamespot.com/api/"
          target="_blank"
          rel="noopener"
        >ComicVine API</a>
        to fetch comic volume metadata, artwork, genres and descriptions.
        Get a free API key at
        <a
          href="https://comicvine.gamespot.com/api/"
          target="_blank"
          rel="noopener"
        >comicvine.gamespot.com/api</a>.
      </p>

      <div class="settings-field">
        <label for="comicvine-key">API Key</label>
        <div class="settings-token-row">
          <input
            id="comicvine-key"
            v-model="comicVineKeyInput"
            :type="showComicVineKey ? 'text' : 'password'"
            :placeholder="hasComicVineKey ? '(key saved — paste a new one to replace)' : 'Paste your API key here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showComicVineKey ? 'Hide key' : 'Show key'"
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
          {{ savingComicVine ? 'Saving…' : 'Save key' }}
        </NcButton>
        <NcButton
          v-if="hasComicVineKey"
          variant="tertiary"
          :disabled="savingComicVine"
          @click="clearComicVineKey"
        >
          Remove key
        </NcButton>
        <span
          v-if="comicVineSavedMessage"
          class="settings-saved"
        >{{ comicVineSavedMessage }}</span>
      </div>
    </NcAppSettingsSection>

    <!-- ── PriceCharting (Games & Comics) ── -->
    <NcAppSettingsSection
      id="crate-settings-pricecharting"
      name="Games &amp; Comics — PriceCharting"
    >
      <p class="settings-hint">
        Crate uses the
        <a
          href="https://www.pricecharting.com/"
          target="_blank"
          rel="noopener"
        >PriceCharting API</a>
        to fetch loose, CIB, and new market prices for games and comics.
        Get a free access token at
        <a
          href="https://www.pricecharting.com/api"
          target="_blank"
          rel="noopener"
        >pricecharting.com/api</a>.
      </p>

      <div class="settings-field">
        <label for="pricecharting-token">Access Token</label>
        <div class="settings-token-row">
          <input
            id="pricecharting-token"
            v-model="priceChartingTokenInput"
            :type="showPriceChartingToken ? 'text' : 'password'"
            :placeholder="hasPriceChartingToken ? '(token saved — paste a new one to replace)' : 'Paste your token here'"
            autocomplete="off"
          >
          <NcButton
            variant="tertiary"
            :aria-label="showPriceChartingToken ? 'Hide token' : 'Show token'"
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
          {{ savingPriceCharting ? 'Saving…' : 'Save token' }}
        </NcButton>
        <NcButton
          v-if="hasPriceChartingToken"
          variant="tertiary"
          :disabled="savingPriceCharting"
          @click="clearPriceChartingToken"
        >
          Remove token
        </NcButton>
        <span
          v-if="priceChartingSavedMessage"
          class="settings-saved"
        >{{ priceChartingSavedMessage }}</span>
      </div>
    </NcAppSettingsSection>

    <NcAppSettingsSection
      id="crate-settings-market"
      name="Market values"
    >
      <p class="settings-hint">
        Fetches the current lowest Discogs marketplace price for each album.
        Prices are cached on the item and shown in the collection, album detail, and home page.
      </p>

      <div class="settings-enrichment-options">
        <NcCheckboxRadioSwitch
          v-model="autoFetchMarketRates"
          :disabled="!hasToken"
        >
          Fetch market rates automatically
        </NcCheckboxRadioSwitch>

        <div class="settings-field">
          <label for="market-currency">Currency</label>
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
        </div>

        <div class="settings-actions settings-enrich-all">
          <NcButton
            variant="secondary"
            :disabled="!hasToken || marketQueue.running.value || enrich.running.value"
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
            v-if="!hasToken"
            class="settings-hint"
            style="margin:0"
          >Add a Discogs token above to enable market rates.</span>
        </div>
      </div>
    </NcAppSettingsSection>

    <NcAppSettingsSection
      id="crate-settings-danger"
      name="Danger zone"
    >
      <p class="settings-hint">
        Permanently delete every item in your collection and wishlist, all playlists, and any shares you have created. This cannot be undone.
      </p>
      <div class="settings-actions">
        <NcButton
          variant="error"
          :disabled="wiping"
          @click="confirmWipe = true"
        >
          {{ wiping ? 'Wiping…' : 'Wipe collection' }}
        </NcButton>
        <span
          v-if="wipedMessage"
          class="settings-saved"
        >{{ wipedMessage }}</span>
      </div>

      <NcDialog
        v-if="confirmWipe"
        name="Wipe collection"
        :open="confirmWipe"
        @closing="confirmWipe = false"
      >
        <p>This will permanently delete <strong>all items</strong> from your collection and wishlist, along with <strong>all playlists</strong> and <strong>shares you have created</strong>. There is no undo.</p>
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
            @click="wipeCollection"
          >
            Yes, wipe everything
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

const tokenInput = ref('')
const hasToken = ref(false)
const showToken = ref(false)
const saving = ref(false)
const savedMessage = ref('')

// TMDB
const tmdbTokenInput = ref('')
const hasTmdbToken = ref(false)
const showTmdbToken = ref(false)
const savingTmdb = ref(false)
const tmdbSavedMessage = ref('')

// RAWG
const rawgKeyInput = ref('')
const hasRawgKey = ref(false)
const showRawgKey = ref(false)
const savingRawg = ref(false)
const rawgSavedMessage = ref('')

// ComicVine
const comicVineKeyInput = ref('')
const hasComicVineKey = ref(false)
const showComicVineKey = ref(false)
const savingComicVine = ref(false)
const comicVineSavedMessage = ref('')

// PriceCharting
const priceChartingTokenInput = ref('')
const hasPriceChartingToken = ref(false)
const showPriceChartingToken = ref(false)
const savingPriceCharting = ref(false)
const priceChartingSavedMessage = ref('')

const confirmWipe = ref(false)
const wiping = ref(false)
const wipedMessage = ref('')

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
  } catch (e) {
    console.error('Failed to load settings', e)
    showError('Failed to load settings')
  }
}

async function save() {
  saving.value = true
  savedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/discogs-token'), {
      token: tokenInput.value,
    })
    hasToken.value = tokenInput.value !== ''
    emit('token-changed', hasToken.value)
    tokenInput.value = ''
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

async function wipeCollection() {
  confirmWipe.value = false
  wiping.value = true
  wipedMessage.value = ''
  try {
    await axios.delete(generateOcsUrl('/apps/crate/api/v1/media'))
    wipedMessage.value = 'Collection wiped.'
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

async function saveTmdbToken() {
  savingTmdb.value = true
  tmdbSavedMessage.value = ''
  try {
    await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/tmdb-token'), { token: tmdbTokenInput.value })
    hasTmdbToken.value = tmdbTokenInput.value !== ''
    emit('tmdb-token-changed', hasTmdbToken.value)
    tmdbTokenInput.value = ''
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
    rawgKeyInput.value = ''
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
    comicVineKeyInput.value = ''
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
    priceChartingTokenInput.value = ''
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

onMounted(load)

async function refreshAllMarketRates() {
  if (marketQueue.running.value) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
    const all = res.data.ocs?.data ?? []
    const ids = all.filter(item => item.discogsId).map(item => item.id)
    if (ids.length > 0) {
      marketQueue.start(ids, marketCurrency.value)
    }
  } catch (e) {
    console.error('Failed to load items for market rate refresh', e)
  }
}

async function enrichAll() {
  if (enrich.running.value) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/media'))
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
  }
}
</script>

<style scoped>
.settings-hint {
  font-size: 0.875em;
  color: var(--color-text-maxcontrast);
  margin-bottom: 16px;
  line-height: 1.5;
}

.settings-hint a {
  color: var(--color-primary-element);
}

.settings-field {
  margin-bottom: 12px;
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
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.settings-enrich-all {
  margin-top: 4px;
}

.settings-currency-select {
  display: block;
  margin-top: 6px;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  padding: 6px 10px;
  font-size: 1em;
  min-width: 120px;
}

.settings-currency-select:focus {
  border-color: var(--color-primary-element);
  outline: none;
}
</style>
