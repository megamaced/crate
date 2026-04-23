export const CATEGORY_LABELS = {
  music: 'Music',
  film:  'Films',
  book:  'Books',
  game:  'Games',
  comic: 'Comics',
}

const CATEGORY_COUNT_LABELS = {
  music: ['album', 'albums'],
  film:  ['film', 'films'],
  book:  ['book', 'books'],
  game:  ['game', 'games'],
  comic: ['comic', 'comics'],
}

/**
 * Returns a human-readable count label for a playlist, e.g. "2 films", "mixed".
 * @param {number} count  - number of items in the playlist
 * @param {string[]} categories - distinct category keys in the playlist
 */
export function playlistCountLabel(count, categories = []) {
  if (count === 0) return 'empty'
  if (categories.length === 1) {
    const [singular, plural] = CATEGORY_COUNT_LABELS[categories[0]] ?? ['item', 'items']
    return `${count} ${count === 1 ? singular : plural}`
  }
  return 'mixed'
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
      formats: ['CD', 'SACD', 'CD-R', 'SHM-CD', 'HDCD', 'CDV', 'Blu-ray Audio', 'DVD-Audio', 'LaserDisc', 'MiniDisc'],
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

// Flat ordered list per category — used to sort format filter chips
export const FORMAT_LIST = Object.fromEntries(
  Object.entries(FORMAT_GROUPS).map(([cat, groups]) => [
    cat,
    groups.flatMap(g => g.formats),
  ]),
)

export const FIELD_CONFIG = {
  music: { artist: 'Artist',    title: 'Album / Title',        label: 'Label',     barcode: 'Barcode', showBarcode: true  },
  film:  { artist: 'Director',  title: 'Film Title',            label: 'Studio',    barcode: null,      showBarcode: false },
  book:  { artist: 'Author',    title: 'Title',                 label: 'Publisher', barcode: 'ISBN',    showBarcode: true  },
  game:  { artist: 'Developer', title: 'Game Title',            label: 'Publisher', barcode: null,      showBarcode: false },
  comic: { artist: 'Writer',    title: 'Series / Volume Title', label: 'Publisher', barcode: null,      showBarcode: false },
}
