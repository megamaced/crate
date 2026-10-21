<template>
  <!-- show-navigation gives the standard Nextcloud two-column settings layout:
       a section list on the left, the sections themselves scrolling on the
       right. The component drops the rail by itself on narrow viewports, so
       the same markup covers mobile. Section order here is the order the rail
       shows, and matches the Android app's settings screen. -->
  <NcAppSettingsDialog
    :open="open"
    show-navigation
    :name="t('crate', 'Crate settings')"
    @update:open="$emit('update:open', $event)"
  >
    <!-- ── Categories ── -->
    <NcAppSettingsSection
      id="crate-settings-categories"
      :name="t('crate', 'Categories')"
      :description="t('crate', 'Hide categories you don\'t use. Hidden categories disappear from the sidebar and the Home view, and won\'t show up in search. At least one category must remain visible.')"
    >
      <div class="settings-stack">
        <NcCheckboxRadioSwitch
          v-for="cat in categoryToggles"
          :key="cat"
          :model-value="!hiddenCategories.includes(cat)"
          :disabled="!hiddenCategories.includes(cat) && hiddenCategories.length >= categoryToggles.length - 1"
          @update:model-value="setCategoryVisible(cat, $event)"
        >
          {{ categoryLabel(cat) }}
        </NcCheckboxRadioSwitch>
      </div>
    </NcAppSettingsSection>

    <!-- ── Enrichment ── -->
    <NcAppSettingsSection
      id="crate-settings-enrichment"
      :name="t('crate', 'Enrichment')"
      :description="t('crate', 'Fill items in with metadata, artwork and descriptions from each category\'s provider. Every provider except Open Library needs a key, set under API keys.')"
    >
      <div class="settings-group">
        <h4 class="settings-group__label">
          <!-- TRANSLATORS: heading over the setting that enriches items without being asked -->
          {{ t('crate', 'Automatic') }}
        </h4>
        <NcCheckboxRadioSwitch v-model="autoEnrichOnClick">
          {{ t('crate', 'Auto-enrich items when opening them') }}
        </NcCheckboxRadioSwitch>
        <p class="settings-sub-hint">
          {{ t('crate', 'Applies to Music (Discogs), Films (TMDB), Books (Open Library — no key needed), Games (RAWG), and Comics (ComicVine). Requires the relevant API key to be configured.') }}
        </p>
      </div>

      <div class="settings-group">
        <h4 class="settings-group__label">
          {{ t('crate', 'Everything at once') }}
        </h4>
        <div class="settings-actions">
          <NcButton
            variant="secondary"
            :disabled="enrich.running.value || marketQueue.running.value"
            @click="enrichAll()"
          >
            {{ enrich.running.value
              ? t('crate', 'Enriching… {done} / {total}', { done: enrich.done.value, total: enrich.total.value })
              : t('crate', 'Enrich all items (every category)') }}
          </NcButton>
          <NcButton
            v-if="enrich.running.value"
            variant="tertiary"
            @click="enrich.cancel()"
          >
            {{ t('crate', 'Stop') }}
          </NcButton>
        </div>
        <p class="settings-hint settings-hint--tight">
          {{ t('crate', 'Enriches every un-enriched item across every category, using whichever API keys you have configured.') }}
        </p>
      </div>

      <div class="settings-group">
        <h4 class="settings-group__label">
          {{ t('crate', 'By category') }}
        </h4>
        <div class="settings-stack settings-stack--wide">
          <EnrichProviderRow
            :label="categoryLabel('book')"
            :action-label="t('crate', 'Enrich all un-enriched books')"
            :disabled="enrich.running.value || marketQueue.running.value"
            @enrich="enrichAll('book')"
          >
            {{ providerHelp.book.before }}<a
              href="https://openlibrary.org/developers/api"
              target="_blank"
              rel="noopener"
            >{{ providerHelp.book.inner }}</a>{{ providerHelp.book.after }}
          </EnrichProviderRow>

          <EnrichProviderRow
            :label="categoryLabel('comic')"
            :action-label="t('crate', 'Enrich all un-enriched comics')"
            :disabled="!comicVine.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="comicVine.hasValue.value ? '' : t('crate', 'Add a ComicVine API key under API keys to enable enrichment.')"
            @enrich="enrichAll('comic')"
          >
            {{ providerHelp.comic.before }}<a
              href="https://comicvine.gamespot.com/api/"
              target="_blank"
              rel="noopener"
            >{{ providerHelp.comic.inner }}</a>{{ providerHelp.comic.after }}
          </EnrichProviderRow>

          <EnrichProviderRow
            :label="categoryLabel('film')"
            :action-label="t('crate', 'Enrich all un-enriched films')"
            :disabled="!tmdb.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="tmdb.hasValue.value ? '' : t('crate', 'Add a TMDB API key under API keys to enable enrichment.')"
            @enrich="enrichAll('film')"
          >
            {{ providerHelp.film.before }}<a
              href="https://www.themoviedb.org/"
              target="_blank"
              rel="noopener"
            >{{ providerHelp.film.inner }}</a>{{ providerHelp.film.after }}
          </EnrichProviderRow>

          <EnrichProviderRow
            :label="categoryLabel('game')"
            :action-label="t('crate', 'Enrich all un-enriched games')"
            :disabled="!rawg.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="rawg.hasValue.value ? '' : t('crate', 'Add a RAWG API key under API keys to enable enrichment.')"
            @enrich="enrichAll('game')"
          >
            {{ providerHelp.game.before }}<a
              href="https://rawg.io/"
              target="_blank"
              rel="noopener"
            >{{ providerHelp.game.inner }}</a>{{ providerHelp.game.after }}
          </EnrichProviderRow>

          <EnrichProviderRow
            :label="categoryLabel('music')"
            :action-label="t('crate', 'Enrich all un-enriched music')"
            :disabled="!discogs.hasValue.value || enrich.running.value || marketQueue.running.value"
            :hint="discogs.hasValue.value ? '' : t('crate', 'Add a Discogs API key under API keys to enable enrichment.')"
            @enrich="enrichAll('music')"
          >
            {{ providerHelp.music.before }}<a
              href="https://www.discogs.com/developers/"
              target="_blank"
              rel="noopener"
            >{{ providerHelp.music.inner }}</a>{{ providerHelp.music.after }}
          </EnrichProviderRow>
        </div>
      </div>
    </NcAppSettingsSection>

    <!-- ── Recommendations ── -->
    <NcAppSettingsSection
      id="crate-settings-recommendations"
      :name="t('crate', 'Recommendations')"
      :description="recommendationsDescription"
    >
      <div class="settings-group">
        <h4 class="settings-group__label">
          <!-- TRANSLATORS: heading over the setting that looks up recommendations on online services -->
          {{ t('crate', 'Online') }}
        </h4>
        <NcCheckboxRadioSwitch
          :model-value="onlineRecommendations"
          @update:model-value="onlineRecommendations = $event"
        >
          {{ t('crate', 'Enable online recommendations') }}
        </NcCheckboxRadioSwitch>
        <p class="settings-sub-hint">
          {{ t('crate', 'Also suggest things you don\'t own yet, from the same service that enriched the item — Discogs for music, TMDB for films, Open Library for books, RAWG for games. Only enriched items can have these, since suggestions are looked up by the ID enrichment stores. Results are cached, so re-opening an item won\'t re-query the service. Comics aren\'t covered: ComicVine publishes no similarity data.') }}
        </p>
      </div>
    </NcAppSettingsSection>

    <!-- ── Market values ── -->
    <NcAppSettingsSection
      id="crate-settings-market"
      :name="t('crate', 'Market values')"
    >
      <p class="settings-hint">
        {{ marketSourcesHelp.before }}<a
          href="https://www.pricecharting.com/"
          target="_blank"
          rel="noopener"
        >{{ marketSourcesHelp.inner }}</a>{{ marketSourcesHelp.after }}
      </p>

      <div class="settings-group">
        <h4 class="settings-group__label">
          <!-- TRANSLATORS: heading over the setting for the currency prices are shown in -->
          {{ t('crate', 'Display') }}
        </h4>
        <div class="settings-field settings-field--inline">
          <label for="market-currency">{{ t('crate', 'Display currency') }}</label>
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
            {{ t('crate', 'Used for Discogs (music) prices. PriceCharting (games & comics) prices are always in USD.') }}
          </p>
        </div>
      </div>

      <div class="settings-group">
        <h4 class="settings-group__label">
          <!-- TRANSLATORS: heading over the settings for looking up market prices -->
          {{ t('crate', 'Fetching') }}
        </h4>
        <NcCheckboxRadioSwitch v-model="autoFetchMarketRates">
          {{ t('crate', 'Fetch market rates automatically') }}
        </NcCheckboxRadioSwitch>
        <p class="settings-sub-hint">
          {{ t('crate', 'When enabled, opening an item triggers a live price lookup for the applicable categories.') }}
        </p>

        <div class="settings-actions settings-actions--spaced">
          <NcButton
            variant="secondary"
            :disabled="(!discogs.hasValue.value && !priceCharting.hasValue.value) || marketQueue.running.value || enrich.running.value"
            @click="refreshAllMarketRates"
          >
            {{ marketQueue.running.value
              ? t('crate', 'Fetching… {done} / {total}', { done: marketQueue.done.value, total: marketQueue.total.value })
              : t('crate', 'Refresh all market rates') }}
          </NcButton>
          <NcButton
            v-if="marketQueue.running.value"
            variant="tertiary"
            @click="marketQueue.cancel()"
          >
            {{ t('crate', 'Stop') }}
          </NcButton>
          <span
            v-if="!discogs.hasValue.value && !priceCharting.hasValue.value"
            class="settings-hint settings-hint--flush"
          >{{ t('crate', 'Add a Discogs or PriceCharting API key to enable market rates.') }}</span>
        </div>
      </div>
    </NcAppSettingsSection>

    <!-- ── API keys ──
         Every provider credential in one place, in the same order as the
         Android app. Keys are configured once and then never touched, which is
         why they sit below the sections that use them rather than inside each. -->
    <NcAppSettingsSection
      id="crate-settings-api-keys"
      :name="t('crate', 'API keys')"
      :description="t('crate', 'Keys are stored on your Nextcloud server and are never shown again once saved. Books need no key — Open Library is open.')"
    >
      <TokenField
        v-model="discogs.input.value"
        input-id="discogs-token"
        :label="t('crate', 'Discogs personal access token')"
        :has-value="discogs.hasValue.value"
        :saving="discogs.saving.value"
        :message="discogs.message.value"
        @save="saveDiscogsToken"
        @remove="clearDiscogsToken"
      >
        {{ keyHelp.discogs.before }}<a
          href="https://www.discogs.com/settings/developers"
          target="_blank"
          rel="noopener"
        >{{ keyHelp.discogs.inner }}</a>{{ keyHelp.discogs.after }}
      </TokenField>

      <TokenField
        v-model="tmdb.input.value"
        input-id="tmdb-token"
        :label="t('crate', 'TMDB API Read Access Token')"
        :has-value="tmdb.hasValue.value"
        :saving="tmdb.saving.value"
        :message="tmdb.message.value"
        @save="saveTmdbToken"
        @remove="clearTmdbToken"
      >
        {{ keyHelp.tmdb.before }}<a
          href="https://www.themoviedb.org/settings/api"
          target="_blank"
          rel="noopener"
        >{{ keyHelp.tmdb.inner }}</a>{{ keyHelp.tmdb.after }}
      </TokenField>

      <TokenField
        v-model="rawg.input.value"
        input-id="rawg-key"
        :label="t('crate', 'RAWG API key')"
        :has-value="rawg.hasValue.value"
        :saving="rawg.saving.value"
        :message="rawg.message.value"
        @save="saveRawgKey"
        @remove="clearRawgKey"
      >
        {{ keyHelp.rawg.before }}<a
          href="https://rawg.io/apidocs"
          target="_blank"
          rel="noopener"
        >{{ keyHelp.rawg.inner }}</a>{{ keyHelp.rawg.after }}
      </TokenField>

      <TokenField
        v-model="comicVine.input.value"
        input-id="comicvine-key"
        :label="t('crate', 'ComicVine API key')"
        :has-value="comicVine.hasValue.value"
        :saving="comicVine.saving.value"
        :message="comicVine.message.value"
        @save="saveComicVineKey"
        @remove="clearComicVineKey"
      >
        {{ keyHelp.comicVine.before }}<a
          href="https://comicvine.gamespot.com/api/"
          target="_blank"
          rel="noopener"
        >{{ keyHelp.comicVine.inner }}</a>{{ keyHelp.comicVine.after }}
      </TokenField>

      <TokenField
        v-model="priceCharting.input.value"
        input-id="pricecharting-token"
        :label="t('crate', 'PriceCharting API key')"
        :has-value="priceCharting.hasValue.value"
        :saving="priceCharting.saving.value"
        :message="priceCharting.message.value"
        @save="savePriceChartingToken"
        @remove="clearPriceChartingToken"
      >
        {{ keyHelp.priceCharting.before }}<a
          href="https://www.pricecharting.com/api-documentation"
          target="_blank"
          rel="noopener"
        >{{ keyHelp.priceCharting.inner }}</a>{{ keyHelp.priceCharting.after }}
      </TokenField>
    </NcAppSettingsSection>

    <!-- ── Danger zone ── -->
    <NcAppSettingsSection
      id="crate-settings-danger"
      :name="t('crate', 'Danger zone')"
      :description="t('crate', 'Permanently delete selected data from your collection. You choose what to wipe in the confirmation dialog. This cannot be undone.')"
    >
      <div class="settings-actions">
        <NcButton
          variant="error"
          :disabled="wiping"
          @click="openWipeDialog"
        >
          {{ wiping
            ? t('crate', 'Wiping…')
            : t('crate', 'Wipe data…') }}
        </NcButton>
        <span
          v-if="wipedMessage"
          class="settings-saved"
        >{{ wipedMessage }}</span>
      </div>

      <NcDialog
        v-if="confirmWipe"
        :name="t('crate', 'Wipe data')"
        :open="confirmWipe"
        @closing="confirmWipe = false"
      >
        <p>{{ t('crate', 'Tick the categories you want to permanently delete. There is no undo.') }}</p>
        <div class="wipe-scopes">
          <NcCheckboxRadioSwitch
            v-for="scope in wipeScopes"
            :key="scope"
            :model-value="wipeSelection.includes(scope)"
            @update:model-value="toggleWipeScope(scope, $event)"
          >
            {{ wipeScopeLabel(scope) }}
          </NcCheckboxRadioSwitch>
        </div>
        <template #actions>
          <NcButton
            type="button"
            variant="tertiary"
            @click="confirmWipe = false"
          >
            {{ t('crate', 'Cancel') }}
          </NcButton>
          <NcButton
            type="button"
            variant="error"
            :disabled="wipeSelection.length === 0"
            @click="wipeCollection"
          >
            {{ t('crate', 'Wipe selected') }}
          </NcButton>
        </template>
      </NcDialog>
    </NcAppSettingsSection>
  </NcAppSettingsDialog>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { NcAppSettingsDialog, NcAppSettingsSection, NcButton, NcCheckboxRadioSwitch, NcDialog } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { splitAround, t } from '../l10n.js'
