import { n, t } from '../l10n.js'

// Category ids, statuses and format names are data: the API sends and stores
// the English values. Everything below that shows them to the user translates
// at the moment it's shown, so it follows the signed-in user's language.

export const CATEGORY_IDS = ['music', 'film', 'book', 'game', 'comic']

/** A category's name, e.g. "Films" for film; an unknown id as it is. */
export function categoryLabel(category) {
  switch (category) {
    case 'music':
      return t('crate', 'Music')
    case 'film':
      return t('crate', 'Films')
    case 'book':
      return t('crate', 'Books')
    case 'game':
      return t('crate', 'Games')
    case 'comic':
      return t('crate', 'Comics')
    default:
      return category
  }
}

/** "Owned" or "Wanted" for an item's status. */
export function statusLabel(status) {
  return status === 'wanted' ? t('crate', 'Wanted') : t('crate', 'Owned')
}

/**
 * Returns a human-readable count label for a playlist, e.g. "2 films", "mixed".
 * @param {number} count  - number of items in the playlist
 * @param {string[]} categories - distinct category keys in the playlist
 */
export function playlistCountLabel(count, categories = []) {
  if (count === 0) {
    // TRANSLATORS: a playlist with nothing in it
    return t('crate', 'empty')
  }
  if (categories.length !== 1) {
    // TRANSLATORS: a playlist holding items from more than one category
    return t('crate', 'mixed')
  }
  switch (categories[0]) {
    case 'music':
      return n('crate', '%n album', '%n albums', count)
    case 'film':
      return n('crate', '%n film', '%n films', count)
    case 'book':
      return n('crate', '%n book', '%n books', count)
    case 'game':
      return n('crate', '%n game', '%n games', count)
    case 'comic':
      return n('crate', '%n comic', '%n comics', count)
    default:
      return n('crate', '%n item', '%n items', count)
  }
}

export const FORMAT_GROUPS = {
  music: [
    {
      label: 'Vinyl',
      formats: ['Vinyl', '7" Single', '10"', '12" Single', 'Picture Disc', 'Flexi-disc', 'Shellac', 'Lathe Cut'],
    },
    {
      label: 'Tape',
      formats: ['Cassette', '8-Track', 'Reel-to-Reel', 'DAT', 'DCC', '4-Track Cartridge', 'Microcassette'],
    },
    {
      label: 'Disc',
      formats: ['CD', 'SACD', 'CD-R', 'SHM-CD', 'HDCD', 'Blu-ray Audio', 'DVD-Audio', 'MiniDisc'],
    },
  ],
  film: [
    {
      label: 'Physical',
      formats: ['Blu-ray', '4K UHD', '3D Blu-ray', 'DVD', 'HD DVD', 'VHS', 'LaserDisc', 'VCD', 'Betamax'],
    },
  ],
  book: [
    {
      label: 'Print',
      formats: ['Hardcover', 'Paperback', 'Mass Market Paperback', 'Trade Paperback', 'Graphic Novel', 'Comic'],
    },
    {
      label: 'Audio',
      formats: ['Audiobook CD', 'Audiobook Cassette'],
    },
  ],
  game: [
    {
      label: 'Sony',
      formats: ['PS5', 'PS4', 'PS3', 'PS2', 'PS1', 'PS Vita', 'PSP'],
    },
    {
      label: 'Microsoft',
      formats: ['Xbox Series X|S', 'Xbox One', 'Xbox 360', 'Xbox'],
    },
    {
      label: 'Nintendo',
      formats: ['Switch 2', 'Switch', 'Wii U', 'Wii', 'GameCube', 'N64', 'SNES', 'NES', '3DS', 'DS', 'Game Boy Advance', 'Game Boy Color', 'Game Boy', 'Virtual Boy'],
    },
    {
      label: 'Sega',
      // "Mega Drive / Genesis" keeps both regional names visible — our UK
      // users know it as Mega Drive, but both RAWG and PriceCharting key on
      // "Genesis", so the combined label matches a user-typed CSV either way.
      formats: ['Dreamcast', 'Saturn', 'Mega Drive / Genesis', 'Master System', 'Game Gear', 'Sega CD', 'Sega 32X'],
    },
    {
      label: 'Atari',
      formats: ['Atari 2600', 'Atari 5200', 'Atari 7800', 'Atari Lynx', 'Jaguar'],
    },
    {
      label: 'SNK',
      // PriceCharting splits the Neo Geo line into four distinct SKUs;
      // RAWG lumps them under a single "Neo Geo" but PriceCharting drives
      // the split (since market values come from there).
      formats: ['Neo Geo MVS', 'Neo Geo AES', 'Neo Geo CD', 'Neo Geo Pocket Color'],
    },
  ],
  comic: [
    {
      label: 'Single Issues',
      formats: ['Single Issue', 'Annual', 'Special', 'One-Shot', 'Mini-Series', 'Limited Series'],
    },
    {
      label: 'Collected',
      formats: ['Trade Paperback', 'Hardcover', 'Omnibus', 'Graphic Novel', 'Compendium'],
    },
  ],
}

/**
 * A format group's name. The generic ones translate; a maker's name (Sony,
 * Nintendo…) is a name and stays as it is.
 */
