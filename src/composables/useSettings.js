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

/** Whether the server settings have been loaded yet. */
let serverLoaded = false

async function loadFromServer() {
  if (serverLoaded) return
  try {
    const res = await axios.get(generateOcsUrl('/apps/crate/api/v1/settings/market'))
    const data = res.data.ocs?.data ?? {}
    if (data.autoEnrichOnClick !== undefined) autoEnrichOnClick.value = !!data.autoEnrichOnClick
    if (data.autoEnrichOnImport !== undefined) autoEnrichOnImport.value = !!data.autoEnrichOnImport
    if (data.autoFetchMarketRates !== undefined) autoFetchMarketRates.value = !!data.autoFetchMarketRates
    if (data.marketCurrency) marketCurrency.value = data.marketCurrency
    serverLoaded = true
  } catch {
    // Fall back to localStorage values — non-critical
  }
}

let saveTimer = null
function persistToServer() {
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

export function useSettings() {
  loadFromServer()
  return { autoEnrichOnClick, autoEnrichOnImport, autoFetchMarketRates, marketCurrency }
}
