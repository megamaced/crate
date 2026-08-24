/**
 * Composable that returns a computed artwork background style for a media item.
 *
 * Handles both local/remote artwork URLs and format-coloured gradient fallbacks.
 */
import { computed } from 'vue'
import { formatColours } from '../utils/formatColours.js'
import { artworkUrl, cssUrl } from '../utils/artworkUrl.js'

function styleForUrl(url) {
  return {
    backgroundImage: cssUrl(url),
    backgroundSize: 'cover',
    backgroundPosition: 'center',
  }
}

function gradientFor(format) {
  const colours = formatColours(format)
  return { background: `linear-gradient(135deg, ${colours[0]}, ${colours[1]})` }
}

function styleForItem(item) {
  if (item?.artworkPath) return styleForUrl(artworkUrl(item))
  return gradientFor(item?.format)
}

/**
 * @param {import('vue').Ref<Object>|import('vue').ComputedRef<Object>} itemRef
 *   Reactive reference to a media item (must have id, artworkPath, updatedAt, format).
 * @returns {import('vue').ComputedRef<Object>} CSS style object for background display.
 */
export function useArtworkStyle(itemRef) {
  return computed(() => {
    const item = itemRef.value
    return item ? styleForItem(item) : {}
  })
}

/**
 * Non-reactive helper for use in plain functions (e.g. thumbStyle in lists).
 *
 * @param {Object} item - A media item object.
 * @returns {Object} CSS style object.
 */
export function artworkStyleFor(item) {
  return styleForItem(item)
}

/**
 * Artwork style for a cover known only by media-item id.
 *
 * The playlist endpoints expose covers as bare media-item ids (`coverId`,
 * `coverIds`) with no `updatedAt` alongside them, so these URLs carry no
 * cache-buster and a replaced cover can show stale bytes until the HTTP cache
 * expires. Pass the owning item to artworkStyleFor() wherever one is to hand.
 *
 * @param {number|null|undefined} mediaItemId
 * @returns {Object} CSS style object.
 */
export function artworkStyleForId(mediaItemId) {
  if (!mediaItemId) return gradientFor(null)
  return styleForUrl(artworkUrl({ id: mediaItemId }))
}