export function formatGroupLabel(group) {
  switch (group) {
    case 'Vinyl':
      return t('crate', 'Vinyl')
    case 'Tape':
      return t('crate', 'Tape')
    case 'Disc':
      return t('crate', 'Disc')
    case 'Physical':
      // TRANSLATORS: physical film formats, as opposed to digital ones
      return t('crate', 'Physical')
    case 'Print':
      return t('crate', 'Print')
    case 'Audio':
      return t('crate', 'Audio')
    case 'Single Issues':
      return t('crate', 'Single Issues')
    case 'Collected':
      // TRANSLATORS: comics collected into one volume (trade paperbacks, omnibuses…)
      return t('crate', 'Collected')
    default:
      return group
  }
}

/**
 * A format's display name. The value stored and sent to the API is always the
 * English name; only formats named with ordinary words translate. Platform,
 * model and disc-standard names (PS5, VHS, SACD…) are product names, and a
 * custom format is the user's own text, so those show as they are.
 */
export function formatLabel(format) {
  switch (format) {
    case 'Vinyl':
      return t('crate', 'Vinyl')
    case '7" Single':
      return t('crate', '7" Single')
    case '12" Single':
      return t('crate', '12" Single')
    case 'Picture Disc':
      return t('crate', 'Picture Disc')
    case 'Flexi-disc':
      return t('crate', 'Flexi-disc')
    case 'Shellac':
      return t('crate', 'Shellac')
    case 'Lathe Cut':
      return t('crate', 'Lathe Cut')
    case 'Cassette':
      return t('crate', 'Cassette')
    case 'Reel-to-Reel':
      return t('crate', 'Reel-to-Reel')
    case '4-Track Cartridge':
      return t('crate', '4-Track Cartridge')
    case 'Microcassette':
      return t('crate', 'Microcassette')
    case 'Hardcover':
      return t('crate', 'Hardcover')
    case 'Paperback':
      return t('crate', 'Paperback')
    case 'Mass Market Paperback':
      return t('crate', 'Mass Market Paperback')
    case 'Trade Paperback':
      return t('crate', 'Trade Paperback')
    case 'Graphic Novel':
      return t('crate', 'Graphic Novel')
    case 'Comic':
      return t('crate', 'Comic')
    case 'Audiobook CD':
      return t('crate', 'Audiobook CD')
    case 'Audiobook Cassette':
      return t('crate', 'Audiobook Cassette')
    case 'Single Issue':
      return t('crate', 'Single Issue')
    case 'Annual':
      // TRANSLATORS: a comic published once a year alongside the regular series
      return t('crate', 'Annual')
    case 'Special':
      // TRANSLATORS: a one-off special comic issue
      return t('crate', 'Special')
    case 'One-Shot':
      return t('crate', 'One-Shot')
    case 'Mini-Series':
      return t('crate', 'Mini-Series')
    case 'Limited Series':
      return t('crate', 'Limited Series')
    case 'Omnibus':
      return t('crate', 'Omnibus')
    case 'Compendium':
      return t('crate', 'Compendium')
    default:
      return format
  }
}

// Flat ordered list per category — used to sort format filter chips
export const FORMAT_LIST = Object.fromEntries(
  Object.entries(FORMAT_GROUPS).map(([cat, groups]) => [
    cat,
    groups.flatMap(g => g.formats),
  ]),
)

// The column names CSV import recognises for each field, in English: the
// server matches headers against these, so they are data and never translated.
// The names shown on forms come from fieldLabels().
export const FIELD_CONFIG = {
  music: { artist: 'Artist',    title: 'Album / Title',        label: 'Label',     barcode: 'Barcode' },
  film:  { artist: 'Director',  title: 'Film Title',            label: 'Studio',    barcode: 'Barcode' },
  book:  { artist: 'Author',    title: 'Title',                 label: 'Publisher', barcode: 'ISBN'    },
  game:  { artist: 'Developer', title: 'Game Title',            label: 'Publisher', barcode: 'Barcode' },
  comic: { artist: 'Writer',    title: 'Series / Volume Title', label: 'Publisher', barcode: 'Barcode' },
}

/** The names a category's form fields show, e.g. "Director" for a film's artist. */
export function fieldLabels(category) {
  switch (category) {
    case 'film':
      return {
        artist: t('crate', 'Director'),
        title: t('crate', 'Film Title'),
        label: t('crate', 'Studio'),
        barcode: t('crate', 'Barcode'),
      }
    case 'book':
      return {
        artist: t('crate', 'Author'),
        title: t('crate', 'Title'),
        label: t('crate', 'Publisher'),
        barcode: t('crate', 'ISBN'),
      }
    case 'game':
      return {
        artist: t('crate', 'Developer'),
        title: t('crate', 'Game Title'),
        label: t('crate', 'Publisher'),
        barcode: t('crate', 'Barcode'),
      }
    case 'comic':
      return {
        artist: t('crate', 'Writer'),
        title: t('crate', 'Series / Volume Title'),
        label: t('crate', 'Publisher'),
        barcode: t('crate', 'Barcode'),
      }
    default:
      return {
        artist: t('crate', 'Artist'),
        title: t('crate', 'Album / Title'),
        // TRANSLATORS: a record label, the company that released the album
        label: t('crate', 'Label'),
        barcode: t('crate', 'Barcode'),
      }
  }
}
