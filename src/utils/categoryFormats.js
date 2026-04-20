export const CATEGORY_LABELS = {
  music: 'Music',
  film:  'Films',
  book:  'Books',
  game:  'Games',
  comic: 'Comics',
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
      label: 'Current Gen',
      formats: ['PS5', 'Xbox Series X|S', 'Switch 2', 'Switch', 'PC'],
    },
    {
      label: 'PlayStation',
      formats: ['PS4', 'PS3', 'PS2', 'PS1', 'PSP', 'PS Vita'],
    },
    {
      label: 'Xbox',
      formats: ['Xbox One', 'Xbox 360', 'Xbox'],
    },
    {
      label: 'Nintendo',
      formats: ['3DS', 'DS', 'Game Boy Advance', 'Game Boy', 'Wii U', 'Wii', 'GameCube', 'N64', 'SNES', 'NES'],
    },
    {
      label: 'Sega',
      formats: ['Mega Drive', 'Saturn', 'Dreamcast'],
    },
    {
      label: 'Retro',
      formats: ['Atari 2600', 'Commodore 64', 'Amiga', 'Neo Geo', 'Tiger'],
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
