/**
 * One descriptor per external metadata provider, keyed by the Crate category
 * it serves.
 *
 * EnrichmentSearch.vue is driven entirely by these, so a provider differs from
 * its siblings only in the data below — placeholder wording, the credential it
 * needs, its two endpoints, how a detail response merges into a search result,
 * and which fields its result line prints.
 *
 * The wording (`placeholder`, `credentialHint`) is a function, so it is
 * translated when it is shown rather than when this module loads.
 */
import { generateOcsUrl } from '@nextcloud/router'
import { t } from '../l10n.js'
import { formatLabel } from './categoryFormats.js'

/** Search results are already complete — there is no detail endpoint to follow. */
const NO_DETAIL = null

/** A detail response supersedes the search row it came from. */
const replaceWithDetail = (result, detail) => detail

export const ENRICHMENT_PROVIDERS = {
  music: {
    id: 'discogs',
    label: 'Discogs',
    placeholder: () => t('crate', 'Search Discogs (artist, album, barcode…)'),
    // Discogs is the one provider that indexes barcodes, so a query that looks
    // like one is routed to the barcode endpoint instead of the text search.
    searchParams: (q) => (/^\d{8,14}$/.test(q) ? { barcode: q } : { q }),
    credentialHint: () => t('crate', 'No Discogs token saved — add one in Settings to enable search and enrichment.'),
    searchPath: () => generateOcsUrl('/apps/crate/api/v1/discogs/search'),
    detailPath: NO_DETAIL,
    idKey: 'discogsId',
    thumbShape: 'square',
    showArtist: true,
    metaFields: ['format', 'year', 'label'],
  },
  film: {
    id: 'tmdb',
    label: 'TMDB',
    placeholder: () => t('crate', 'Search TMDB (film title…)'),
    credentialHint: () => t('crate', 'No TMDB token saved — add one in Settings to enable film search.'),
    searchPath: () => generateOcsUrl('/apps/crate/api/v1/tmdb/search'),
    detailPath: (result) => generateOcsUrl(`/apps/crate/api/v1/tmdb/movie/${result.tmdbId}`),
    mergeDetail: replaceWithDetail,
    idKey: 'tmdbId',
    thumbShape: 'portrait',
    showArtist: false,
    metaFields: ['year'],
  },
  book: {
    id: 'openlibrary',
    label: 'Open Library',
    placeholder: () => t('crate', 'Search Open Library (title, author…)'),
    // Open Library is the only open provider — no key, so no hint to show.
    credentialHint: null,
    searchPath: () => generateOcsUrl('/apps/crate/api/v1/openlibrary/search'),
    // The work key arrives as a path ("/works/OL123W"); the route takes the bare id.
    detailPath: (result) => generateOcsUrl(
      `/apps/crate/api/v1/openlibrary/work/${encodeURIComponent(result.workKey.replace(/^\/works\//, ''))}`,
    ),
    // A work response carries description/cover/author bio but not the search
    // row's edition fields, so the two are merged rather than swapped. A row
    // showing an edition in the user's language keeps that edition's cover,
    // which matches its title, over the work's.
    mergeDetail: (result, detail) => ({
      ...result,
      ...detail,
      ...(result.coverFromEdition ? { artworkUrl: result.artworkUrl, thumb: result.thumb } : {}),
    }),
    idKey: 'workKey',
    thumbShape: 'portrait',
    showArtist: true,
    metaFields: ['year', 'label'],
  },
  game: {
    id: 'rawg',
    label: 'RAWG',
    placeholder: () => t('crate', 'Search RAWG (game title…)'),
    credentialHint: () => t('crate', 'No RAWG API key saved — add one in Settings to enable game search.'),
    searchPath: () => generateOcsUrl('/apps/crate/api/v1/rawg/search'),
    detailPath: (result) => generateOcsUrl(`/apps/crate/api/v1/rawg/game/${result.rawgId}`),
    mergeDetail: replaceWithDetail,
    idKey: 'rawgId',
    thumbShape: 'portrait',
    showArtist: false,
    metaFields: ['year', 'genres'],
  },
  comic: {
    id: 'comicvine',
    label: 'ComicVine',
    placeholder: () => t('crate', 'Search ComicVine (series title…)'),
    credentialHint: () => t('crate', 'No ComicVine API key saved — add one in Settings to enable comic search.'),
    searchPath: () => generateOcsUrl('/apps/crate/api/v1/comicvine/search'),
    detailPath: (result) => generateOcsUrl(`/apps/crate/api/v1/comicvine/volume/${result.comicVineId}`),
    mergeDetail: replaceWithDetail,
    idKey: 'comicVineId',
    thumbShape: 'portrait',
    showArtist: false,
    metaFields: ['label', 'year', 'genres'],
  },
}

/**
 * @param {string} category - Crate category key.
 * @returns {Object} The provider for that category, falling back to music.
 */
export function providerFor(category) {
  return ENRICHMENT_PROVIDERS[category] ?? ENRICHMENT_PROVIDERS.music
}

/**
 * The line under a search result's title: the provider's `metaFields` that the
 * result has, in order. A format shows by its display name (see formatLabel);
 * the result keeps the stored value, which is what selecting it copies.
 *
 * @param {Object} provider - An ENRICHMENT_PROVIDERS entry.
 * @param {Object} result - One search result.
 * @returns {string}
 */
export function resultMetaLine(provider, result) {
  return (provider.metaFields ?? [])
    .map(field => (field === 'format' && result.format ? formatLabel(result.format) : result[field]))
    .filter(Boolean)
    .join(', ')
}

/**
 * Category → the id field a provider's results carry. Read wherever a result
 * has to be tied back to the provider that produced it: the add/edit form's
 * `discogsId` column, and the recommendation rail's row keys.
 */
export const ENRICHMENT_ID_KEY = Object.fromEntries(
  Object.entries(ENRICHMENT_PROVIDERS).map(([cat, p]) => [cat, p.idKey]),
)
