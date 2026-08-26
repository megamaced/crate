/**
 * Sizing for the recommendation rails on the item detail view.
 *
 * A rail lays its tiles out with `repeat(auto-fill, minmax(tile, 1fr))`, so how
 * many suggestions are worth fetching is a function of the rendered width. The
 * grid and the request have to agree on the tile size — otherwise the rail
 * leaves a hole in its last row, or spills a stub row — so both read their
 * numbers from here: the component measures, this works out the tile size and
 * the count, and the rail renders the grid with the tile size it's handed.
 */

/**
 * Tile floor wherever the rail has room for two of them, matching the
 * collection's own card grid so a suggestion reads at the same size as the
 * items it sits between.
 */
export const RAIL_TILE_TARGET = 180

/** Gap between tiles; the rail applies it as the grid's `gap`. */
export const RAIL_GAP = 12

/** Two tiles is the narrowest a row can get before it stops reading as a set. */
const MIN_COLUMNS = 2

/**
 * Fewest tiles worth rendering at all. A phone-width rail fits two per row, and
 * a rail of two reads as an accident rather than a suggestion list, so a narrow
 * rail wraps to complete rows instead of shrinking away.
 */
const MIN_TILES = 4

/** The server rejects a `limit` outside 1..24 (MediaController::recommendations). */
const MAX_TILES = 24

/**
 * Tile size and suggestion count for a rail rendered at [width] pixels.
 *
 * @param {number} width - Rail width in CSS pixels; must be positive.
 * @returns {{tileMin: number, limit: number}} `tileMin` for the grid's
 *   `minmax()`, `limit` for the recommendations request.
 */
export function railLayoutFor(width) {
  // Below ~372px the target tile would leave one per row, so the pair shares
  // the width instead. Continuous either way: no breakpoint to jump at.
  const roomForTwo = Math.floor((width - RAIL_GAP * (MIN_COLUMNS - 1)) / MIN_COLUMNS)
  const tileMin = Math.max(1, Math.min(RAIL_TILE_TARGET, roomForTwo))

  // `auto-fill` fits n tiles when n * tile + (n - 1) * gap <= width, which is
  // this once both sides gain a gap. Mirroring it exactly is what makes the
  // count and the grid agree.
  const columns = Math.max(1, Math.floor((width + RAIL_GAP) / (tileMin + RAIL_GAP)))

  // Full rows only: asking for a count the grid can't finish a row with leaves
  // a stub of one or two tiles hanging off the end.
  const rows = Math.ceil(MIN_TILES / columns)

  return { tileMin, limit: Math.min(columns * rows, MAX_TILES) }
}
