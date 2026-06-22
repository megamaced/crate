/**
 * Module-level singleton so settings state is shared across all components
 * without needing a store. Loads from the server on first use and persists
 * changes both to localStorage (instant) and the Nextcloud backend (roaming).
 */
import { ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const KEY_ENRICH_ON_CLICK  = 'crate_auto_enrich_click'
const KEY_ENRICH_ON_IMPORT = 'crate_auto_enrich_import'
const KEY_AUTO_MARKET      = 'crate_auto_fetch_market_rates'
const KEY_MARKET_CURRENCY  = 'crate_market_currency'
const KEY_HIDDEN_CATS      = 'crate_hidden_categories'

const ALL_CATEGORIES = ['music', 'film', 'book', 'game', 'comic']

function readStringList(key) {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.filter(v => ALL_CATEGORIES.includes(v)) : []
  } catch {
    return []
  }
}

function readBool(key, defaultValue) {
  try {
    const val = localStorage.getItem(key)
    if (val === null) return defaultValue
    return val === 'true'
  } catch {
    return defaultValue
  }
}

function readString(key, defaultValue) {
  try {
    return localStorage.getItem(key) ?? defaultValue
  } catch {
    return defaultValue
  }
}

function safeSet(key, value) {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Private-mode Safari, quota exceeded, sandboxed iframe — ignore
  }
}

const autoEnrichOnClick      = ref(readBool(KEY_ENRICH_ON_CLICK, true))
const autoEnrichOnImport     = ref(readBool(KEY_ENRICH_ON_IMPORT, true))
const autoFetchMarketRates   = ref(readBool(KEY_AUTO_MARKET, false))
const marketCurrency         = ref(readString(KEY_MARKET_CURRENCY, 'GBP'))
const hiddenCategories       = ref(readStringList(KEY_HIDDEN_CATS))
// Currency allowlist served by the backend (`MarketValueService::SUPPORTED_CURRENCIES`).
// Kept here rather than duplicated per-component so the list can't drift, and
// fetched once per page load (cached for the rest of the session).
const currencyOptions        = ref([])

/** Whether the server settings have been loaded yet. */
let serverLoaded = false
/** Whether the currency allowlist has been fetched yet. */
let currenciesLoaded = false
/** Whether the hidden_categories list has been loaded from /api/v1/me yet. */
let hiddenLoaded = false
// Suppress watcher-driven server writes while we're applying values from
// the server. Without this every page load echoes the just-loaded values
// back to the server.
let suppressPersist = false

async function loadFromServer() {
  if (serverLoaded) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/market'))
    const data = res.data.ocs?.data ?? {}
    suppressPersist = true
    if (data.autoEnrichOnClick !== undefined) autoEnrichOnClick.value = !!data.autoEnrichOnClick
    if (data.autoEnrichOnImport !== undefined) autoEnrichOnImport.value = !!data.autoEnrichOnImport
    if (data.autoFetchMarketRates !== undefined) autoFetchMarketRates.value = !!data.autoFetchMarketRates
    if (data.marketCurrency) marketCurrency.value = data.marketCurrency
    serverLoaded = true
  } catch {
    // Fall back to localStorage values — non-critical
  } finally {
    // Wait one tick so the watchers see the new values then re-enable.
    queueMicrotask(() => { suppressPersist = false })
  }
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

async function loadHiddenCategoriesFromMe() {
  if (hiddenLoaded) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/me'))
    const data = res.data.ocs?.data ?? {}
    if (Array.isArray(data.hiddenCategories)) {
      suppressPersist = true
      hiddenCategories.value = data.hiddenCategories.filter(v => ALL_CATEGORIES.includes(v))
      hiddenLoaded = true
      queueMicrotask(() => { suppressPersist = false })
    }
  } catch {
    // Stay on local value
  }
}

async function loadCurrencies() {
  if (currenciesLoaded) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/currencies'))
    const list = res.data.ocs?.data
    if (Array.isArray(list) && list.length > 0) {
      currencyOptions.value = list
      currenciesLoaded = true
    }
  } catch {
    // Caller falls back to whatever the marketCurrency is — non-critical
  }
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
  }
}
