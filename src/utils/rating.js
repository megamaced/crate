// An item's star rating: a whole number of stars from 1 to MAX_RATING, or
// null while the item is unrated. The server stores the same range
// (MediaItemData::MAX_RATING).
import { n, t } from '../l10n.js'

export const MAX_RATING = 5

/**
 * "4 stars": one choice in the rating control, or a group heading.
 *
 * @param {number} stars whole stars
 * @return {string}
 */
export function starsLabel(stars) {
  return n('crate', '%n star', '%n stars', stars)
}

/**
 * The accessible name of a shown rating: "4 out of 5 stars".
 *
 * @param {number} stars whole stars, 1 to MAX_RATING
 * @return {string}
 */
export function ratingSummary(stars) {
  // TRANSLATORS: an item's star rating, read out by a screen reader: "4 out of 5 stars"
  return t('crate', '{stars} out of {max} stars', { stars, max: MAX_RATING })
}