import { showError } from '../utils/toast.js'
import { categoryLabel } from '../utils/categoryFormats.js'
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

// Alphabetical by English label so the Categories list matches Android.
const categoryToggles = ['book', 'comic', 'film', 'game', 'music']

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

/** A translated sentence cut at its {linkStart} and {linkEnd} markers. */
function linkParts(text) {
  return splitAround(text, 'linkStart', 'linkEnd')
}

// The description under each per-category enrich button, around the link to
// the provider's API.
const providerHelp = computed(() => ({
  book: linkParts(t('crate', 'Book metadata, covers and author bios via the {linkStart}Open Library API{linkEnd}. No API key is required.')),
  comic: linkParts(t('crate', 'Comic volume metadata, artwork, genres and descriptions via the {linkStart}ComicVine API{linkEnd}.')),
  film: linkParts(t('crate', 'Film metadata, posters and director info via the {linkStart}TMDB API{linkEnd}.')),
  game: linkParts(t('crate', 'Game metadata and cover art via the {linkStart}RAWG API{linkEnd}.')),
  music: linkParts(t('crate', 'Metadata, artwork, tracklists and artist info via the {linkStart}Discogs API{linkEnd}.')),
}))

const recommendationsDescription = computed(() => {
  const rail = t('crate', 'More from your crate')
  // TRANSLATORS: {rail} is the title of the row of suggestions on an item's page, "More from your crate"
  return t('crate', 'Items show a "{rail}" row, suggesting similar things you already own or want. That\'s worked out locally from your own collection and needs no setup.', { rail })
})

