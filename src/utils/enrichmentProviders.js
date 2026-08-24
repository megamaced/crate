/**
 * One descriptor per external metadata provider, keyed by the Crate category
 * it serves.
 *
 * EnrichmentSearch.vue is driven entirely by these, so a provider differs from
 * its siblings only in the data below — placeholder wording, the credential it
 * needs, its two endpoints, how a detail response merges into a search result,
 * and which fields its result line prints.
 */
import {
  comicVineSearch, comicVineVolume,
  discogsSearch,
  openLibrarySearch, openLibraryWork,
  rawgSearch, rawgGame,
  tmdbSearch, tmdbMovie,
} from '../api.js'

/** Search results are already complete — there is no detail endpoint to follow. */
const NO_DETAIL = null

/** A detail response supersedes the search row it came from. */
const replaceWithDetail = (result, detail) => detail

export const ENRICHMENT_PROVIDERS = {
  music: {
    id: 'discogs',
    label: 'Discogs',
    placeholder: 'Search Discogs (artist, album, barcode…)',
    // Discogs is the one provider that indexes barcodes, so a query that looks
    // like one is routed to the barcode endpoint instead of the text search.
    searchParams: (q) => (/^\d{8,14}$/.test(q) ? { barcode: q } : { q }),
    credentialHint: 'No Discogs token saved — add one in Settings to enable search and enrichment.',
    searchPath: discogsSearch,
    detailPath: NO_DETAIL,
    idKey: 'discogsId',
    thumbShape: 'square',
    showArtist: true,
    metaFields: ['format', 'year', 'label'],
  },
  film: {
    id: 'tmdb',
    label: 'TMDB',
    placeholder: 'Search TMDB (film title…)',
    credentialHint: 'No TMDB token saved — add one in Settings to enable film search.',
    searchPath: tmdbSearch,
    detailPath: (result) => tmdbMovie(result.tmdbId),
    mergeDetail: replaceWithDetail,
    idKey: 'tmdbId',
    thumbShape: 'portrait',
    showArtist: false,
    metaFields: ['year'],
  },
  book: {
    id: 'openlibrary',
    label: 'Open Library',
    placeholder: 'Search Open Library (title, author…)',
    // Open Library is the only open provider — no key, so no hint to show.
    credentialHint: null,
    searchPath: openLibrarySearch,
    // The work key arrives as a path ("/works/OL123W"); the route takes the bare id.
    detailPath: (result) => openLibraryWork(encodeURIComponent(result.workKey.replace(/^\/works\//, ''))),
    // A work response carries description/cover/author bio but not the search
    // row's edition fields, so the two are merged rather than swapped.
    mergeDetail: (result, detail) => ({ ...result, ...detail }),
    idKey: 'workKey',
    thumbShape: 'portrait',
    showArtist: true,
    metaFields: ['year', 'label'],
  },
  game: {
    id: 'rawg',
    label: 'RAWG',
    placeholder: 'Search RAWG (game title…)',
    credentialHint: 'No RAWG API key saved — add one in Settings to enable game search.',
    searchPath: rawgSearch,
    detailPath: (result) => rawgGame(result.rawgId),
    mergeDetail: replaceWithDetail,
    idKey: 'rawgId',
    thumbShape: 'portrait',
    showArtist: false,
    metaFields: ['year', 'genres'],
  },
  comic: {
    id: 'comicvine',
    label: 'ComicVine',
    placeholder: 'Search ComicVine (series title…)',
    credentialHint: 'No ComicVine API key saved — add one in Settings to enable comic search.',
    searchPath: comicVineSearch,
    detailPath: (result) => comicVineVolume(result.comicVineId),
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
 * Category → the id field a provider's results carry. Read wherever a result
 * has to be tied back to the provider that produced it: the add/edit form's
 * `discogsId` column, and the recommendation rail's row keys.
 */
export const ENRICHMENT_ID_KEY = Object.fromEntries(
  Object.entries(ENRICHMENT_PROVIDERS).map(([cat, p]) => [cat, p.idKey]),
)
