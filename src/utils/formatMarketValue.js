/**
 * Format a media item's market value as a localised currency string.
 *
 * @param {{ marketValue?: number, marketValueCurrency?: string }} item
 * @returns {string}
 */
export function formatMarketValue(item) {
  // Distinguish "no value recorded" (null/undefined) from a legitimate 0
  // (e.g. a PriceCharting tier of 0), which should still render as a price.
  if (item.marketValue == null) return ''
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: item.marketValueCurrency ?? 'GBP',
      minimumFractionDigits: 2,
    }).format(item.marketValue)
  } catch {
    return `${item.marketValueCurrency ?? ''} ${item.marketValue}`
  }
}