const marketSourcesHelp = computed(() => linkParts(
  t('crate', 'Music market values come from Discogs; game and comic prices come from {linkStart}PriceCharting{linkEnd} (paid API — requires a subscription). Both keys live under API keys. Films and books have no market-value source.'),
))

// The help under each API key field, around the link to where the key is made.
const keyHelp = computed(() => ({
  discogs: linkParts(t('crate', 'Powers music enrichment and music market values. Generate a personal access token at {linkStart}discogs.com/settings/developers{linkEnd}.')),
  tmdb: linkParts(t('crate', 'Powers film enrichment. Generate an API Read Access Token at {linkStart}themoviedb.org/settings/api{linkEnd}.')),
  rawg: linkParts(t('crate', 'Powers game enrichment. Get a free API key at {linkStart}rawg.io/apidocs{linkEnd}.')),
  comicVine: linkParts(t('crate', 'Powers comic enrichment. Get a free API key at {linkStart}comicvine.gamespot.com/api{linkEnd}.')),
  priceCharting: linkParts(t('crate', 'Powers game and comic market values. API access is a paid subscription — see the {linkStart}API documentation{linkEnd} for pricing and details.')),
}))

const currencies = ref([])

// Token settings via composable. `label` names the key in console messages,
// `name` in the messages the user sees.
const discogs = useTokenSetting({
  endpoint: '/settings/discogs-token',
  payloadKey: 'token',
  responseKey: 'hasToken',
  label: 'Discogs token',
  name: () => t('crate', 'Discogs token'),
})
const tmdb = useTokenSetting({
  endpoint: '/settings/tmdb-token',
  payloadKey: 'token',
  responseKey: 'hasToken',
  label: 'TMDB token',
  name: () => t('crate', 'TMDB token'),
})
const rawg = useTokenSetting({
  endpoint: '/settings/rawg-key',
  payloadKey: 'key',
  responseKey: 'hasKey',
  label: 'RAWG key',
  name: () => t('crate', 'RAWG key'),
})
const comicVine = useTokenSetting({
  endpoint: '/settings/comicvine-key',
  payloadKey: 'key',
  responseKey: 'hasKey',
  label: 'ComicVine key',
  name: () => t('crate', 'ComicVine key'),
})
const priceCharting = useTokenSetting({
  endpoint: '/settings/pricecharting-token',
  payloadKey: 'token',
  responseKey: 'hasToken',
  label: 'PriceCharting token',
  name: () => t('crate', 'PriceCharting token'),
})

