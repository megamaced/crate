/**
 * Module-level singleton so settings state is shared across all components
 * without needing a store.
 */
import { ref, watch } from 'vue'

const KEY_ENRICH_ON_CLICK  = 'crate_auto_enrich_click'
const KEY_ENRICH_ON_IMPORT = 'crate_auto_enrich_import'
const KEY_AUTO_MARKET      = 'crate_auto_fetch_market_rates'
const KEY_MARKET_CURRENCY  = 'crate_market_currency'

function readBool(key, defaultValue) {
  const val = localStorage.getItem(key)
  if (val === null) return defaultValue
  return val === 'true'
}

function readString(key, defaultValue) {
  return localStorage.getItem(key) ?? defaultValue
}

const autoEnrichOnClick      = ref(readBool(KEY_ENRICH_ON_CLICK, true))
const autoEnrichOnImport     = ref(readBool(KEY_ENRICH_ON_IMPORT, true))
const autoFetchMarketRates   = ref(readBool(KEY_AUTO_MARKET, false))
const marketCurrency         = ref(readString(KEY_MARKET_CURRENCY, 'GBP'))

watch(autoEnrichOnClick,    v => localStorage.setItem(KEY_ENRICH_ON_CLICK, String(v)))
watch(autoEnrichOnImport,   v => localStorage.setItem(KEY_ENRICH_ON_IMPORT, String(v)))
watch(autoFetchMarketRates, v => localStorage.setItem(KEY_AUTO_MARKET, String(v)))
watch(marketCurrency,       v => localStorage.setItem(KEY_MARKET_CURRENCY, v))

export function useSettings() {
  return { autoEnrichOnClick, autoEnrichOnImport, autoFetchMarketRates, marketCurrency }
}
