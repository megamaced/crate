/**
 * Module-level singleton so settings state is shared across all components
 * without needing a store. Loads from the server on first use and persists
 * changes both to localStorage (instant) and the Nextcloud backend (roaming).
 */
import { ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { readBool, readString, safeSet } from '../utils/localStore.js'

const KEY_ENRICH_ON_CLICK  = 'crate_auto_enrich_click'
const KEY_ENRICH_ON_IMPORT = 'crate_auto_enrich_import'
const KEY_AUTO_MARKET      = 'crate_auto_fetch_market_rates'
const KEY_MARKET_CURRENCY  = 'crate_market_currency'
const KEY_HIDDEN_CATS      = 'crate_hidden_categories'
const KEY_ONLINE_RECS      = 'crate_online_recommendations'

const ALL_CATEGORIES = ['music', 'film', 'book', 'game', 'comic']

function readStringList(key) {
  const raw = readString(key)
  if (!raw) return []
  try {
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.filter(v => ALL_CATEGORIES.includes(v)) : []
  } catch {
    return []
  }
}

const autoEnrichOnClick      = ref(readBool(KEY_ENRICH_ON_CLICK, true))
const autoEnrichOnImport     = ref(readBool(KEY_ENRICH_ON_IMPORT, true))
const autoFetchMarketRates   = ref(readBool(KEY_AUTO_MARKET, false))
const marketCurrency         = ref(readString(KEY_MARKET_CURRENCY, 'GBP'))
const hiddenCategories       = ref(readStringList(KEY_HIDDEN_CATS))
// Off by default: when on, opening an item may call out to the provider that
// enriched it, so it stays the user's explicit choice.
const onlineRecommendations  = ref(readBool(KEY_ONLINE_RECS, false))
// Currency allowlist served by the backend (`MarketValueService::SUPPORTED_CURRENCIES`).
// Kept here rather than duplicated per-component so the list can't drift, and
// fetched once per page load (cached for the rest of the session).
const currencyOptions        = ref([])

// Suppress watcher-driven server writes while we're applying values from
// the server. Without this every page load echoes the just-loaded values
// back to the server.
let suppressPersist = false

/**
 * Apply server-supplied values without echoing them straight back. The flag
 * is cleared a microtask later so the watchers below have already seen (and
 * skipped) the new values.
 */
function withoutPersisting(apply) {
  suppressPersist = true
  try {
    apply()
  } finally {
    queueMicrotask(() => { suppressPersist = false })
  }
}

/**
 * Each loader memoises its in-flight *promise*, not a "loaded" boolean. Five
 * components call useSettings() in the same tick on first paint, and a boolean
 * that is only set after the await lets all five fire the same request. The
 * promise is dropped again on failure so a later mount can retry.
 */
let marketPromise = null
function loadFromServer() {
  if (!marketPromise) {
    marketPromise = axios.get(generateOcsUrl('/apps/crate/api/v1/settings/market'))
      .then(res => {
        const data = res.data.ocs?.data ?? {}
        withoutPersisting(() => {
          if (data.autoEnrichOnClick !== undefined) autoEnrichOnClick.value = !!data.autoEnrichOnClick
          if (data.autoEnrichOnImport !== undefined) autoEnrichOnImport.value = !!data.autoEnrichOnImport
          if (data.autoFetchMarketRates !== undefined) autoFetchMarketRates.value = !!data.autoFetchMarketRates
          if (data.marketCurrency) marketCurrency.value = data.marketCurrency
        })
      })
      .catch(() => {
        // Fall back to localStorage values — non-critical.
        marketPromise = null
      })
  }
  return marketPromise
}

let saveTimer = null
function persistToServer() {
  if (suppressPersist) return
  // Debounce: wait 500ms of inactivity before posting
  clearTimeout(saveTimer)
  saveTimer = setTimeout(async () => {
    try {
      await axios.post(generateOcsUrl('/apps/crate/api/v1/settings/market'), {
        autoFetchMarketRates: autoFetchMarketRates.value,
        marketCurrency: marketCurrency.value,
        autoEnrichOnClick: autoEnrichOnClick.value,
        autoEnrichOnImport: autoEnrichOnImport.value,
      })
    } catch {
      // Best-effort — localStorage still has the value
    }
  }, 500)
}

// Persist to localStorage immediately, debounce server sync
watch(autoEnrichOnClick, v => { safeSet(KEY_ENRICH_ON_CLICK, String(v)); persistToServer() })
watch(autoEnrichOnImport, v => { safeSet(KEY_ENRICH_ON_IMPORT, String(v)); persistToServer() })
watch(autoFetchMarketRates, v => { safeSet(KEY_AUTO_MARKET, String(v)); persistToServer() })
watch(marketCurrency, v => { safeSet(KEY_MARKET_CURRENCY, v); persistToServer() })
watch(hiddenCategories, v => {
  safeSet(KEY_HIDDEN_CATS, JSON.stringify(v))
  persistHiddenCategories()
}, { deep: true })

watch(onlineRecommendations, v => {
  safeSet(KEY_ONLINE_RECS, String(v))
  persistOnlineRecs()
})

let onlineRecsSaveTimer = null
function persistOnlineRecs() {
  if (suppressPersist) return
  clearTimeout(onlineRecsSaveTimer)
  onlineRecsSaveTimer = setTimeout(async () => {
    try {
      await axios.put(
        generateOcsUrl('/apps/crate/api/v1/settings/online-recommendations'),
        { enabled: onlineRecommendations.value },
      )
    } catch {
      // Best-effort — localStorage still has the value
    }
  }, 500)
}

let hiddenSaveTimer = null
function persistHiddenCategories() {
  if (suppressPersist) return
  // Block any state that would hide every category — the server will reject
  // this too, but stopping it client-side avoids a wasted round-trip and
  // keeps the UI in sync with the rule.
  if (hiddenCategories.value.length >= ALL_CATEGORIES.length) return
  clearTimeout(hiddenSaveTimer)
  hiddenSaveTimer = setTimeout(async () => {
    try {
      await axios.put(
        generateOcsUrl('/apps/crate/api/v1/settings/hidden-categories'),
        { categories: hiddenCategories.value },
      )
    } catch {
      // Best-effort — localStorage still has the value
    }
  }, 500)
}

let hiddenPromise = null
function loadHiddenCategoriesFromMe() {
  if (!hiddenPromise) {
    hiddenPromise = axios.get(generateOcsUrl('/apps/crate/api/v1/me'))
      .then(res => {
        const data = res.data.ocs?.data ?? {}
        // A server without these keys is a successful answer, not a reason to
        // ask again on every subsequent mount — the local values stand.
        withoutPersisting(() => {
          if (Array.isArray(data.hiddenCategories)) {
            hiddenCategories.value = data.hiddenCategories.filter(v => ALL_CATEGORIES.includes(v))
          }
          if (typeof data.onlineRecommendations === 'boolean') {
            onlineRecommendations.value = data.onlineRecommendations
          }
        })
      })
      .catch(() => {
        // Stay on local value.
        hiddenPromise = null
      })
  }
  return hiddenPromise
}

let currenciesPromise = null
function loadCurrencies() {
  if (!currenciesPromise) {
    currenciesPromise = axios.get(generateOcsUrl('/apps/crate/api/v1/settings/currencies'))
      .then(res => {
        const list = res.data.ocs?.data
        if (Array.isArray(list) && list.length > 0) {
          currencyOptions.value = list
        }
      })
      .catch(() => {
        // Caller falls back to whatever the marketCurrency is — non-critical.
        currenciesPromise = null
      })
  }
  return currenciesPromise
}

export function useSettings() {
  loadFromServer()
  loadCurrencies()
  loadHiddenCategoriesFromMe()
  return {
    autoEnrichOnClick,
    autoEnrichOnImport,
    autoFetchMarketRates,
    marketCurrency,
    currencyOptions,
    hiddenCategories,
    onlineRecommendations,
  }
}
