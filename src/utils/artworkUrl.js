/**
 * Build the artwork URL for a media item.
 *
 * Appends the item's `updatedAt` as a cache-busting query string when
 * present so that re-uploaded artwork invalidates cleanly without hammering
 * the cache for unchanged items.
 */
import { generateUrl } from '@nextcloud/router'

/**
 * @param {Object} item - A media item (needs `id` and optionally `updatedAt`).
 * @returns {string} Fully-qualified URL to the artwork endpoint.
 */
export function artworkUrl(item) {
  const v = item.updatedAt ? '?v=' + encodeURIComponent(item.updatedAt) : ''
  return generateUrl('/apps/crate/artwork/' + item.id) + v
}

/**
 * Wrap a URL as a CSS `url()` value.
 *
 * Provider artwork URLs are interpolated straight into inline styles, and an
 * unquoted `url()` cannot carry a parenthesis, whitespace or quote — the whole
 * declaration is discarded and the image silently fails to render. Quoting and
 * escaping keeps any URL a provider hands us usable.
 *
 * @param {string} url - Absolute or app-relative URL.
 * @returns {string} A `url("…")` token safe to assign to a CSS property.
 */
export function cssUrl(url) {
  const escaped = String(url)
    .replace(/[\r\n\f]/g, '')
    .replace(/["\\]/g, '\\$&')
  return `url("${escaped}")`
}
