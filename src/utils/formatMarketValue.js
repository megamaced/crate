/**
 * Format a media item's market value as a localised currency string.
 *
 * The API may serialise a decimal column as a JSON string, so the value is
 * coerced before any numeric formatting.
 *
 * @param {{ marketValue?: number|string, marketValueCurrency?: string }} item
 * @returns {string}
 */
export function formatMarketValue(item) {
  // Distinguish "no value recorded" (null/undefined) from a legitimate 0
  // (e.g. a PriceCharting tier of 0), which should still render as a price.
  if (item.marketValue == null) return ''
  const value = Number(item.marketValue)
  const currency = item.marketValueCurrency ?? 'GBP'
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
    }).format(value)
  } catch {
    return `${currency} ${Number.isFinite(value) ? value.toFixed(2) : item.marketValue}`
  }
}
