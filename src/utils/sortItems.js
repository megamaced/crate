/**
 * Collection sort order, shared by every view that lists media items.
 *
 * Mirrored by comparatorForSort() in crate-android's CollectionGrouping.kt, so
 * the two clients present one collection in the same order. Change both.
 *
 * Each axis sorts on its own key first, then falls through a chain of
 * tiebreaks. Without those, rows that tie on the primary key came out in
 * whatever order the sort happened to leave them: an artist's albums were
 * unordered within the artist, and a year bucket was unordered within the year.
 */

const ARTICLE_RE = /^(the |a |an )\s*/i

/**
 * Drop a leading article for alphabetical purposes — never for display.
 * "The Beatles" files under B.
 */
export function stripArticle(str) {
	return (str ?? '').replace(ARTICLE_RE, '')
}

// -- Sort keys ---------------------------------------------------------------
//
// One accessor per axis so the primary comparator and the tiebreak chain can't
// drift apart. Artist strips its article to match the group headers; title
// deliberately keeps its own, as getGroupKey() does.

function artistKey(item) {
	return stripArticle((item.artist ?? '').trim()).toLowerCase()
}

function titleKey(item) {
	return (item.title ?? '').trim().toLowerCase()
}

function formatKey(item) {
	return (item.format ?? '').trim().toLowerCase()
}

/** Absent and zero years both read as "unknown", as they do in getGroupKey(). */
function yearKey(item) {
	return item.year ? item.year : Number.NEGATIVE_INFINITY
}

function valueKey(item) {
	return item.marketValue ?? Number.NEGATIVE_INFINITY
}

function createdAtKey(item) {
	return item.createdAt ?? ''
}

// Final tiebreak. Every key above can tie — a bulk import stamps one createdAt
// across hundreds of rows, and two pressings of one album share artist, title
// and year — so without this the order of tied rows is left to the sort's
// discretion and can differ between two renders of the same list.
function idKey(item) {
	return item.id ?? 0
}

const KEYS = {
	artist: artistKey,
	title: titleKey,
	year: yearKey,
	format: formatKey,
	marketValue: valueKey,
	createdAt: createdAtKey,
	id: idKey,
}

/**
 * How rows that tie on the primary axis are ordered. Each chain omits its own
 * primary and ends on `id` so the result is a total order.
 *
 * Artist leads with title, because within one artist the album name is what you
 * scan for; every other axis leads with artist then title, so a year, format or
 * value bucket reads as an alphabetical list rather than an arbitrary one.
 */
const TIEBREAKS = {
	artist: ['title', 'year', 'format', 'id'],
	title: ['artist', 'year', 'format', 'id'],
	year: ['artist', 'title', 'format', 'id'],
	createdAt: ['artist', 'title', 'year', 'id'],
	format: ['artist', 'title', 'year', 'id'],
	marketValue: ['artist', 'title', 'year', 'id'],
}

function compareOn(a, b, field) {
	const key = KEYS[field]
	if (!key) return 0
	const av = key(a)
	const bv = key(b)
	if (av < bv) return -1
	if (av > bv) return 1
	return 0
}

/**
 * Compare two items for the given axis and direction.
 *
 * Only the primary axis honours `dir`; the tiebreaks stay ascending. "Year
 * (Newest)" therefore counts years down while keeping each year's contents in
 * A–Z order, which is how you'd read a shelf.
 *
 * @param {object} a first item
 * @param {object} b second item
 * @param {string} field primary axis — a key of KEYS
 * @param {string} dir 'asc' or 'desc'
 * @return {number} negative, zero or positive, per Array#sort
 */
export function compareItems(a, b, field, dir) {
	const primary = compareOn(a, b, field)
	if (primary !== 0) return dir === 'desc' ? -primary : primary

	for (const tiebreak of TIEBREAKS[field] ?? ['id']) {
		const result = compareOn(a, b, tiebreak)
		if (result !== 0) return result
	}
	return 0
}

/**
 * Sort a copy of `items` by the given axis and direction.
 *
 * @param {Array<object>} items items to order
 * @param {string} field primary axis — a key of KEYS
 * @param {string} dir 'asc' or 'desc'
 * @return {Array<object>} a new, ordered array
 */
export function sortItems(items, field, dir) {
	return [...items].sort((a, b) => compareItems(a, b, field, dir))
}
