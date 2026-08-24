/**
 * Format → gradient colour pair, for the artwork placeholder every card and
 * thumbnail falls back to when an item has no cover image.
 *
 * Used across MediaCard/MediaThumb, ItemDetailView, CollectionView,
 * PlaylistDetailView, and the Shared-with-me views.
 */

/**
 * Hand-picked pairs. Music formats are named here because they are the ones a
 * collector sees side by side most often, so their colours are chosen to be
 * distinguishable rather than merely distinct.
 */
export const FORMAT_COLOURS = {
  Vinyl: ['#6b21a8', '#a855f7'],
  CD: ['#1d4ed8', '#60a5fa'],
  SACD: ['#0f766e', '#2dd4bf'],
  Cassette: ['#b45309', '#fbbf24'],
  MiniDisc: ['#0e7490', '#38bdf8'],
}

/** Used when there is no format at all to derive a hue from. */
const UNKNOWN_COLOURS = ['#374151', '#6b7280']

/**
 * Hue derived from the format string, so every format in every category gets
 * its own colour without enumerating the ~90 entries in FORMAT_LIST — and a
 * format a user typed themselves (or one a provider invents) is coloured too.
 * The hash is order-dependent, so "PS4" and "PS5" land far apart.
 *
 * @param {string} format
 * @returns {number} 0–359
 */
function hueFor(format) {
  let h = 0
  for (let i = 0; i < format.length; i++) {
    h = Math.imul(31, h) + format.charCodeAt(i) | 0
  }
  return Math.abs(h) % 360
}

/**
 * @param {string|null|undefined} format - A media item's format.
 * @returns {[string, string]} dark→light gradient stops.
 */
export function formatColours(format) {
  const key = (format ?? '').trim()
  if (!key) return UNKNOWN_COLOURS
  if (FORMAT_COLOURS[key]) return FORMAT_COLOURS[key]
  const hue = hueFor(key)
  // Same lightness/saturation band as the hand-picked pairs above, so a
  // derived colour sits alongside them without standing out.
  return [`hsl(${hue}, 55%, 30%)`, `hsl(${hue}, 65%, 60%)`]
}