const confirmWipe = ref(false)
const wiping = ref(false)
const wipedMessage = ref('')
// One handle, so a second wipe's message isn't cleared by the first wipe's
// timer and nothing fires after the panel is gone.
let wipedTimer = null
onBeforeUnmount(() => clearTimeout(wipedTimer))

const wipeScopes = ['music', 'film', 'book', 'game', 'comic', 'playlists']
const wipeSelection = ref([])

function wipeScopeLabel(scope) {
  return scope === 'playlists' ? t('crate', 'Playlists and shares') : categoryLabel(scope)
}

function openWipeDialog() {
  // Default: everything selected.
  wipeSelection.value = [...wipeScopes]
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
    showError(t('crate', 'Failed to load settings'))
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
    const wiped = scopes.map(wipeScopeLabel).join(', ')
    wipedMessage.value = allSelected
      ? t('crate', 'Collection wiped.')
      : t('crate', 'Wiped: {scopes}.', { scopes: wiped })
    emit('collection-wiped')
    clearTimeout(wipedTimer)
    wipedTimer = setTimeout(() => { wipedMessage.value = '' }, 4000)
  } catch (e) {
    console.error('Failed to wipe collection', e)
    showError(t('crate', 'Failed to wipe collection'))
    wipedMessage.value = t('crate', 'Failed — check the console.')
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
    showError(t('crate', 'Failed to start market rate refresh'))
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
    showError(t('crate', 'Failed to start enrichment'))
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
