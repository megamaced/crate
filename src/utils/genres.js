/**
 * Genre and decade helpers for the collection filters.
 *
 * Providers hand back genres as one comma-separated string ("Rock, Art Rock"),
 * so anything that filters or displays them has to split first. Matching is
 * case-insensitive; the first casing seen wins for display.
 *
 * Mirrored in crate-android's CollectionFilters.kt so both clients bucket
 * identically.
 */

/**
 * Split an item's genres into trimmed tokens. Tolerates a JSON array, which
 * older enriched rows could carry.
 *
 * @param {object} item media item
 * @return {string[]} genre names, empty when the item has none
 */
export function genreTokens(item) {
  const raw = item?.genres
  if (!raw) return []
  if (Array.isArray(raw)) return raw.map(g => String(g).trim()).filter(Boolean)
  if (typeof raw !== 'string') return []
  const trimmed = raw.trim()
  if (trimmed.startsWith('[')) {
    try {
      const parsed = JSON.parse(trimmed)
      if (Array.isArray(parsed)) return parsed.map(g => String(g).trim()).filter(Boolean)
    } catch {
      // fall through to comma splitting
    }
  }
  return trimmed.split(',').map(g => g.trim()).filter(Boolean)
}

/**
 * True when the item carries [genre] (case-insensitive).
 *
 * @param {object} item media item
 * @param {string} genre genre name to match
 * @return {boolean} whether the item is in that genre
 */
export function hasGenre(item, genre) {
  const needle = genre.trim().toLowerCase()
  if (!needle) return true
  return genreTokens(item).some(g => g.toLowerCase() === needle)
}

/**
 * The decade label an item files under, e.g. 1997 -> "1990s". Null when the
 * item has no usable year.
 *
 * @param {object} item media item
 * @return {string|null} decade label
 */
export function decadeOf(item) {
  const year = item?.year
  if (!year) return null
  return `${Math.floor(year / 10) * 10}s`
}

/**
 * Distinct genres across [items] with their counts, alphabetical.
 *
 * @param {object[]} items media items
 * @return {{value: string, count: number}[]} buckets
 */
export function genreBuckets(items) {
  const counts = new Map()
  for (const item of items) {
    // One item can carry a genre twice ("Rock, rock"); count it once.
    const seen = new Set()
    for (const genre of genreTokens(item)) {
      const key = genre.toLowerCase()
      if (seen.has(key)) continue
      seen.add(key)
      const existing = counts.get(key)
      if (existing) existing.count += 1
      else counts.set(key, { value: genre, count: 1 })
    }
  }
  return [...counts.values()].sort((a, b) => a.value.localeCompare(b.value))
}

/**
 * Distinct decades across [items] with their counts, oldest first.
 *
 * @param {object[]} items media items
 * @return {{value: string, count: number}[]} buckets
 */
export function decadeBuckets(items) {
  const counts = new Map()
  for (const item of items) {
    const decade = decadeOf(item)
    if (!decade) continue
    counts.set(decade, (counts.get(decade) ?? 0) + 1)
  }
  return [...counts.entries()]
    .map(([value, count]) => ({ value, count }))
    .sort((a, b) => parseInt(a.value, 10) - parseInt(b.value, 10))
}
