/**
 * Centralised route helpers for Crate's backend endpoints.
 *
 * Previously every component hardcoded `generateOcsUrl('/apps/crate/api/v1/...')`.
 * Keeping them here means a route rename is a one-place change, and new
 * callers can find the full surface area at a glance.
 *
 * Functions intentionally return strings (not Promises) so components retain
 * full control over request shape, headers, and error handling.
 */
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const ocs = (path) => generateOcsUrl('/apps/crate/api/v1' + path)
const nc  = (path) => generateUrl('/apps/crate' + path)

/* ── Media items ──────────────────────────────────────────────────────── */
export const mediaIndex        = () => ocs('/media')
export const mediaShow         = (id) => ocs(`/media/${id}`)
export const mediaCreate       = () => ocs('/media')
export const mediaUpdate       = (id) => ocs(`/media/${id}`)
export const mediaDestroy      = (id) => ocs(`/media/${id}`)
export const mediaDestroyAll   = () => ocs('/media')
export const mediaEnrich       = (id) => ocs(`/media/${id}/enrich`)
export const mediaStripEnrich  = (id) => ocs(`/media/${id}/enrich`)
export const mediaMarketValue  = (id) => ocs(`/media/${id}/market-value`)
export const mediaRefreshAllMv = () => ocs('/market-value/refresh-all')

/* ── Enrichment API proxies ───────────────────────────────────────────── */
export const discogsSearch       = () => ocs('/discogs/search')
export const discogsBarcode      = (barcode) => ocs(`/discogs/barcode/${encodeURIComponent(barcode)}`)
export const discogsRelease      = (id) => ocs(`/discogs/release/${id}`)
export const discogsArtist       = (id) => ocs(`/discogs/artist/${id}`)

export const tmdbSearch          = () => ocs('/tmdb/search')
export const tmdbMovie           = (id) => ocs(`/tmdb/movie/${id}`)

export const openLibrarySearch   = () => ocs('/openlibrary/search')
export const openLibraryWork     = (id) => ocs(`/openlibrary/work/${id}`)
export const openLibraryByIsbn   = (isbn) => ocs(`/openlibrary/isbn/${encodeURIComponent(isbn)}`)

export const rawgSearch          = () => ocs('/rawg/search')
export const rawgGame            = (id) => ocs(`/rawg/game/${id}`)

export const comicVineSearch     = () => ocs('/comicvine/search')
export const comicVineVolume     = (id) => ocs(`/comicvine/volume/${id}`)

/* ── Settings ─────────────────────────────────────────────────────────── */
export const settingsDiscogsToken       = () => ocs('/settings/discogs-token')
export const settingsTmdbToken          = () => ocs('/settings/tmdb-token')
export const settingsRawgKey            = () => ocs('/settings/rawg-key')
export const settingsComicVineKey       = () => ocs('/settings/comicvine-key')
export const settingsPriceChartingToken = () => ocs('/settings/pricecharting-token')
export const settingsMarket             = () => ocs('/settings/market')
export const settingsCurrencies         = () => ocs('/settings/currencies')
export const settingsMe                 = () => ocs('/me')
export const settingsCurrency           = () => ocs('/settings/currency')
export const homeFeed                   = () => ocs('/home')

/* ── Import / Export ──────────────────────────────────────────────────── */
export const importPreview  = () => ocs('/import/preview')
export const importCommit   = () => ocs('/import/commit')
export const exportUrl      = () => nc('/export')

/* ── Playlists ────────────────────────────────────────────────────────── */
export const playlistIndex      = () => ocs('/playlists')
export const playlistShow       = (id) => ocs(`/playlists/${id}`)
export const playlistCreate     = () => ocs('/playlists')
export const playlistUpdate     = (id) => ocs(`/playlists/${id}`)
export const playlistDestroy    = (id) => ocs(`/playlists/${id}`)
export const playlistAddItem    = (id) => ocs(`/playlists/${id}/items`)
export const playlistRemoveItem = (id, mediaItemId) => ocs(`/playlists/${id}/items/${mediaItemId}`)

/* ── Sharing ──────────────────────────────────────────────────────────── */
export const usersSearch          = () => ocs('/users/search')
export const shareAlbum           = (id) => ocs(`/share/album/${id}`)
export const sharesForAlbum       = (id) => ocs(`/share/album/${id}`)
export const sharePlaylist        = (id) => ocs(`/share/playlist/${id}`)
export const sharesForPlaylist    = (id) => ocs(`/share/playlist/${id}`)
export const sharedWithMe         = () => ocs('/share/with-me')
export const unshare              = (id) => ocs(`/share/${id}`)

/* ── Artwork (non-OCS, user frontend) ────────────────────────────────── */
export const artworkGet    = (id) => nc(`/artwork/${id}`)
export const artworkUpload = (id) => nc(`/artwork/${id}`)
export const artworkDelete = (id) => nc(`/artwork/${id}`)

/* ── User-supplied photo slots (1 or 2). Non-OCS, user frontend. ──────── */
export const photoGet      = (id, slot) => nc(`/photo/${id}/${slot}`)
export const photoUpload   = (id, slot) => nc(`/photo/${id}/${slot}`)
export const photoDelete   = (id, slot) => nc(`/photo/${id}/${slot}`)
